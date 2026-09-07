<?php
/**
 * phpBB Migration Center Extension
 *
 * @copyright (c) 2026 phpBB SEO Team
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace phpbbseo\migrationcenter\source\smf\step;

use phpbbseo\migrationcenter\core\contract\step_interface;
use phpbbseo\migrationcenter\core\contract\source_provider_interface;
use phpbbseo\migrationcenter\core\contract\target_writer_interface;
use phpbbseo\migrationcenter\core\dto\conversation_dto;
use phpbbseo\migrationcenter\core\dto\conversation_recipient_dto;
use phpbbseo\migrationcenter\core\dto\migration_config_dto;
use phpbbseo\migrationcenter\core\dto\step_result_dto;
use phpbbseo\migrationcenter\source\smf\adapter\smf_db_adapter;

/**
 * SMF 2.x Private Conversations Migration Step
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
		$db = new smf_db_adapter($config);

		$cursor_id = (int)$cursor;
		$tbl_pm = $db->get_table_name('personal_messages');
		$tbl_recip = $db->get_table_name('pm_recipients');

		if (!$db->table_exists('personal_messages'))
		{
			$result->is_completed = true;
			return $result;
		}

		$sql = "SELECT id_pm, id_member_from, deleted_by_sender, msgtime, subject
				FROM {$tbl_pm}
				WHERE id_pm > {$cursor_id}
				ORDER BY id_pm ASC
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

		// Fetch all recipients for the batch in one query
		$pm_ids = array_map(function ($r) {
			return (int)$r['id_pm'];
		}, $rows);

		$recipients_by_pm = [];
		if (!empty($pm_ids) && $db->table_exists('pm_recipients'))
		{
			$in_ids = implode(',', $pm_ids);
			$recip_rows = $db->fetch_all("SELECT id_pm, id_member, is_read, deleted FROM {$tbl_recip} WHERE id_pm IN ({$in_ids})");
			foreach ($recip_rows as $rcp)
			{
				$pm_id = (int)$rcp['id_pm'];
				if (!isset($recipients_by_pm[$pm_id]))
				{
					$recipients_by_pm[$pm_id] = [];
				}
				$recipients_by_pm[$pm_id][] = $rcp;
			}
		}

		$conversations = [];
		$max_cursor = $cursor_id;

		foreach ($rows as $row)
		{
			$pmid = (int)$row['id_pm'];
			if ($pmid > $max_cursor)
			{
				$max_cursor = $pmid;
			}

			$from_uid = (int)($row['id_member_from'] ?? 0);
			$msg_time = (int)($row['msgtime'] ?? time());

			$dto = new conversation_dto();
			$dto->source_id = $pmid;
			$dto->title = trim((string)($row['subject'] ?? 'Private Message'));
			if ($dto->title === '')
			{
				$dto->title = 'Private Message';
			}
			$dto->user_source_id = $from_uid;
			$dto->start_date = $msg_time;
			$dto->first_message_id = $pmid;
			$dto->last_message_id = $pmid;
			$dto->last_message_date = $msg_time;

			$recipients = [];

			// Sender
			$sender_r = new conversation_recipient_dto();
			$sender_r->user_source_id = $from_uid;
			$sender_r->recipient_state = !empty($row['deleted_by_sender']) ? 'deleted' : 'active';
			$sender_r->last_read_date = $msg_time;
			$sender_r->is_unread = false;
			$sender_r->join_date = $msg_time;
			$recipients[$from_uid] = $sender_r;

			// Target Recipients
			if (isset($recipients_by_pm[$pmid]))
			{
				foreach ($recipients_by_pm[$pmid] as $recip)
				{
					$to_uid = (int)$recip['id_member'];
					if ($to_uid <= 0)
					{
						continue;
					}

					$is_read = !empty($recip['is_read']);
					$to_r = new conversation_recipient_dto();
					$to_r->user_source_id = $to_uid;
					$to_r->recipient_state = !empty($recip['deleted']) ? 'deleted' : 'active';
					$to_r->last_read_date = $is_read ? $msg_time : 0;
					$to_r->is_unread = !$is_read;
					$to_r->join_date = $msg_time;
					$recipients[$to_uid] = $to_r;
				}
			}

			$dto->recipients = array_values($recipients);
			$dto->recipient_count = count($dto->recipients);

			$conversations[] = $dto;
		}

		$writer_res = $writer->write_conversations($conversations, [
			'run_id'        => $run_id,
			'source_system' => $config->source_system ?: 'smf',
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
