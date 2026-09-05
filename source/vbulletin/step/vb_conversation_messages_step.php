<?php
/**
 * phpBB Migration Center Extension
 *
 * @copyright (c) 2026 phpBB SEO Team
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace phpbbseo\migrationcenter\source\vbulletin\step;

use phpbbseo\migrationcenter\core\contract\step_interface;
use phpbbseo\migrationcenter\core\contract\source_provider_interface;
use phpbbseo\migrationcenter\core\contract\target_writer_interface;
use phpbbseo\migrationcenter\core\dto\conversation_message_dto;
use phpbbseo\migrationcenter\core\dto\migration_config_dto;
use phpbbseo\migrationcenter\core\dto\step_result_dto;
use phpbbseo\migrationcenter\source\vbulletin\adapter\vb_db_adapter;

/**
 * vBulletin Private Messages Delivery & Content Step
 */
class vb_conversation_messages_step implements step_interface
{
	public function get_name(): string
	{
		return 'conversation_messages';
	}

	public function get_label(): string
	{
		return 'Private Messages';
	}

	public function get_dependencies(): array
	{
		return ['conversations', 'users'];
	}

	public function process_batch(
		string $run_id,
		$cursor,
		int $batch_size,
		migration_config_dto $config,
		source_provider_interface $provider,
		target_writer_interface $writer
	): step_result_dto {
		$result = new step_result_dto('conversation_messages');
		$db = new vb_db_adapter($config);

		$cursor_id = (int)$cursor;
		$tbl_pmtext = $db->get_table_name('pmtext');

		$sql = "SELECT pmtextid, fromuserid, fromusername, title, message, dateline
				FROM {$tbl_pmtext}
				WHERE pmtextid > {$cursor_id}
				ORDER BY pmtextid ASC
				LIMIT {$batch_size}";

		$rows = $db->fetch_all($sql);
		$result->read_count = count($rows);

		if (empty($rows))
		{
			$result->next_cursor = (string)$cursor_id;
			$result->current_cursor = (string)$cursor_id;
			$result->is_completed = true;
			return $result;
		}

		$messages = [];
		$max_cursor = $cursor_id;

		foreach ($rows as $row)
		{
			$pmtextid = (int)$row['pmtextid'];
			if ($pmtextid > $max_cursor)
			{
				$max_cursor = $pmtextid;
			}

			$dto = new conversation_message_dto();
			$dto->source_id = $pmtextid;
			$dto->conversation_source_id = $pmtextid;
			$dto->user_source_id = (int)($row['fromuserid'] ?? 0);
			$dto->username = (string)($row['fromusername'] ?? '');
			$dto->message_date = (int)($row['dateline'] ?? time());
			$dto->message_text = (string)($row['message'] ?? '');
			$dto->author_ip = '127.0.0.1';

			$messages[] = $dto;
		}

		$writer_res = $writer->write_privmsgs($messages, [
			'run_id'        => $run_id,
			'source_system' => $config->source_system ?: 'vbulletin',
		]);

		$created = 0;
		$skipped = 0;
		$failed  = 0;

		foreach ($writer_res as $res)
		{
			if (($res['status'] ?? '') === 'success')
			{
				$created++;
			}
			else if (($res['status'] ?? '') === 'skipped')
			{
				$skipped++;
			}
			else
			{
				$failed++;
			}
		}

		$result->imported_count = $created;
		$result->skipped_count = $skipped;
		$result->failed_count = $failed;
		$result->metrics = [
			'created' => $created,
			'reused'  => 0,
			'updated' => 0,
			'skipped' => $skipped,
			'failed'  => $failed,
		];
		$result->next_cursor = (string)$max_cursor;
		$result->current_cursor = (string)$max_cursor;

		$max_total_id = (int)$provider->get_max_source_id('conversations', $config);
		if ($max_cursor >= $max_total_id || count($rows) < $batch_size)
		{
			$result->is_completed = true;
		}

		return $result;
	}
}
