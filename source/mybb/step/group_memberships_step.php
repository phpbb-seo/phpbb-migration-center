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
use phpbbseo\migrationcenter\core\dto\migration_config_dto;
use phpbbseo\migrationcenter\core\dto\step_result_dto;
use phpbbseo\migrationcenter\source\mybb\adapter\mybb_db_adapter;

/**
 * MyBB Group Memberships Step
 */
class group_memberships_step implements step_interface
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
		$db = new mybb_db_adapter($config);

		$cursor_id = (int)$cursor;
		$tbl_user = $db->get_table_name('users');

		$sql = "SELECT uid, usergroup, additionalgroups
				FROM {$tbl_user}
				WHERE uid > {$cursor_id}
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

		$memberships = [];
		$max_cursor = $cursor_id;

		foreach ($rows as $row)
		{
			$uid = (int)$row['uid'];
			if ($uid > $max_cursor)
			{
				$max_cursor = $uid;
			}

			$primary_group = (int)$row['usergroup'];
			$secondary_groups = [];
			if (!empty($row['additionalgroups']))
			{
				$parts = explode(',', (string)$row['additionalgroups']);
				foreach ($parts as $p)
				{
					$p = trim($p);
					if ($p !== '' && ctype_digit($p))
					{
						$secondary_groups[] = (int)$p;
					}
				}
			}

			$all_groups = array_merge([$primary_group], $secondary_groups);
			$is_admin = in_array(4, $all_groups, true);
			$is_mod = in_array(3, $all_groups, true) || in_array(6, $all_groups, true);

			$memberships[] = [
				'user_source_id'              => $uid,
				'primary_group_source_id'    => $primary_group,
				'secondary_group_source_ids' => array_unique($secondary_groups),
				'is_admin'                   => $is_admin,
				'is_moderator'               => $is_mod,
			];
		}

		$writer_res = $writer->write_group_memberships($memberships, [
			'run_id'        => $run_id,
			'source_system' => 'mybb',
		]);

		$created = count($writer_res);
		$result->imported_count = $created;
		$result->processed_records = count($rows);
		$result->imported_records = $created;
		$result->skipped_count = 0;
		$result->failed_count = 0;
		$result->metrics = [
			'created' => $created,
			'reused'  => 0,
			'updated' => 0,
			'skipped' => 0,
			'failed'  => 0,
		];
		$result->next_cursor = (string)$max_cursor;
		$result->current_cursor = (string)$cursor_id;

		$max_id = (int)$provider->get_max_source_id('group_memberships', $config);
		if (count($rows) < $batch_size || $max_cursor >= $max_id)
		{
			$result->is_completed = true;
		}

		return $result;
	}
}
