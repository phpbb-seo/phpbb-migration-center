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
use phpbbseo\migrationcenter\source\xenforo\normalizer\xf_poll_normalizer;

/**
 * XenForo Thread Polls Migration Step
 */
class polls_step implements step_interface
{
	/** @var xf_poll_normalizer */
	protected $normalizer;

	/**
	 * Constructor
	 *
	 * @param xf_poll_normalizer|null $normalizer
	 */
	public function __construct(?xf_poll_normalizer $normalizer = null)
	{
		$this->normalizer = $normalizer ?: new xf_poll_normalizer();
	}

	public function get_name(): string
	{
		return 'polls';
	}

	public function get_label(): string
	{
		return 'Thread Polls';
	}

	public function get_dependencies(): array
	{
		return ['topics', 'users'];
	}

	public function process_batch(
		string $run_id,
		$cursor,
		int $batch_size,
		migration_config_dto $config,
		source_provider_interface $provider,
		target_writer_interface $writer
	): step_result_dto {
		$result = new step_result_dto('polls');
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
			$max_id_clause = ' AND poll_id <= :max_id ';
			$params[':max_id'] = (int)$config->max_id;
		}

		// Fetch polls for threads
		$sql = "SELECT 
					poll_id,
					content_type,
					content_id,
					question,
					responses,
					voter_count,
					public_votes,
					max_votes,
					close_date,
					change_vote,
					view_results_unvoted
				FROM `{$prefix}poll`
				WHERE poll_id > :cursor 
					AND content_type = 'thread'
					{$max_id_clause}
				ORDER BY poll_id ASC
				LIMIT {$limit}";

		$poll_rows = $db->fetch_all($sql, $params);

		if (empty($poll_rows))
		{
			$result->is_completed = true;
			$result->next_cursor = (string)$current_cursor;
			return $result;
		}

		$poll_ids = [];
		foreach ($poll_rows as $pr)
		{
			$poll_ids[] = (int)$pr['poll_id'];
		}

		// Fetch responses for this batch
		$in_poll_ids = implode(',', $poll_ids);
		$resp_sql = "SELECT poll_response_id, poll_id, response, response_vote_count 
					 FROM `{$prefix}poll_response` 
					 WHERE poll_id IN ({$in_poll_ids}) 
					 ORDER BY poll_id ASC, poll_response_id ASC";
		$resp_rows = $db->fetch_all($resp_sql);

		$responses_by_poll = [];
		foreach ($resp_rows as $rr)
		{
			$responses_by_poll[(int)$rr['poll_id']][] = $rr;
		}

		// Fetch votes for this batch
		$votes_sql = "SELECT poll_id, user_id, poll_response_id, vote_date 
					  FROM `{$prefix}poll_vote` 
					  WHERE poll_id IN ({$in_poll_ids}) 
					  ORDER BY poll_id ASC, vote_date ASC";
		$vote_rows = $db->fetch_all($votes_sql);

		$votes_by_poll = [];
		foreach ($vote_rows as $vr)
		{
			$votes_by_poll[(int)$vr['poll_id']][] = $vr;
		}

		// Normalize DTOs
		$dtos = [];
		$max_seen_id = $current_cursor;

		foreach ($poll_rows as $pr)
		{
			$pid = (int)$pr['poll_id'];
			if ($pid > $max_seen_id)
			{
				$max_seen_id = $pid;
			}

			$p_resps = $responses_by_poll[$pid] ?? [];
			$p_votes = $votes_by_poll[$pid] ?? [];

			$dto = $this->normalizer->normalize_poll($pr, $p_resps, $p_votes, $config);
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
			$write_results = $writer->write_polls($dtos, [
				'run_id'        => $run_id,
				'source_system' => $config->source_system ?: 'xenforo',
			]);

			foreach ($dtos as $d)
			{
				$pid = $d->source_id;
				$res = $write_results[$pid] ?? null;

				if ($res && $res['status'] === 'success')
				{
					$result->items_imported++;
				}
				else if ($res && $res['status'] === 'skipped')
				{
					$result->items_skipped++;
					if (!empty($res['error']))
					{
						$result->warnings[] = "Poll {$pid} skipped: {$res['error']}";
					}
				}
				else
				{
					$result->items_failed++;
					$err = $res['error'] ?? 'Unknown error';
					$result->errors[] = "Poll {$pid} failed: {$err}";
				}
			}
		}

		$result->next_cursor = (string)$max_seen_id;
		if (count($poll_rows) < $limit)
		{
			$result->is_completed = true;
		}

		return $result;
	}
}
