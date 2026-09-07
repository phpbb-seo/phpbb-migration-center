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
use phpbbseo\migrationcenter\core\dto\migration_config_dto;
use phpbbseo\migrationcenter\core\dto\step_result_dto;
use phpbbseo\migrationcenter\source\smf\adapter\smf_db_adapter;

/**
 * SMF Board/Node Permissions Migration Step
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
		$db = new smf_db_adapter($config);

		$cursor_id = (int)$cursor;
		$tbl_boards = $db->get_table_name('boards');

		$sql = "SELECT id_board, id_cat, name
				FROM {$tbl_boards}
				WHERE id_board > {$cursor_id}
				ORDER BY id_board ASC
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
			$bid = (int)$row['id_board'];
			if ($bid > $max_cursor)
			{
				$max_cursor = $bid;
			}

			// Guest access (-1 in SMF maps to Guests) and Member access (0 in SMF maps to Registered Users)
			$permissions[] = [
				'node_source_id'  => $bid,
				'group_source_id' => -1,
				'phpbb_option'    => 'f_read',
				'auth_setting'    => 1,
			];
			$permissions[] = [
				'node_source_id'  => $bid,
				'group_source_id' => 0,
				'phpbb_option'    => 'f_read',
				'auth_setting'    => 1,
			];
		}

		$writer_res = $writer->write_node_permissions($permissions, [
			'run_id'        => $run_id,
			'source_system' => 'smf',
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

		$max_total_id = (int)$provider->get_max_source_id('node_permissions', $config);
		if ($max_cursor >= $max_total_id || count($rows) < $batch_size)
		{
			$result->is_completed = true;
		}

		return $result;
	}
}
