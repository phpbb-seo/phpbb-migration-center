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
use phpbbseo\migrationcenter\source\xenforo\normalizer\xf_ban_normalizer;

/**
 * XenForo Bans (User, Email, IP) Migration Step
 */
class bans_step implements step_interface
{
	/** @var xf_ban_normalizer */
	protected $normalizer;

	/**
	 * Constructor
	 *
	 * @param xf_ban_normalizer|null $normalizer
	 */
	public function __construct(?xf_ban_normalizer $normalizer = null)
	{
		$this->normalizer = $normalizer ?: new xf_ban_normalizer();
	}

	public function get_name(): string
	{
		return 'bans';
	}

	public function get_label(): string
	{
		return 'User, Email & IP Bans';
	}

	public function get_dependencies(): array
	{
		return ['users'];
	}

	public function process_batch(
		string $run_id,
		$cursor,
		int $batch_size,
		migration_config_dto $config,
		source_provider_interface $provider,
		target_writer_interface $writer
	): step_result_dto {
		$result = new step_result_dto('bans');
		$db = new xf_db_adapter($config);
		$prefix = $db->get_prefix();

		$current_cursor = (int)$cursor;
		if ($current_cursor === 0 && !empty($config->min_id))
		{
			$current_cursor = (int)$config->min_id - 1;
		}

		$limit = $batch_size > 0 ? $batch_size : 200;
		$max_seen_id = $current_cursor;
		$dtos = [];

		// 1. Fetch user bans (Keyset paginated by user_id)
		$max_id_clause = '';
		$params = [':cursor' => $current_cursor];
		if ($config->max_id !== null && $config->max_id > 0)
		{
			$max_id_clause = ' AND user_id <= :max_id ';
			$params[':max_id'] = (int)$config->max_id;
		}

		$user_ban_sql = "SELECT user_id, ban_user_id, ban_date, end_date, user_reason, triggered 
						 FROM `{$prefix}user_ban` 
						 WHERE user_id > :cursor 
						 {$max_id_clause} 
						 ORDER BY user_id ASC 
						 LIMIT {$limit}";
		$user_ban_rows = $db->fetch_all($user_ban_sql, $params);

		foreach ($user_ban_rows as $ub)
		{
			$uid = (int)$ub['user_id'];
			if ($uid > $max_seen_id)
			{
				$max_seen_id = $uid;
			}
			$dtos[] = $this->normalizer->normalize_user_ban($ub);
		}

		// 2. If cursor is 0 (first batch), also fetch email and IP bans
		if ($current_cursor === 0)
		{
			// Email bans
			try
			{
				$email_ban_sql = "SELECT banned_email, create_date, reason FROM `{$prefix}ban_email` ORDER BY banned_email ASC";
				$email_rows = $db->fetch_all($email_ban_sql);
				foreach ($email_rows as $er)
				{
					$dtos[] = $this->normalizer->normalize_email_ban($er);
				}
			}
			catch (\Throwable $e)
			{
				// Table might not exist in older versions
			}

			// IP bans (banned match_type only)
			try
			{
				$ip_ban_sql = "SELECT ip, match_type, create_date, reason, last_triggered_date 
							   FROM `{$prefix}ip_match` 
							   WHERE match_type = 'banned' 
							   ORDER BY ip ASC";
				$ip_rows = $db->fetch_all($ip_ban_sql);
				foreach ($ip_rows as $ir)
				{
					$dtos[] = $this->normalizer->normalize_ip_ban($ir);
				}
			}
			catch (\Throwable $e)
			{
				// Table might not exist
			}
		}

		$result->items_total = count($dtos);
		$result->items_processed = count($dtos);

		if ($config->dry_run)
		{
			$result->items_imported = count($dtos);
		}
		else
		{
			$write_results = $writer->write_bans($dtos, [
				'run_id'               => $run_id,
				'source_system'        => $config->source_system ?: 'xenforo',
				'expired_ban_policy'   => (string)($config->get_option('expired_ban_policy', 'skip')),
				'existing_user_policy' => (string)($config->get_option('existing_user_policy', 'preserve_target')),
			]);

			foreach ($dtos as $d)
			{
				$bid = $d->source_id;
				$res = $write_results[$bid] ?? null;

				if ($res && $res['status'] === 'success')
				{
					$result->items_imported++;
				}
				else if ($res && $res['status'] === 'skipped')
				{
					$result->items_skipped++;
					if (!empty($res['error']))
					{
						$result->warnings[] = "Ban {$bid} skipped: {$res['error']}";
					}
				}
				else
				{
					$result->items_failed++;
					$err = $res['error'] ?? 'Unknown error';
					$result->errors[] = "Ban {$bid} failed: {$err}";
				}
			}
		}

		$result->next_cursor = (string)$max_seen_id;
		if (count($user_ban_rows) < $limit)
		{
			$result->is_completed = true;
		}

		return $result;
	}
}
