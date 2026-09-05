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
use phpbbseo\migrationcenter\core\dto\migration_config_dto;
use phpbbseo\migrationcenter\core\dto\step_result_dto;
use phpbbseo\migrationcenter\source\vbulletin\adapter\vb_db_adapter;

/**
 * vBulletin Group Memberships Step
 */
class vb_group_memberships_step implements step_interface
{
	public function get_name(): string
	{
		return 'group_memberships';
	}

	public function get_label(): string
	{
		return 'Group Memberships';
	}

	public function get_dependencies(): array
	{
		return ['groups', 'users'];
	}

	public function process_batch(
		string $run_id,
		$cursor,
		int $batch_size,
		migration_config_dto $config,
		source_provider_interface $provider,
		target_writer_interface $writer
	): step_result_dto {
		$result = new step_result_dto('group_memberships');
		$db = new vb_db_adapter($config);

		$cursor_id = (int)$cursor;
		$tbl_user = $db->get_table_name('user');

		$sql = "SELECT userid, usergroupid, membergroupids
				FROM {$tbl_user}
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

		$memberships = [];
		$max_cursor = $cursor_id;

		foreach ($rows as $row)
		{
			$uid = (int)$row['userid'];
			if ($uid > $max_cursor)
			{
				$max_cursor = $uid;
			}

			$primary_group = (int)$row['usergroupid'];
			$secondary_groups = [];
			if (!empty($row['membergroupids']))
			{
				$parts = explode(',', (string)$row['membergroupids']);
				foreach ($parts as $p)
				{
					$p = trim($p);
					if ($p !== '' && ctype_digit($p))
					{
						$secondary_groups[] = (int)$p;
					}
				}
			}

			$memberships[] = [
				'user_source_id'              => $uid,
				'primary_group_source_id'    => $primary_group,
				'secondary_group_source_ids' => array_unique($secondary_groups),
				'is_admin'                   => false,
				'is_moderator'               => false,
			];
		}

		$writer_res = $writer->write_group_memberships($memberships, [
			'run_id'        => $run_id,
			'source_system' => $config->source_system ?: 'vbulletin',
		]);

		$created = 0;
		$skipped = 0;
		$failed = 0;

		foreach ($writer_res as $src_id => $res)
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

		$total_expected = (int)$provider->get_total_records('users', $config);
		$max_total_id = (int)$provider->get_max_source_id('users', $config);
		if ($max_cursor >= $max_total_id || count($rows) < $batch_size)
		{
			$result->is_completed = true;
		}

		return $result;
	}
}
