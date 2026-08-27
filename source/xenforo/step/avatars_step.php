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
use phpbbseo\migrationcenter\source\xenforo\normalizer\xf_avatar_normalizer;

/**
 * XenForo Avatars Migration Step
 */
class avatars_step implements step_interface
{
	/** @var xf_avatar_normalizer */
	protected $normalizer;

	/**
	 * Constructor
	 *
	 * @param xf_avatar_normalizer|null $normalizer
	 */
	public function __construct(?xf_avatar_normalizer $normalizer = null)
	{
		$this->normalizer = $normalizer ?: new xf_avatar_normalizer();
	}

	/**
	 * Unique step identifier
	 *
	 * @return string
	 */
	public function get_name(): string
	{
		return 'avatars';
	}

	/**
	 * Human-readable step label
	 *
	 * @return string
	 */
	public function get_label(): string
	{
		return 'Avatars';
	}

	/**
	 * Step dependencies
	 *
	 * @return array
	 */
	public function get_dependencies(): array
	{
		return ['users'];
	}

	/**
	 * Process avatars batch using keyset pagination
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
		$result = new step_result_dto('avatars');
		$db = new xf_db_adapter($config);
		$prefix = $db->get_prefix();

		$current_cursor = (int)$cursor;
		if ($current_cursor === 0 && !empty($config->min_id))
		{
			$current_cursor = (int)$config->min_id - 1;
		}

		$limit = $batch_size > 0 ? $batch_size : 200;

		$max_id_clause = '';
		$params = [
			':cursor' => $current_cursor,
		];

		if ($config->max_id !== null && $config->max_id > 0)
		{
			$max_id_clause = ' AND u.user_id <= :max_id ';
			$params[':max_id'] = (int)$config->max_id;
		}

		$sql = "SELECT 
					u.user_id,
					u.avatar_date,
					u.avatar_width,
					u.avatar_height,
					u.avatar_highdpi,
					u.gravatar
				FROM `{$prefix}user` u
				WHERE u.user_id > :cursor 
					AND (u.avatar_date > 0 OR (u.gravatar IS NOT NULL AND u.gravatar != ''))
					{$max_id_clause}
				ORDER BY u.user_id ASC
				LIMIT {$limit}";

		$rows = $db->fetch_all($sql, $params);

		if (empty($rows))
		{
			$result->is_completed = true;
			$result->next_cursor = (string)$current_cursor;
			return $result;
		}

		$dtos = [];
		$max_seen_id = $current_cursor;

		foreach ($rows as $row)
		{
			$uid = (int)$row['user_id'];
			if ($uid > $max_seen_id)
			{
				$max_seen_id = $uid;
			}

			$dto = $this->normalizer->normalize_avatar($row, $config);
			$dtos[] = $dto;
		}

		$result->items_total = count($dtos);
		$result->items_processed = count($dtos);

		if ($config->dry_run)
		{
			foreach ($dtos as $d)
			{
				if (!empty($d->warnings))
				{
					$result->items_skipped++;
					foreach ($d->warnings as $w)
					{
						$result->warnings[] = "Dry-run User {$d->user_source_id}: {$w}";
					}
				}
				else
				{
					$result->items_imported++;
				}
			}
		}
		else
		{
			$write_results = $writer->write_avatars($dtos, [
				'run_id'        => $run_id,
				'source_system' => $config->source_system ?: 'xenforo',
			]);

			foreach ($dtos as $d)
			{
				$sid = $d->user_source_id;
				$res = $write_results[$sid] ?? null;

				if ($res && $res['status'] === 'success')
				{
					$result->items_imported++;
				}
				else if ($res && $res['status'] === 'skipped')
				{
					$result->items_skipped++;
					if (!empty($res['error']))
					{
						$result->warnings[] = "User {$sid} avatar skipped: {$res['error']}";
					}
				}
				else
				{
					$result->items_failed++;
					$err = $res['error'] ?? 'Unknown error';
					$result->errors[] = "User {$sid} avatar failed: {$err}";
				}
			}
		}

		$result->next_cursor = (string)$max_seen_id;
		if (count($rows) < $limit)
		{
			$result->is_completed = true;
		}

		return $result;
	}
}
