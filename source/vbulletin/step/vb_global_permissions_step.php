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
 * vBulletin Global Permissions Step
 */
class vb_global_permissions_step implements step_interface
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
		$db = new vb_db_adapter($config);

		$cursor_id = (int)$cursor;
		$tbl_group = $db->get_table_name('usergroup');

		$sql = "SELECT usergroupid, genericpermissions, forumpermissions, adminpermissions
				FROM {$tbl_group}
				WHERE usergroupid > {$cursor_id}
				ORDER BY usergroupid ASC
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
			$gid = (int)$row['usergroupid'];
			if ($gid > $max_cursor)
			{
				$max_cursor = $gid;
			}

			$gen_perm = (int)($row['genericpermissions'] ?? 0);
			$forum_perm = (int)($row['forumpermissions'] ?? 0);

			// Standard user permissions (view profile, search, pm, signature)
			if ($gid !== 1 && $gid !== 3) // Not unverified or banned
			{
				$permissions[] = [
					'group_source_id' => $gid,
					'phpbb_option'    => 'u_viewprofile',
					'auth_setting'    => 1,
				];
				$permissions[] = [
					'group_source_id' => $gid,
					'phpbb_option'    => 'u_search',
					'auth_setting'    => 1,
				];
				$permissions[] = [
					'group_source_id' => $gid,
					'phpbb_option'    => 'u_sendpm',
					'auth_setting'    => ($gid === 2 || $gid === 5 || $gid === 6 || $gid === 7) ? 1 : 0,
				];
				$permissions[] = [
					'group_source_id' => $gid,
					'phpbb_option'    => 'u_sig',
					'auth_setting'    => 1,
				];
			}
		}

		$writer_res = $writer->write_global_permissions($permissions, [
			'run_id'        => $run_id,
			'source_system' => $config->source_system ?: 'vbulletin',
		]);

		$created = 0;
		$skipped = 0;
		$failed = 0;

		foreach ($writer_res as $res)
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

		$processed = count($rows);
		$result->imported_count = $processed;
		$result->skipped_count = 0;
		$result->failed_count = 0;
		$result->metrics = [
			'created' => $processed,
			'reused'  => 0,
			'updated' => 0,
			'skipped' => 0,
			'failed'  => 0,
		];
		$result->next_cursor = (string)$max_cursor;
		$result->current_cursor = (string)$max_cursor;

		$max_total_id = (int)$provider->get_max_source_id('global_permissions', $config);
		if ($max_cursor >= $max_total_id || count($rows) < $batch_size)
		{
			$result->is_completed = true;
		}

		return $result;
	}
}
