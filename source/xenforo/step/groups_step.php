<?php
/**
 * phpBB Migration Center Extension
 *
 * @copyright (c) 2026 phpBB SEO Team
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace phpbbseo\migrationcenter\source\xenforo\step;

use phpbbseo\migrationcenter\core\contract\step_interface;
use phpbbseo\migrationcenter\core\contract\source_provider_interface;
use phpbbseo\migrationcenter\core\contract\target_writer_interface;
use phpbbseo\migrationcenter\core\dto\migration_config_dto;
use phpbbseo\migrationcenter\core\dto\step_result_dto;
use phpbbseo\migrationcenter\core\dto\group_dto;
use phpbbseo\migrationcenter\source\xenforo\adapter\xf_db_adapter;

/**
 * XenForo Groups Migration Step
 */
class groups_step implements step_interface
{
	/**
	 * Unique step identifier
	 *
	 * @return string
	 */
	public function get_name(): string
	{
		return 'groups';
	}

	/**
	 * Human-readable label
	 *
	 * @return string
	 */
	public function get_label(): string
	{
		return 'User Groups';
	}

	/**
	 * Step dependencies
	 *
	 * @return array
	 */
	public function get_dependencies(): array
	{
		return [];
	}

	/**
	 * Process groups batch
	 *
	 * @param string $run_id
	 * @param string|int $cursor
	 * @param int $batch_size
	 * @param migration_config_dto $config
	 * @param source_provider_interface $provider
	 * @param target_writer_interface $writer
	 * @return step_result_dto
	 */
	public function process_batch(
		string $run_id,
		$cursor,
		int $batch_size,
		migration_config_dto $config,
		source_provider_interface $provider,
		target_writer_interface $writer
	): step_result_dto {
		$result = new step_result_dto('groups');
		$db = new xf_db_adapter($config);
		$prefix = $db->get_prefix();

		$cursor_id = (int)$cursor;
		$sql = "SELECT * FROM `{$prefix}user_group`
				WHERE user_group_id > :cursor
				ORDER BY user_group_id ASC
				LIMIT :batch_limit";

		$stmt = $db->get_pdo()->prepare($sql);
		$stmt->bindValue(':cursor', $cursor_id, \PDO::PARAM_INT);
		$stmt->bindValue(':batch_limit', $batch_size, \PDO::PARAM_INT);
		$stmt->execute();
		$rows = $stmt->fetchAll();

		$result->read_count = count($rows);

		if (empty($rows))
		{
			$result->is_completed = true;
			$result->next_cursor = $cursor;
			return $result;
		}

		$normalized_groups = [];
		$last_id = $cursor_id;

		foreach ($rows as $row)
		{
			$group_id = (int)$row['user_group_id'];
			$last_id = $group_id;

			$group = new group_dto();
			$group->source_id = $group_id;
			$group->group_name = (string)$row['title'];
			$group->group_name_clean = mb_strtolower($group->group_name, 'UTF-8');
			$group->display_style_priority = (int)($row['display_style_priority'] ?? 0);
			$group->user_title = (string)($row['user_title'] ?? '');
			$group->group_type = 0; // OPEN by default

			// Handle XenForo Standard Default Groups
			switch ($group_id)
			{
				case 1: // Unregistered / Unconfirmed
					$group->is_builtin = true;
					$group->canonical_name = 'GUESTS';
					break;

				case 2: // Registered
					$group->is_builtin = true;
					$group->canonical_name = 'REGISTERED';
					break;

				case 3: // Administrative
					$group->is_builtin = true;
					$group->canonical_name = 'ADMINISTRATORS';
					break;

				case 4: // Moderating
					$group->is_builtin = true;
					$group->canonical_name = 'GLOBAL_MODERATORS';
					break;

				default:
					$group->is_builtin = false;
					$group->canonical_name = '';
					break;
			}

			$group->raw_source_data = $row;
			$normalized_groups[] = $group;
		}

		$result->next_cursor = $last_id;

		// Dry-Run Handling
		if ($config->dry_run)
		{
			$result->imported_count = count($normalized_groups);
			if (count($rows) < $batch_size)
			{
				$result->is_completed = true;
			}
			return $result;
		}

		// Write groups via target writer
		$write_options = [
			'run_id'        => $run_id,
			'source_system' => $config->source_system ?: 'xenforo',
		];

		$write_results = $writer->write_groups($normalized_groups, $write_options);

		$created_cnt = 0;
		$reused_cnt = 0;

		foreach ($write_results as $src_id => $res)
		{
			if ($res['status'] === 'success')
			{
				$result->imported_count++;
				if (!empty($res['builtin']) || !empty($res['reused']))
				{
					$reused_cnt++;
				}
				else
				{
					$created_cnt++;
				}
			}
			else if ($res['status'] === 'skipped')
			{
				$result->skipped_count++;
			}
			else
			{
				$result->add_error(
					'GROUP_WRITE_FAILED',
					"Group ID {$src_id} write failed: " . ($res['error'] ?? 'Unknown error'),
					'error',
					$src_id
				);
			}
		}

		$result->metrics = [
			'created' => $created_cnt,
			'reused'  => $reused_cnt,
			'updated' => 0,
		];

		if (count($rows) < $batch_size)
		{
			$result->is_completed = true;
		}

		return $result;
	}
}
