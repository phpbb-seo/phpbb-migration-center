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
use phpbbseo\migrationcenter\core\dto\attachment_dto;
use phpbbseo\migrationcenter\core\dto\migration_config_dto;
use phpbbseo\migrationcenter\core\dto\step_result_dto;
use phpbbseo\migrationcenter\source\smf\adapter\smf_db_adapter;

/**
 * SMF Post Attachments Migration Step
 */
class attachments_step implements step_interface
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
		$db = new smf_db_adapter($config);

		$cursor_id = (int)$cursor;
		$tbl_attach = $db->get_table_name('attachments');

		if (!$db->table_exists('attachments'))
		{
			$result->is_completed = true;
			return $result;
		}

		// Only select post attachments (attachment_type = 0)
		$sql = "SELECT id_attach, id_folder, id_msg, id_member, filename, file_hash, fileext, size, downloads
				FROM {$tbl_attach}
				WHERE id_attach > {$cursor_id} AND attachment_type = 0
				ORDER BY id_attach ASC
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
		$source_path = rtrim(str_replace('\\', '/', $config->source_path), '/');

		// Parse attachmentUploadDir if configured in smf_settings
		$upload_dirs = [];
		$raw_dirs = $db->get_setting('attachmentUploadDir');
		if (!empty($raw_dirs))
		{
			$decoded = @json_decode($raw_dirs, true);
			if (is_array($decoded))
			{
				$upload_dirs = $decoded;
			}
			else
			{
				$upload_dirs[1] = $raw_dirs;
			}
		}

		foreach ($rows as $row)
		{
			$aid = (int)$row['id_attach'];
			if ($aid > $max_cursor)
			{
				$max_cursor = $aid;
			}

			$dto = new attachment_dto();
			$dto->source_id = $aid;
			$dto->data_id = $aid;
			$dto->content_type = 'post';
			$dto->post_source_id = (int)($row['id_msg'] ?? 0);
			$dto->user_source_id = (int)($row['id_member'] ?? 0);
			$dto->real_filename = (string)($row['filename'] ?? "attachment_{$aid}");
			$dto->filesize = (int)($row['size'] ?? 0);
			$dto->filetime = time();
			$dto->extension = strtolower(pathinfo($dto->real_filename, PATHINFO_EXTENSION) ?: (string)($row['fileext'] ?? ''));

			// Resolve physical path
			$file_hash = (string)($row['file_hash'] ?? '');
			$id_folder = (int)($row['id_folder'] ?? 1);

			$candidate_dirs = [];
			if (isset($upload_dirs[$id_folder]))
			{
				$candidate_dirs[] = rtrim(str_replace('\\', '/', $upload_dirs[$id_folder]), '/');
			}
			$candidate_dirs[] = $source_path . '/attachments';

			$physical_path = null;
			foreach ($candidate_dirs as $dir)
			{
				$try1 = $dir . '/' . $aid . '_' . $file_hash . '.dat';
				$try2 = $dir . '/' . $aid . '_' . $file_hash;
				$try3 = $dir . '/' . $aid;

				if (file_exists($try1) && is_readable($try1))
				{
					$physical_path = $try1;
					break;
				}
				else if (file_exists($try2) && is_readable($try2))
				{
					$physical_path = $try2;
					break;
				}
				else if (file_exists($try3) && is_readable($try3))
				{
					$physical_path = $try3;
					break;
				}
			}

			if ($physical_path && file_exists($physical_path))
			{
				$dto->physical_filepath = $physical_path;
				if ($dto->filesize <= 0)
				{
					$dto->filesize = (int)filesize($physical_path);
				}
			}

			$attachment_dtos[] = $dto;
		}

		$writer_res = $writer->write_attachments($attachment_dtos, [
			'run_id'        => $run_id,
			'source_system' => 'smf',
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
		$result->processed_records = count($rows);
		$result->imported_records = $result->imported_count;
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
		$result->current_cursor = (string)$cursor_id;

		$max_id = (int)$provider->get_max_source_id('attachments', $config);
		if (count($rows) < $batch_size || $max_cursor >= $max_id)
		{
			$result->is_completed = true;
		}

		return $result;
	}
}
