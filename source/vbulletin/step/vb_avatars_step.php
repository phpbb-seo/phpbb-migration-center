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
use phpbbseo\migrationcenter\core\dto\avatar_dto;
use phpbbseo\migrationcenter\core\dto\migration_config_dto;
use phpbbseo\migrationcenter\core\dto\step_result_dto;
use phpbbseo\migrationcenter\source\vbulletin\adapter\vb_db_adapter;

/**
 * vBulletin Avatars Migration Step
 */
class vb_avatars_step implements step_interface
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
		$db = new vb_db_adapter($config);

		$cursor_id = (int)$cursor;
		$tbl_avatar = $db->get_table_name('customavatar');

		if (!$db->table_exists('customavatar'))
		{
			$result->is_completed = true;
			return $result;
		}

		$sql = "SELECT userid, filedata, dateline, filename, filesize, width, height
				FROM {$tbl_avatar}
				WHERE userid > {$cursor_id}
				ORDER BY userid ASC
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
		$temp_files = [];

		try
		{
			foreach ($rows as $row)
			{
				$uid = (int)$row['userid'];
				if ($uid > $max_cursor)
				{
					$max_cursor = $uid;
				}

				if (empty($row['filedata']))
				{
					continue;
				}

				$dto = new avatar_dto();
				$dto->user_source_id = $uid;
				$dto->avatar_date = (int)($row['dateline'] ?? time());
				$dto->avatar_type = 'upload';
				$dto->extension = strtolower(pathinfo((string)($row['filename'] ?? 'avatar.png'), PATHINFO_EXTENSION)) ?: 'png';

				$tmp_path = sys_get_temp_dir() . '/vb_av_' . $uid . '_' . bin2hex(random_bytes(4)) . '.' . $dto->extension;
				file_put_contents($tmp_path, $row['filedata']);
				$temp_files[] = $tmp_path;

				$dto->source_physical_path = $tmp_path;
				$dto->source_filesize = filesize($tmp_path);
				$dto->source_sha256 = hash_file('sha256', $tmp_path);
				$dto->source_width = (int)($row['width'] ?? 64);
				$dto->source_height = (int)($row['height'] ?? 64);

				$avatar_dtos[] = $dto;
			}

			$writer_res = $writer->write_avatars($avatar_dtos, [
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

		$max_total_id = (int)$provider->get_max_source_id('avatars', $config);
		if ($max_cursor >= $max_total_id || count($rows) < $batch_size)
		{
			$result->is_completed = true;
		}

		return $result;
	}
}
