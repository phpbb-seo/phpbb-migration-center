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
use phpbbseo\migrationcenter\source\vbulletin\normalizer\vb_group_normalizer;

/**
 * vBulletin 3.8 / 4.2 User Groups Migration Step
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
		$db = new vb_db_adapter($config);
		$tbl = $db->get_table_name('usergroup');

		$cursor_id = (int)$cursor;
		$sql = "SELECT * FROM `{$tbl}`
				WHERE usergroupid > :cursor
				ORDER BY usergroupid ASC
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
			$group_id = (int)$row['usergroupid'];
			$last_id = $group_id;

			$normalized_groups[] = vb_group_normalizer::normalize($row);
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
			'source_system' => $config->source_system ?: 'vbulletin',
		];

		$write_results = $writer->write_groups($normalized_groups, $write_options);

		$created_cnt = 0;
		$reused_cnt = 0;

		foreach ($write_results as $src_id => $res)
		{
			if ($res['status'] === 'success')
			{
				$result->imported_count++;
				if (!empty($res['builtin']) || !empty($res['reused']) || ($res['note'] ?? '') === 'already_mapped')
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
					"vBulletin Group ID {$src_id} write failed: " . ($res['error'] ?? 'Unknown error'),
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
