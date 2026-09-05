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
 * vBulletin Post Attachments Step (Supports vB3 and vB4 storage schemas)
 */
class vb_attachments_step implements step_interface
{
	public function get_name(): string
	{
		return 'attachments';
	}

	public function get_label(): string
	{
		return 'Post Attachments';
	}

	public function get_dependencies(): array
	{
		return ['posts', 'users'];
	}

	public function process_batch(
		string $run_id,
		$cursor,
		int $batch_size,
		migration_config_dto $config,
		source_provider_interface $provider,
		target_writer_interface $writer
	): step_result_dto {
		$result = new step_result_dto('attachments');
		$db = new vb_db_adapter($config);

		$cursor_id = (int)$cursor;
		$is_vb4 = $db->table_exists('filedata');

		$tbl_attach = $db->get_table_name('attachment');
		$tbl_fd = $db->get_table_name('filedata');

		if ($is_vb4)
		{
			$sql = "SELECT a.attachmentid, a.contentid AS postid, a.userid, a.filename, a.dateline, fd.filedata, fd.filesize, fd.filehash
					FROM {$tbl_attach} a
					JOIN {$tbl_fd} fd ON a.filedataid = fd.filedataid
					WHERE a.attachmentid > {$cursor_id}
					ORDER BY a.attachmentid ASC
					LIMIT {$batch_size}";
		}
		else
		{
			$sql = "SELECT attachmentid, postid, userid, filename, filedata, filesize, dateline
					FROM {$tbl_attach}
					WHERE attachmentid > {$cursor_id}
					ORDER BY attachmentid ASC
					LIMIT {$batch_size}";
		}

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

		try
		{
			foreach ($rows as $row)
			{
				$aid = (int)$row['attachmentid'];
				if ($aid > $max_cursor)
				{
					$max_cursor = $aid;
				}

				$dto = new attachment_dto();
				$dto->source_id = $aid;
				$dto->data_id = $aid;
				$dto->content_type = 'post';
				$dto->post_source_id = (int)($row['postid'] ?? 0);
				$dto->user_source_id = (int)($row['userid'] ?? 0);
				$dto->real_filename = (string)($row['filename'] ?? "attachment_{$aid}");
				$dto->filesize = (int)($row['filesize'] ?? 0);
				$dto->filetime = (int)($row['dateline'] ?? time());
				$dto->extension = strtolower(pathinfo($dto->real_filename, PATHINFO_EXTENSION));

				// Handle file data blob
				if (!empty($row['filedata']))
				{
					$tmp_path = sys_get_temp_dir() . '/vb_att_' . $aid . '_' . md5($dto->real_filename) . '.' . $dto->extension;
					file_put_contents($tmp_path, $row['filedata']);
					$temp_files[] = $tmp_path;
					$dto->source_physical_path = $tmp_path;
					if ($dto->filesize <= 0)
					{
						$dto->filesize = filesize($tmp_path);
					}
				}

				$attachment_dtos[] = $dto;
			}

			$writer_res = $writer->write_attachments($attachment_dtos, [
				'run_id'        => $run_id,
				'source_system' => $config->source_system ?: 'vbulletin',
			]);

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

			$result->imported_count = $created + $reused;
			$result->skipped_count = $skipped;
			$result->failed_count = $failed;
			$result->metrics = [
				'created' => $created,
				'reused'  => $reused,
				'updated' => 0,
				'skipped' => $skipped,
				'failed'  => $failed,
			];
			$result->next_cursor = (string)$max_cursor;
			$result->current_cursor = (string)$max_cursor;
		}
		finally
		{
			foreach ($temp_files as $tmp)
			{
				if (file_exists($tmp))
				{
					@unlink($tmp);
				}
			}
		}

		$max_total_id = (int)$provider->get_max_source_id('attachments', $config);
		if ($max_cursor >= $max_total_id || count($rows) < $batch_size)
		{
			$result->is_completed = true;
		}

		return $result;
	}
}
