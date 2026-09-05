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
 * MyBB Global Permissions Step
 */
class global_permissions_step implements step_interface
{
	public function get_name(): string
	{
		return 'global_permissions';
	}

	public function get_label(): string
	{
		return 'Global Permissions';
	}

	public function get_dependencies(): array
	{
		return ['groups'];
	}

	public function process_batch(
		string $run_id,
		$cursor,
		int $batch_size,
		migration_config_dto $config,
		source_provider_interface $provider,
		target_writer_interface $writer
	): step_result_dto {
		$result = new step_result_dto('global_permissions');
		$db = new mybb_db_adapter($config);

		$cursor_id = (int)$cursor;
		$tbl_group = $db->get_table_name('usergroups');

		$sql = "SELECT * FROM {$tbl_group}
				WHERE gid > {$cursor_id}
				ORDER BY gid ASC
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
			$gid = (int)$row['gid'];
			if ($gid > $max_cursor)
			{
				$max_cursor = $gid;
			}

			if (!empty($row['canviewprofiles']))
			{
				$permissions[] = ['group_source_id' => $gid, 'phpbb_option' => 'u_viewprofile', 'auth_setting' => 1];
			}
			if (!empty($row['cansearch']))
			{
				$permissions[] = ['group_source_id' => $gid, 'phpbb_option' => 'u_search', 'auth_setting' => 1];
			}
			if (!empty($row['cansendpms']))
			{
				$permissions[] = ['group_source_id' => $gid, 'phpbb_option' => 'u_sendpm', 'auth_setting' => 1];
			}
			if (!empty($row['canusepms']))
			{
				$permissions[] = ['group_source_id' => $gid, 'phpbb_option' => 'u_readpm', 'auth_setting' => 1];
			}
			if (!empty($row['canuploadavatars']))
			{
				$permissions[] = ['group_source_id' => $gid, 'phpbb_option' => 'u_chgavatar', 'auth_setting' => 1];
			}
			if (!empty($row['candlattachments']))
			{
				$permissions[] = ['group_source_id' => $gid, 'phpbb_option' => 'u_download', 'auth_setting' => 1];
			}
			if (!empty($row['canusesig']))
			{
				$permissions[] = ['group_source_id' => $gid, 'phpbb_option' => 'u_sig', 'auth_setting' => 1];
			}
		}

		$writer_res = $writer->write_global_permissions($permissions, [
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

		$max_id = (int)$provider->get_max_source_id('global_permissions', $config);
		if (count($rows) < $batch_size || $max_cursor >= $max_id)
		{
			$result->is_completed = true;
		}

		return $result;
	}
}
