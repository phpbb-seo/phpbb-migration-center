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
use phpbbseo\migrationcenter\source\mybb\normalizer\mybb_user_normalizer;

/**
 * MyBB Users Migration Step
 */
class users_step implements step_interface
{
	/** @var mybb_user_normalizer */
	protected $normalizer;

	public function __construct(?mybb_user_normalizer $normalizer = null)
	{
		$this->normalizer = $normalizer ?: new mybb_user_normalizer();
	}

	public function get_name(): string
	{
		return 'users';
	}

	public function get_label(): string
	{
		return 'Users';
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
		$result = new step_result_dto('users');
		$db = new mybb_db_adapter($config);

		$cursor_id = (int)$cursor;
		$tbl_user = $db->get_table_name('users');

		$sql = "SELECT * FROM {$tbl_user}
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

		$normalized_users = [];
		$max_cursor = $cursor_id;

		foreach ($rows as $row)
		{
			$uid = (int)$row['uid'];
			if ($uid > $max_cursor)
			{
				$max_cursor = $uid;
			}
			try
			{
				$normalized_users[] = $this->normalizer->normalize($row);
			}
			catch (\Throwable $e)
			{
				$result->add_error('USER_NORM_ERROR', "Error normalizing user {$uid}: " . $e->getMessage(), 'error', $uid);
			}
		}

		$result->processed_records = count($rows);
		$result->next_cursor = (string)$max_cursor;
		$result->current_cursor = (string)$cursor_id;

		if ($config->dry_run)
		{
			$result->imported_count = count($normalized_users);
			$result->imported_records = count($normalized_users);
			$max_id = (int)$provider->get_max_source_id('users', $config);
			if (count($rows) < $batch_size || $max_cursor >= $max_id)
			{
				$result->is_completed = true;
			}
			return $result;
		}

		$write_options = [
			'run_id'                    => $run_id,
			'source_system'             => 'mybb',
			'preserve_ids'              => $config->preserve_ids,
			'duplicate_username_policy' => $config->duplicate_username_policy ?: 'rename',
			'duplicate_email_policy'    => $config->duplicate_email_policy ?: 'keep',
		];

		$write_results = $writer->write_users($normalized_users, $write_options);

		$created = 0;
		$reused  = 0;
		$skipped = 0;
		$failed  = 0;

		foreach ($write_results as $res)
		{
			if (($res['status'] ?? '') === 'success')
			{
				if (!empty($res['reused']))
				{
					$reused++;
				}
				else
				{
					$created++;
				}
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

		$result->imported_count = $created + $reused;
		$result->imported_records = $result->imported_count;
		$result->skipped_count = $skipped;
		$result->failed_count = $failed;
		$result->metrics = [
			'created' => $created,
			'reused'  => $reused,
			'updated' => 0,
			'skipped' => $skipped,
			'failed'  => $failed,
		];

		$max_id = (int)$provider->get_max_source_id('users', $config);
		if (count($rows) < $batch_size || $max_cursor >= $max_id)
		{
			$result->is_completed = true;
		}

		return $result;
	}
}
