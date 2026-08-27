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
use phpbbseo\migrationcenter\source\xenforo\conversation\xf_conversation_normalizer;

/**
 * XenForo Conversations Metadata & Participant Step
 */
class conversations_step implements step_interface
{
	/** @var xf_conversation_normalizer */
	protected $normalizer;

	/**
	 * Constructor
	 *
	 * @param xf_conversation_normalizer|null $normalizer
	 */
	public function __construct(?xf_conversation_normalizer $normalizer = null)
	{
		$this->normalizer = $normalizer ?: new xf_conversation_normalizer();
	}

	public function get_name(): string
	{
		return 'conversations';
	}

	public function get_label(): string
	{
		return 'Conversations';
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
		$result = new step_result_dto('conversations');
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
			$max_id_clause = ' AND c.conversation_id <= :max_id ';
			$params[':max_id'] = (int)$config->max_id;
		}

		// 1. Fetch conversation master batch
		$sql = "SELECT 
					c.conversation_id,
					c.title,
					c.user_id,
					c.username,
					c.start_date,
					c.open_invite,
					c.conversation_open,
					c.reply_count,
					c.recipient_count,
					c.first_message_id,
					c.last_message_date,
					c.last_message_id,
					c.last_message_user_id
				FROM `{$prefix}conversation_master` c
				WHERE c.conversation_id > :cursor
					{$max_id_clause}
				ORDER BY c.conversation_id ASC
				LIMIT {$limit}";

		$rows = $db->fetch_all($sql, $params);

		if (empty($rows))
		{
			$result->is_completed = true;
			$result->next_cursor = (string)$current_cursor;
			return $result;
		}

		$conv_ids = array_map(function ($r) {
			return (int)$r['conversation_id'];
		}, $rows);

		// 2. Fetch recipients and per-user state for this batch
		$in_clause = implode(',', $conv_ids);

		$recip_rows = $db->fetch_all("SELECT conversation_id, user_id, recipient_state, last_read_date 
									  FROM `{$prefix}conversation_recipient` 
									  WHERE conversation_id IN ({$in_clause})");
		$recips_by_conv = [];
		foreach ($recip_rows as $rr)
		{
			$recips_by_conv[(int)$rr['conversation_id']][] = $rr;
		}

		$user_rows = $db->fetch_all("SELECT conversation_id, owner_user_id, is_unread, is_starred 
									 FROM `{$prefix}conversation_user` 
									 WHERE conversation_id IN ({$in_clause})");
		$users_by_conv = [];
		foreach ($user_rows as $ur)
		{
			$users_by_conv[(int)$ur['conversation_id']][(int)$ur['owner_user_id']] = $ur;
		}

		$dtos = [];
		$max_seen_id = $current_cursor;

		foreach ($rows as $row)
		{
			$cid = (int)$row['conversation_id'];
			if ($cid > $max_seen_id)
			{
				$max_seen_id = $cid;
			}

			$r_list = $recips_by_conv[$cid] ?? [];
			$u_list = $users_by_conv[$cid] ?? [];

			$dto = $this->normalizer->normalize_conversation($row, $r_list, $u_list, $config);
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
			$write_results = $writer->write_conversations($dtos, [
				'run_id'        => $run_id,
				'source_system' => $config->source_system ?: 'xenforo',
			]);

			foreach ($dtos as $d)
			{
				$cid = $d->source_id;
				$res = $write_results[$cid] ?? null;

				if ($res && $res['status'] === 'success')
				{
					$result->items_imported++;
				}
				else if ($res && $res['status'] === 'skipped')
				{
					$result->items_skipped++;
					if (!empty($res['error']))
					{
						$result->warnings[] = "Conversation {$cid} skipped: {$res['error']}";
					}
				}
				else
				{
					$result->items_failed++;
					$err = $res['error'] ?? 'Unknown error';
					$result->errors[] = "Conversation {$cid} failed: {$err}";
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
