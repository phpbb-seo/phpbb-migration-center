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
use phpbbseo\migrationcenter\core\dto\attachment_dto;
use phpbbseo\migrationcenter\core\dto\migration_config_dto;
use phpbbseo\migrationcenter\core\dto\step_result_dto;
use phpbbseo\migrationcenter\source\vbulletin\adapter\vb_db_adapter;

/**
 * vBulletin Conversation Attachments Step (Supports vB6 node-based attachments)
 */
class vb_conversation_attachments_step implements step_interface
{
	public function get_name(): string
	{
		return 'conversation_attachments';
	}

	public function get_label(): string
	{
		return 'PM Attachments';
	}

	public function get_dependencies(): array
	{
		return ['conversation_messages', 'users'];
	}

	public function process_batch(
		string $run_id,
		$cursor,
		int $batch_size,
		migration_config_dto $config,
		source_provider_interface $provider,
		target_writer_interface $writer
	): step_result_dto {
		$result = new step_result_dto('conversation_attachments');
		$db = new vb_db_adapter($config);

		$cursor_id = (int)$cursor;
		$is_vb6 = $db->table_exists('attach') && $db->table_exists('node');

		if (!$is_vb6)
		{
			$result->is_completed = true;
			return $result;
		}

		$tbl_attach = $db->get_table_name('attach');
		$tbl_fd = $db->get_table_name('filedata');
		$tbl_node = $db->get_table_name('node');

		$sql = "SELECT a.nodeid AS attachmentid, n.parentid AS postid, n.userid, a.filename, n.publishdate AS dateline,
				       fd.filedata, fd.filesize, fd.filehash
				FROM {$tbl_attach} a
				JOIN {$tbl_node} n ON n.nodeid = a.nodeid
				JOIN {$tbl_node} p ON p.nodeid = n.parentid
				JOIN {$tbl_fd} fd ON a.filedataid = fd.filedataid
				WHERE p.contenttypeid = 27 AND a.nodeid > {$cursor_id}
				ORDER BY a.nodeid ASC
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

		$attachment_dtos = [];
		$max_cursor = $cursor_id;
		$temp_files = [];

		foreach ($rows as $row)
		{
			$aid = (int)$row['attachmentid'];
			if ($aid > $max_cursor)
			{
				$max_cursor = $aid;
			}

			$dto = new attachment_dto();
			$dto->source_id = $aid;
			$dto->post_source_id = (int)$row['postid'];
			$dto->user_source_id = (int)$row['userid'];
			$dto->real_filename = (string)$row['filename'];
			$dto->filesize = (int)($row['filesize'] ?? 0);
			$dto->filetime = (int)($row['dateline'] ?? time());
			$dto->extension = strtolower(pathinfo($dto->real_filename, PATHINFO_EXTENSION));
			$dto->content_type = 'conversation_message';

			if (!empty($row['filedata']))
			{
				$tmp_path = sys_get_temp_dir() . '/vb6_pm_att_' . $aid . '_' . md5($dto->real_filename) . '.' . $dto->extension;
				file_put_contents($tmp_path, $row['filedata']);
				$dto->source_physical_path = $tmp_path;
				if ($dto->filesize <= 0)
				{
					$dto->filesize = filesize($tmp_path);
				}
				$temp_files[] = $tmp_path;
			}

			$attachment_dtos[] = $dto;
		}

		$writer_res = $writer->write_attachments($attachment_dtos, [
			'run_id'        => $run_id,
			'source_system' => $config->source_system ?: 'vbulletin',
		]);

		foreach ($temp_files as $tf)
		{
			@unlink($tf);
		}

		$created = 0;
		$reused  = 0;
		$skipped = 0;
		$failed  = 0;

		foreach ($writer_res as $res)
		{
			if (($res['status'] ?? '') === 'success')
			{
				if (!empty($res['reused']))
				{
					$reused++;
				}
				else
				{
					$created++;
				}
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
			'reused'  => $reused,
			'updated' => 0,
			'skipped' => $skipped,
			'failed'  => $failed,
		];

		$result->current_cursor = (string)$cursor_id;
		$result->next_cursor = (string)$max_cursor;
		$result->is_completed = (count($rows) < $batch_size);

		return $result;
	}
}
