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
use phpbbseo\migrationcenter\core\dto\avatar_dto;
use phpbbseo\migrationcenter\core\dto\migration_config_dto;
use phpbbseo\migrationcenter\core\dto\step_result_dto;
use phpbbseo\migrationcenter\source\mybb\adapter\mybb_db_adapter;

/**
 * MyBB User Avatars Migration Step
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
		$db = new mybb_db_adapter($config);

		$cursor_id = (int)$cursor;
		$tbl_users = $db->get_table_name('users');

		$sql = "SELECT uid, avatar, avatardimensions, avatartype, lastactive
				FROM {$tbl_users}
				WHERE uid > {$cursor_id} AND avatar != '' AND avatar IS NOT NULL
				ORDER BY uid ASC
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
		$source_path = rtrim($config->source_path, '/\\');

		foreach ($rows as $row)
		{
			$uid = (int)$row['uid'];
			if ($uid > $max_cursor)
			{
				$max_cursor = $uid;
			}

			$raw_avatar = trim((string)$row['avatar']);
			if ($raw_avatar === '')
			{
				continue;
			}

			// Clean query strings like ?dateline=12345
			$clean_avatar = preg_replace('/\?.*$/', '', $raw_avatar);

			$dto = new avatar_dto();
			$dto->user_source_id = $uid;
			$dto->avatar_date = (int)($row['lastactive'] ?? time());

			// Dimensions e.g. "120|120"
			$dims = explode('|', (string)($row['avatardimensions'] ?? ''));
			if (count($dims) >= 2)
			{
				$dto->width = (int)$dims[0];
				$dto->height = (int)$dims[1];
			}

			if (preg_match('#^https?://#i', $clean_avatar))
			{
				$dto->avatar_type = 'remote';
				$dto->remote_url = $clean_avatar;
				$dto->extension = strtolower(pathinfo(parse_url($clean_avatar, PHP_URL_PATH), PATHINFO_EXTENSION)) ?: 'png';
			}
			else
			{
				$rel = ltrim($clean_avatar, './\\');
				$resolved_path = null;

				$candidates = [
					$source_path . '/' . $rel,
					$source_path . '/uploads/avatars/' . basename($rel),
					$source_path . '/uploads/' . basename($rel),
				];

				foreach ($candidates as $cand)
				{
					if (file_exists($cand) && is_file($cand))
					{
						$resolved_path = $cand;
						break;
					}
				}

				if ($resolved_path !== null)
				{
					$dto->avatar_type = 'upload';
					$dto->source_physical_path = $resolved_path;
					$dto->extension = strtolower(pathinfo($resolved_path, PATHINFO_EXTENSION)) ?: 'png';
					$dto->filesize = (int)filesize($resolved_path);
				}
				else
				{
					continue;
				}
			}

			$avatar_dtos[] = $dto;
		}

		$writer_res = $writer->write_avatars($avatar_dtos, [
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

		$max_total_id = (int)$provider->get_max_source_id('avatars', $config);
		if ($max_cursor >= $max_total_id || count($rows) < $batch_size)
		{
			$result->is_completed = true;
		}

		return $result;
	}
}
