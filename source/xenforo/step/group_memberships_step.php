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
use phpbbseo\migrationcenter\source\xenforo\adapter\xf_db_adapter;

/**
 * XenForo Group Memberships Reconciliation Step
 */
class group_memberships_step implements step_interface
{
	/**
	 * Unique step identifier
	 *
	 * @return string
	 */
	public function get_name(): string
	{
		return 'group_memberships';
	}

	/**
	 * Human-readable step label
	 *
	 * @return string
	 */
	public function get_label(): string
	{
		return 'Group Memberships';
	}

	/**
	 * Step dependencies
	 *
	 * @return array
	 */
	public function get_dependencies(): array
	{
		return ['groups', 'users'];
	}

	/**
	 * Process a single batch of user memberships
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
		$result = new step_result_dto('group_memberships');
		$db = new xf_db_adapter($config);
		$prefix = $db->get_prefix();

		$cursor_id = (int)$cursor;
		$max_id = (int)$provider->get_max_source_id('users', $config);

		$sql = "SELECT 
					user_id,
					user_group_id,
					secondary_group_ids,
					is_admin,
					is_moderator
				FROM `{$prefix}user`
				WHERE user_id > :cursor
				ORDER BY user_id ASC
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

		$memberships = [];
		$last_id = $cursor_id;

		foreach ($rows as $row)
		{
			$source_id = (int)$row['user_id'];
			$last_id = $source_id;

			$secondary_ids = [];
			if (!empty($row['secondary_group_ids']))
			{
				$secondary_ids = array_filter(array_map('intval', explode(',', $row['secondary_group_ids'])));
			}

			$memberships[] = [
				'user_source_id'             => $source_id,
				'primary_group_source_id'    => (int)($row['user_group_id'] ?? 2),
				'secondary_group_source_ids' => $secondary_ids,
				'is_admin'                   => !empty($row['is_admin']),
				'is_moderator'               => !empty($row['is_moderator']),
			];
		}

		$result->next_cursor = $last_id;

		// Dry-Run handling
		if ($config->dry_run)
		{
			$result->imported_count = count($memberships);
			if (count($rows) < $batch_size || $last_id >= $max_id)
			{
				$result->is_completed = true;
			}
			return $result;
		}

		// Reconcile group memberships in target writer
		$write_options = [
			'run_id'        => $run_id,
			'source_system' => $config->source_system ?: 'xenforo',
		];

		$write_results = $writer->write_group_memberships($memberships, $write_options);

		foreach ($write_results as $src_id => $res)
		{
			if ($res['status'] === 'success')
			{
				$result->imported_count++;
			}
			else if ($res['status'] === 'skipped')
			{
				$result->skipped_count++;
			}
			else
			{
				$result->add_error(
					'MEMBERSHIP_RECONCILIATION_FAILED',
					"Membership reconciliation for user ID {$src_id} failed: " . ($res['error'] ?? 'Unknown error'),
					'error',
					$src_id
				);
			}
		}

		if (count($rows) < $batch_size || $last_id >= $max_id)
		{
			$result->is_completed = true;
		}

		return $result;
	}
}
