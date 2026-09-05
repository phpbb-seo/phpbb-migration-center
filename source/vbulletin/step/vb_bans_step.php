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
use phpbbseo\migrationcenter\core\dto\ban_dto;
use phpbbseo\migrationcenter\core\dto\migration_config_dto;
use phpbbseo\migrationcenter\core\dto\step_result_dto;
use phpbbseo\migrationcenter\source\vbulletin\adapter\vb_db_adapter;

/**
 * vBulletin User Bans Migration Step
 */
class vb_bans_step implements step_interface
{
	public function get_name(): string
	{
		return 'bans';
	}

	public function get_label(): string
	{
		return 'Bans';
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
		$result = new step_result_dto('bans');
		$db = new vb_db_adapter($config);

		$cursor_id = (int)$cursor;
		$tbl_ban = $db->get_table_name('userban');

		if (!$db->table_exists('userban'))
		{
			$result->is_completed = true;
			return $result;
		}

		$sql = "SELECT userid, bandate, liftdate, adminid, reason
				FROM {$tbl_ban}
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

		$ban_dtos = [];
		$max_cursor = $cursor_id;

		foreach ($rows as $row)
		{
			$uid = (int)$row['userid'];
			if ($uid > $max_cursor)
			{
				$max_cursor = $uid;
			}

			$dto = new ban_dto();
			$dto->source_id = $uid;
			$dto->ban_type = 'user';
			$dto->user_source_id = $uid;
			$dto->ban_start = (int)($row['bandate'] ?? time());
			$dto->ban_end = (int)($row['liftdate'] ?? 0);
			$dto->ban_reason = trim((string)($row['reason'] ?? 'Imported vBulletin ban'));
			$dto->ban_give_reason = $dto->ban_reason;

			$ban_dtos[] = $dto;
		}

		$writer_res = $writer->write_bans($ban_dtos, [
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

		$max_total_id = (int)$provider->get_max_source_id('bans', $config);
		if ($max_cursor >= $max_total_id || count($rows) < $batch_size)
		{
			$result->is_completed = true;
		}

		return $result;
	}
}
