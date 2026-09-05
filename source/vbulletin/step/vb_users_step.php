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
use phpbbseo\migrationcenter\source\vbulletin\normalizer\vb_user_normalizer;

/**
 * vBulletin 3.8 / 4.2 Users Migration Step
 */
class vb_users_step implements step_interface
{
	/** @var vb_user_normalizer */
	protected $normalizer;

	/**
	 * Constructor
	 *
	 * @param vb_user_normalizer|null $normalizer
	 */
	public function __construct(?vb_user_normalizer $normalizer = null)
	{
		$this->normalizer = $normalizer ?: new vb_user_normalizer();
	}

	/**
	 * Unique step identifier
	 *
	 * @return string
	 */
	public function get_name(): string
	{
		return 'users';
	}

	/**
	 * Human-readable step label
	 *
	 * @return string
	 */
	public function get_label(): string
	{
		return 'Users';
	}

	/**
	 * Step dependencies
	 *
	 * @return array
	 */
	public function get_dependencies(): array
	{
		return ['groups'];
	}

	/**
	 * Execute a single batch for users
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
		$result = new step_result_dto('users');
		$db = new vb_db_adapter($config);

		$cursor_id = (int)$cursor;
		$max_id = (int)$provider->get_max_source_id('users', $config);

		$tbl_user = $db->get_table_name('user');
		$tbl_usertext = $db->get_table_name('usertextfield');

		// Keyset pagination: WHERE u.userid > :cursor ORDER BY u.userid ASC LIMIT :limit
		$sql = "SELECT u.*, ut.signature
				FROM {$tbl_user} u
				LEFT JOIN {$tbl_usertext} ut ON u.userid = ut.userid
				WHERE u.userid > {$cursor_id}
				ORDER BY u.userid ASC
				LIMIT {$batch_size}";

		$rows = $db->fetch_all($sql);

		if (empty($rows))
		{
			$result->next_cursor = (string)$cursor_id;
			$result->current_cursor = (string)$cursor_id;
			$result->is_completed = true;
			return $result;
		}

		$normalized_users = [];
		$last_id = $cursor_id;
		$norm_failed = 0;

		foreach ($rows as $row)
		{
			$last_id = (int)$row['userid'];
			try
			{
				$normalized_users[] = $this->normalizer->normalize($row);
			}
			catch (\Throwable $e)
			{
				$norm_failed++;
				$result->add_error('USER_NORM_ERROR', "Normalize error user {$row['userid']}: " . $e->getMessage(), 'error', (int)$row['userid']);
			}
		}

		$result->read_count = count($rows);
		$result->processed_records = count($rows);
		$result->next_cursor = (string)$last_id;
		$result->current_cursor = (string)$last_id;

		// Dry-Run Handling
		if ($config->dry_run)
		{
			$result->imported_count = count($normalized_users);
			$result->imported_records = count($normalized_users);
			if (count($rows) < $batch_size || $last_id >= $max_id)
			{
				$result->is_completed = true;
			}
			return $result;
		}

		$write_options = [
			'run_id'                    => $run_id,
			'source_system'             => $config->source_system ?: 'vbulletin',
			'preserve_ids'              => $config->preserve_ids,
			'duplicate_username_policy' => $config->duplicate_username_policy ?: 'rename',
			'duplicate_email_policy'    => $config->duplicate_email_policy ?: 'keep',
		];

		$write_results = $writer->write_users($normalized_users, $write_options);

		$created_count = 0;
		$reused_count  = 0;
		$skipped_count = 0;
		$failed_count  = $norm_failed;

		foreach ($write_results as $src_id => $write_res)
		{
			if ($write_res['status'] === 'success')
			{
				if (!empty($write_res['note']) && in_array($write_res['note'], ['already_mapped', 'merged', 'reused'], true))
				{
					$reused_count++;
				}
				else
				{
					$created_count++;
				}
			}
			else if ($write_res['status'] === 'skipped')
			{
				$skipped_count++;
			}
			else
			{
				$failed_count++;
				$result->add_error('USER_WRITE_ERROR', "User {$src_id} write failed: " . ($write_res['error'] ?? 'Unknown error'), 'error', (int)$src_id);
			}
		}

		$result->imported_count = $created_count + $reused_count;
		$result->imported_records = $created_count;
		$result->reused_records = $reused_count;
		$result->skipped_count = $skipped_count;
		$result->skipped_records = $skipped_count;
		$result->failed_count = $failed_count;
		$result->failed_records = $failed_count;
		$result->metrics = [
			'created' => $created_count,
			'reused'  => $reused_count,
		];

		if ($last_id >= $max_id || count($rows) < $batch_size)
		{
			$result->is_completed = true;
		}

		return $result;
	}
}
