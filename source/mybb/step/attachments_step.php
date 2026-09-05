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
use phpbbseo\migrationcenter\core\dto\attachment_dto;
use phpbbseo\migrationcenter\core\dto\migration_config_dto;
use phpbbseo\migrationcenter\core\dto\step_result_dto;
use phpbbseo\migrationcenter\source\mybb\adapter\mybb_db_adapter;

/**
 * MyBB Post Attachments Migration Step
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
		$db = new mybb_db_adapter($config);

		$cursor_id = (int)$cursor;
		$tbl_attach = $db->get_table_name('attachments');

		if (!$db->table_exists('attachments'))
		{
			$result->is_completed = true;
			return $result;
		}

		$sql = "SELECT aid, pid, uid, filename, filetype, filesize, attachname, downloads, dateuploaded
				FROM {$tbl_attach}
				WHERE aid > {$cursor_id}
				ORDER BY aid ASC
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
		$source_path = rtrim($config->source_path, '/\\');

		foreach ($rows as $row)
		{
			$aid = (int)$row['aid'];
			if ($aid > $max_cursor)
			{
				$max_cursor = $aid;
			}

			$dto = new attachment_dto();
			$dto->source_id = $aid;
			$dto->data_id = $aid;
			$dto->content_type = 'post';
			$dto->post_source_id = (int)($row['pid'] ?? 0);
			$dto->user_source_id = (int)($row['uid'] ?? 0);
			$dto->real_filename = (string)($row['filename'] ?? "attachment_{$aid}");
			$dto->filesize = (int)($row['filesize'] ?? 0);
			$dto->filetime = (int)($row['dateuploaded'] ?? time());
			$dto->extension = strtolower(pathinfo($dto->real_filename, PATHINFO_EXTENSION));

			// Resolve physical path in MyBB uploads folder
			$attach_rel = (string)($row['attachname'] ?? '');
			$resolved_path = null;

			$candidates = [
				$source_path . '/uploads/' . $attach_rel,
				$source_path . '/' . $attach_rel,
				$source_path . '/uploads/' . basename($attach_rel),
			];

			foreach ($candidates as $cand)
			{
				if ($attach_rel !== '' && file_exists($cand) && is_file($cand))
				{
					$resolved_path = $cand;
					break;
				}
			}

			if ($resolved_path !== null)
			{
				$dto->source_physical_path = $resolved_path;
				if ($dto->filesize <= 0)
				{
					$dto->filesize = (int)filesize($resolved_path);
				}
			}

			$attachment_dtos[] = $dto;
		}

		$writer_res = $writer->write_attachments($attachment_dtos, [
			'run_id'        => $run_id,
			'source_system' => $config->source_system ?: 'mybb',
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

		$max_total_id = (int)$provider->get_max_source_id('attachments', $config);
		if ($max_cursor >= $max_total_id || count($rows) < $batch_size)
		{
			$result->is_completed = true;
		}

		return $result;
	}
}
