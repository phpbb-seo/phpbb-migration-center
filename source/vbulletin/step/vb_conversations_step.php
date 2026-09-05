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
use phpbbseo\migrationcenter\core\dto\conversation_dto;
use phpbbseo\migrationcenter\core\dto\conversation_recipient_dto;
use phpbbseo\migrationcenter\core\dto\migration_config_dto;
use phpbbseo\migrationcenter\core\dto\step_result_dto;
use phpbbseo\migrationcenter\source\vbulletin\adapter\vb_db_adapter;

/**
 * vBulletin Private Messages Conversations Step
 */
class vb_conversations_step implements step_interface
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
		$db = new vb_db_adapter($config);

		$cursor_id = (int)$cursor;
		$tbl_pmtext = $db->get_table_name('pmtext');
		$tbl_pm = $db->get_table_name('pm');

		$sql = "SELECT pmtextid, fromuserid, fromusername, title, message, touserarray, dateline
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

		$conversations = [];
		$max_cursor = $cursor_id;

		foreach ($rows as $row)
		{
			$pmtextid = (int)$row['pmtextid'];
			if ($pmtextid > $max_cursor)
			{
				$max_cursor = $pmtextid;
			}

			$dto = new conversation_dto();
			$dto->source_id = $pmtextid;
			$dto->title = trim((string)($row['title'] ?? 'Private Message'));
			if ($dto->title === '')
			{
				$dto->title = 'Private Message';
			}
			$dto->user_source_id = (int)($row['fromuserid'] ?? 0);
			$dto->username = (string)($row['fromusername'] ?? '');
			$dto->start_date = (int)($row['dateline'] ?? time());
			$dto->first_message_id = $pmtextid;
			$dto->last_message_id = $pmtextid;
			$dto->last_message_date = $dto->start_date;

			// Fetch delivery rows from pm table
			$pm_sql = "SELECT pmid, userid, folderid, messageread FROM {$tbl_pm} WHERE pmtextid = {$pmtextid}";
			$pm_rows = $db->fetch_all($pm_sql);

			$recipients = [];
			$to_user_ids = [];

			// Unserialize touserarray if present
			if (!empty($row['touserarray']))
			{
				$unserialized = @unserialize($row['touserarray']);
				if (is_array($unserialized))
				{
					foreach ($unserialized as $t_uid => $t_uname)
					{
						$to_user_ids[(int)$t_uid] = true;
					}
				}
			}

			foreach ($pm_rows as $pm_r)
			{
				$p_uid = (int)$pm_r['userid'];
				$folder = (int)$pm_r['folderid'];
				$read = !empty($pm_r['messageread']);

				$r_dto = new conversation_recipient_dto();
				$r_dto->user_source_id = $p_uid;
				$r_dto->recipient_state = 'active';
				$r_dto->last_read_date = $read ? $dto->start_date : 0;
				$r_dto->is_unread = !$read;
				$r_dto->join_date = $dto->start_date;

				$recipients[$p_uid] = $r_dto;
				$to_user_ids[$p_uid] = true;
			}

			// Ensure sender is included in recipients map for state tracking
			if (!isset($recipients[$dto->user_source_id]))
			{
				$sender_r = new conversation_recipient_dto();
				$sender_r->user_source_id = $dto->user_source_id;
				$sender_r->recipient_state = 'active';
				$sender_r->last_read_date = $dto->start_date;
				$sender_r->is_unread = false;
				$sender_r->join_date = $dto->start_date;
				$recipients[$dto->user_source_id] = $sender_r;
			}

			// Ensure all to_users are in recipients map
			foreach (array_keys($to_user_ids) as $t_uid)
			{
				if (!isset($recipients[$t_uid]))
				{
					$to_r = new conversation_recipient_dto();
					$to_r->user_source_id = $t_uid;
					$to_r->recipient_state = 'active';
					$to_r->last_read_date = $dto->start_date;
					$to_r->is_unread = false;
					$to_r->join_date = $dto->start_date;
					$recipients[$t_uid] = $to_r;
				}
			}

			$dto->recipients = array_values($recipients);
			$dto->recipient_count = count($dto->recipients);
			$conversations[] = $dto;
		}

		$writer_res = $writer->write_conversations($conversations, [
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
