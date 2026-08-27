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
use phpbbseo\migrationcenter\source\xenforo\conversation\xf_conversation_message_normalizer;

/**
 * XenForo Conversation Messages Migration Step
 */
class conversation_messages_step implements step_interface
{
	/** @var xf_conversation_message_normalizer */
	protected $normalizer;

	/**
	 * Constructor
	 *
	 * @param xf_conversation_message_normalizer|null $normalizer
	 */
	public function __construct(?xf_conversation_message_normalizer $normalizer = null)
	{
		$this->normalizer = $normalizer ?: new xf_conversation_message_normalizer();
	}

	public function get_name(): string
	{
		return 'conversation_messages';
	}

	public function get_label(): string
	{
		return 'Conversation Messages';
	}

	public function get_dependencies(): array
	{
		return ['conversations', 'users'];
	}

	public function process_batch(
		string $run_id,
		$cursor,
		int $batch_size,
		migration_config_dto $config,
		source_provider_interface $provider,
		target_writer_interface $writer
	): step_result_dto {
		$result = new step_result_dto('conversation_messages');
		$db = new xf_db_adapter($config);
		$prefix = $db->get_prefix();

		$current_cursor = (int)$cursor;
		if ($current_cursor === 0 && !empty($config->min_id))
		{
			$current_cursor = (int)$config->min_id - 1;
		}

		$limit = $batch_size > 0 ? $batch_size : 300;

		$max_id_clause = '';
		$params = [
			':cursor' => $current_cursor,
		];

		if ($config->max_id !== null && $config->max_id > 0)
		{
			$max_id_clause = ' AND m.message_id <= :max_id ';
			$params[':max_id'] = (int)$config->max_id;
		}

		// Keyset pagination on message_id ASC (ensures deterministic chronologic order)
		$sql = "SELECT 
					m.message_id,
					m.conversation_id,
					m.message_date,
					m.user_id,
					m.username,
					m.message,
					m.attach_count,
					m.ip_id
				FROM `{$prefix}conversation_message` m
				WHERE m.message_id > :cursor
					{$max_id_clause}
				ORDER BY m.message_id ASC
				LIMIT {$limit}";

		$rows = $db->fetch_all($sql, $params);

		if (empty($rows))
		{
			$result->is_completed = true;
			$result->next_cursor = (string)$current_cursor;
			return $result;
		}

		// Resolve IP addresses if ip_id is present
		$ip_ids = [];
		foreach ($rows as $r)
		{
			if (!empty($r['ip_id']))
			{
				$ip_ids[] = (int)$r['ip_id'];
			}
		}

		$ip_map = [];
		if (!empty($ip_ids))
		{
			$in_ips = implode(',', array_unique($ip_ids));
			$ip_rows = $db->fetch_all("SELECT ip_id, ip FROM `{$prefix}ip` WHERE ip_id IN ({$in_ips})");
			foreach ($ip_rows as $ir)
			{
				$ip_raw = $ir['ip'];
				$ip_formatted = @inet_ntop($ip_raw);
				if ($ip_formatted !== false)
				{
					$ip_map[(int)$ir['ip_id']] = $ip_formatted;
				}
			}
		}

		$dtos = [];
		$max_seen_id = $current_cursor;

		foreach ($rows as $row)
		{
			$mid = (int)$row['message_id'];
			if ($mid > $max_seen_id)
			{
				$max_seen_id = $mid;
			}

			$ip_str = !empty($row['ip_id']) && isset($ip_map[(int)$row['ip_id']])
				? $ip_map[(int)$row['ip_id']]
				: '127.0.0.1';

			$dto = $this->normalizer->normalize_message($row, $config, $ip_str);
			$dtos[] = $dto;
		}

		$result->items_total = count($dtos);
		$result->items_processed = count($dtos);

		if ($config->dry_run)
		{
			$result->items_imported = count($dtos);
		}
		else
		{
			$write_results = $writer->write_privmsgs($dtos, [
				'run_id'        => $run_id,
				'source_system' => $config->source_system ?: 'xenforo',
			]);

			foreach ($dtos as $d)
			{
				$mid = $d->source_id;
				$res = $write_results[$mid] ?? null;

				if ($res && $res['status'] === 'success')
				{
					$result->items_imported++;
				}
				else if ($res && $res['status'] === 'skipped')
				{
					$result->items_skipped++;
					if (!empty($res['error']))
					{
						$result->warnings[] = "Conversation message {$mid} skipped: {$res['error']}";
					}
				}
				else
				{
					$result->items_failed++;
					$err = $res['error'] ?? 'Unknown error';
					$result->errors[] = "Conversation message {$mid} failed: {$err}";
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
