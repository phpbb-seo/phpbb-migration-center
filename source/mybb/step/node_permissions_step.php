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
 * MyBB Forum/Node Permissions Migration Step
 */
class node_permissions_step implements step_interface
{
	public function get_name(): string
	{
		return 'node_permissions';
	}

	public function get_label(): string
	{
		return 'Forum Permissions';
	}

	public function get_dependencies(): array
	{
		return ['groups', 'forums'];
	}

	public function process_batch(
		string $run_id,
		$cursor,
		int $batch_size,
		migration_config_dto $config,
		source_provider_interface $provider,
		target_writer_interface $writer
	): step_result_dto {
		$result = new step_result_dto('node_permissions');
		$db = new mybb_db_adapter($config);

		$cursor_id = (int)$cursor;
		$tbl_perm = $db->get_table_name('forumpermissions');

		if (!$db->table_exists('forumpermissions'))
		{
			$result->is_completed = true;
			return $result;
		}

		$sql = "SELECT pid, fid, gid, canview
				FROM {$tbl_perm}
				WHERE pid > {$cursor_id}
				ORDER BY pid ASC
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

		$permissions = [];
		$max_cursor = $cursor_id;

		foreach ($rows as $row)
		{
			$pid = (int)$row['pid'];
			if ($pid > $max_cursor)
			{
				$max_cursor = $pid;
			}

			$permissions[] = [
				'source_node_id'  => (int)$row['fid'],
				'node_source_id'  => (int)$row['fid'],
				'group_source_id' => (int)$row['gid'],
				'phpbb_option'    => 'f_read',
				'auth_setting'    => !empty($row['canview']) ? 1 : 0,
			];
		}

		$writer_res = $writer->write_node_permissions($permissions, [
			'run_id'        => $run_id,
			'source_system' => $config->source_system ?: 'mybb',
		]);

		$created = count($writer_res);
		$result->imported_count = $created;
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
		$result->current_cursor = (string)$max_cursor;

		$max_total_id = (int)$provider->get_max_source_id('node_permissions', $config);
		if ($max_cursor >= $max_total_id || count($rows) < $batch_size)
		{
			$result->is_completed = true;
		}

		return $result;
	}
}
