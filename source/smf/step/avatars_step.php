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
use phpbbseo\migrationcenter\core\dto\avatar_dto;
use phpbbseo\migrationcenter\core\dto\migration_config_dto;
use phpbbseo\migrationcenter\core\dto\step_result_dto;
use phpbbseo\migrationcenter\source\smf\adapter\smf_db_adapter;

/**
 * SMF User Avatars Migration Step
 */
class avatars_step implements step_interface
{
	public function get_name(): string
	{
		return 'avatars';
	}

	public function get_label(): string
	{
		return 'Avatars';
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
		$result = new step_result_dto('avatars');
		$db = new smf_db_adapter($config);

		$cursor_id = (int)$cursor;
		$tbl_members = $db->get_table_name('members');
		$tbl_attach  = $db->get_table_name('attachments');

		$sql = "SELECT m.id_member, m.avatar, m.last_login,
				       a.id_attach, a.filename as attach_filename, a.width, a.height
				FROM {$tbl_members} m
				LEFT JOIN {$tbl_attach} a ON (a.id_member = m.id_member AND a.attachment_type = 1)
				WHERE m.id_member > {$cursor_id}
				  AND (m.avatar != '' OR a.id_attach IS NOT NULL)
				ORDER BY m.id_member ASC
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

		$avatar_dtos = [];
		$max_cursor = $cursor_id;
		$source_path = rtrim(str_replace('\\', '/', $config->source_path), '/');

		$custom_dir = $db->get_setting('custom_avatar_dir') ?: ($source_path . '/custom_avatar');
		$custom_dir = rtrim(str_replace('\\', '/', $custom_dir), '/');

		foreach ($rows as $row)
		{
			$uid = (int)$row['id_member'];
			if ($uid > $max_cursor)
			{
				$max_cursor = $uid;
			}

			$dto = new avatar_dto();
			$dto->user_source_id = $uid;
			$dto->avatar_date = (int)($row['last_login'] ?? time());
			$dto->width = (int)($row['width'] ?? 0);
			$dto->height = (int)($row['height'] ?? 0);

			$filename = trim((string)($row['attach_filename'] ?: $row['avatar']));
			if (empty($filename))
			{
				continue;
			}

			// Remote URL avatar
			if (preg_match('#^https?://#i', $filename))
			{
				$dto->avatar_type = 'remote';
				$dto->avatar_file = $filename;
				$avatar_dtos[] = $dto;
				continue;
			}

			// Local custom avatar on filesystem
			$candidates = [
				$custom_dir . '/' . $filename,
				$source_path . '/custom_avatar/' . $filename,
				$source_path . '/avatars/' . $filename,
				$source_path . '/attachments/' . $filename,
			];

			$resolved = null;
			foreach ($candidates as $cand)
			{
				if (file_exists($cand) && is_readable($cand))
				{
					$resolved = $cand;
					break;
				}
			}

			if ($resolved && file_exists($resolved))
			{
				$dto->avatar_type = 'upload';
				$dto->avatar_file = $filename;
				$dto->physical_filepath = $resolved;
				if ($dto->width <= 0 || $dto->height <= 0)
				{
					$size = @getimagesize($resolved);
					if ($size)
					{
						$dto->width = (int)$size[0];
						$dto->height = (int)$size[1];
					}
				}
				$avatar_dtos[] = $dto;
			}
		}

		$writer_res = $writer->write_avatars($avatar_dtos, [
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

		$max_id = (int)$provider->get_max_source_id('avatars', $config);
		if (count($rows) < $batch_size || $max_cursor >= $max_id)
		{
			$result->is_completed = true;
		}

		return $result;
	}
}
