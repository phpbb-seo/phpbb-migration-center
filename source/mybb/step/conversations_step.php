<?php
/**
 * phpBB Migration Center Extension
 *
 * @copyright (c) 2026 phpBB SEO Team
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace phpbbseo\migrationcenter\source\mybb\step;

use phpbbseo\migrationcenter\core\contract\step_interface;
use phpbbseo\migrationcenter\core\contract\source_provider_interface;
use phpbbseo\migrationcenter\core\contract\target_writer_interface;
use phpbbseo\migrationcenter\core\dto\conversation_dto;
use phpbbseo\migrationcenter\core\dto\conversation_recipient_dto;
use phpbbseo\migrationcenter\core\dto\migration_config_dto;
use phpbbseo\migrationcenter\core\dto\step_result_dto;
use phpbbseo\migrationcenter\source\mybb\adapter\mybb_db_adapter;

/**
 * MyBB Private Conversations Migration Step
 */
class conversations_step implements step_interface
{
	public function get_name(): string
	{
		return 'conversations';
	}

	public function get_label(): string
	{
		return 'Conversations';
	}

	public function get_dependencies(): array
	{
		return ['users'];
	}

	public function process_batch(
		string $run_id,
		$cursor,
		int $batch_size,
		migration_config_dto $config,
		source_provider_interface $provider,
		target_writer_interface $writer
	): step_result_dto {
		$result = new step_result_dto('conversations');
		$db = new mybb_db_adapter($config);

		$cursor_id = (int)$cursor;
		$tbl_pm = $db->get_table_name('privatemessages');

		if (!$db->table_exists('privatemessages'))
		{
			$result->is_completed = true;
			return $result;
		}

		// In MyBB, folder 1 is inbox (received messages)
		$sql = "SELECT pmid, uid, toid, fromid, subject, dateline, status
				FROM {$tbl_pm}
				WHERE pmid > {$cursor_id} AND folder = 1
				ORDER BY pmid ASC
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

		$conversations = [];
		$max_cursor = $cursor_id;

		foreach ($rows as $row)
		{
			$pmid = (int)$row['pmid'];
			if ($pmid > $max_cursor)
			{
				$max_cursor = $pmid;
			}

			$dto = new conversation_dto();
			$dto->source_id = $pmid;
			$dto->title = trim((string)($row['subject'] ?? 'Private Message'));
			if ($dto->title === '')
			{
				$dto->title = 'Private Message';
			}
			$dto->user_source_id = (int)($row['fromid'] ?? 0);
			$dto->start_date = (int)($row['dateline'] ?? time());
			$dto->first_message_id = $pmid;
			$dto->last_message_id = $pmid;
			$dto->last_message_date = $dto->start_date;

			$to_uid = (int)($row['toid'] ?? 0);
			$from_uid = (int)($row['fromid'] ?? 0);
			$read = ((int)($row['status'] ?? 0)) > 0;

			$recipients = [];

			// Sender
			$sender_r = new conversation_recipient_dto();
			$sender_r->user_source_id = $from_uid;
			$sender_r->recipient_state = 'active';
			$sender_r->last_read_date = $dto->start_date;
			$sender_r->is_unread = false;
			$sender_r->join_date = $dto->start_date;
			$recipients[$from_uid] = $sender_r;

			// Recipient
			if ($to_uid > 0)
			{
				$to_r = new conversation_recipient_dto();
				$to_r->user_source_id = $to_uid;
				$to_r->recipient_state = 'active';
				$to_r->last_read_date = $read ? $dto->start_date : 0;
				$to_r->is_unread = !$read;
				$to_r->join_date = $dto->start_date;
				$recipients[$to_uid] = $to_r;
			}

			$dto->recipients = array_values($recipients);
			$dto->recipient_count = count($dto->recipients);

			$conversations[] = $dto;
		}

		$writer_res = $writer->write_conversations($conversations, [
			'run_id'        => $run_id,
			'source_system' => $config->source_system ?: 'mybb',
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
