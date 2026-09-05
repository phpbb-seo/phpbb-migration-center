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
use phpbbseo\migrationcenter\core\dto\poll_dto;
use phpbbseo\migrationcenter\core\dto\poll_option_dto;
use phpbbseo\migrationcenter\core\dto\poll_vote_dto;
use phpbbseo\migrationcenter\core\dto\step_result_dto;
use phpbbseo\migrationcenter\source\mybb\adapter\mybb_db_adapter;

/**
 * MyBB Polls, Options and Votes Migration Step
 */
class polls_step implements step_interface
{
	public function get_name(): string
	{
		return 'polls';
	}

	public function get_label(): string
	{
		return 'Polls';
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
		$db = new mybb_db_adapter($config);

		$cursor_id = (int)$cursor;
		$tbl_poll = $db->get_table_name('polls');
		$tbl_vote = $db->get_table_name('pollvotes');

		if (!$db->table_exists('polls'))
		{
			$result->is_completed = true;
			return $result;
		}

		$sql = "SELECT pid, tid, question, dateline, options, votes, numoptions, numvotes, timeout, closed, multiple, public
				FROM {$tbl_poll}
				WHERE pid > {$cursor_id}
				ORDER BY pid ASC
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

		$poll_dtos = [];
		$max_cursor = $cursor_id;
		$has_votes_table = $db->table_exists('pollvotes');

		foreach ($rows as $row)
		{
			$pid = (int)$row['pid'];
			if ($pid > $max_cursor)
			{
				$max_cursor = $pid;
			}

			$thread_id = (int)($row['tid'] ?? 0);
			if ($thread_id <= 0)
			{
				continue;
			}

			$dto = new poll_dto();
			$dto->source_id = $pid;
			$dto->content_type = 'thread';
			$dto->thread_source_id = $thread_id;
			$dto->question = trim((string)$row['question']);
			$dto->start_date = (int)($row['dateline'] ?? time());
			$dto->voter_count = (int)($row['numvotes'] ?? 0);
			$dto->public_votes = !empty($row['public']);
			$dto->max_votes = !empty($row['multiple']) ? (int)($row['numoptions'] ?: 1) : 1;

			$timeout_days = (int)($row['timeout'] ?? 0);
			if ($timeout_days > 0)
			{
				$dto->close_date = $dto->start_date + ($timeout_days * 86400);
			}

			// Parse options (either split by ||~|~|| or newline)
			if (strpos((string)$row['options'], '||~|~||') !== false)
			{
				$raw_options = explode('||~|~||', (string)$row['options']);
			}
			else
			{
				$raw_options = explode("\n", str_replace("\r", "", (string)$row['options']));
			}

			$responses = [];
			$opt_idx = 1;

			foreach ($raw_options as $opt_text)
			{
				$opt_text = trim($opt_text);
				if ($opt_text === '')
				{
					continue;
				}

				$resp = new poll_option_dto();
				$resp->source_id = ($pid * 1000) + $opt_idx;
				$resp->poll_source_id = $pid;
				$resp->option_text = $opt_text;
				$resp->option_order = $opt_idx;
				$responses[$opt_idx] = $resp;
				$opt_idx++;
			}

			$dto->responses = array_values($responses);

			// Query votes
			if ($has_votes_table)
			{
				$vote_rows = $db->fetch_all("SELECT vid, pid, uid, voteoption, dateline FROM {$tbl_vote} WHERE pid = {$pid}");
				$votes = [];
				foreach ($vote_rows as $v_row)
				{
					$v_opt = (int)$v_row['voteoption'];
					$v_dto = new poll_vote_dto();
					$v_dto->poll_source_id = $pid;
					$v_dto->user_source_id = (int)$v_row['uid'];
					$v_dto->response_source_id = ($pid * 1000) + $v_opt;
					$v_dto->vote_date = (int)($v_row['dateline'] ?? $dto->start_date);
					$votes[] = $v_dto;
				}
				$dto->votes = $votes;
			}

			$poll_dtos[] = $dto;
		}

		$writer_res = $writer->write_polls($poll_dtos, [
			'run_id'        => $run_id,
			'source_system' => $config->source_system ?: 'mybb',
		]);

		$created = 0;
		$reused  = 0;
		$skipped = 0;
		$failed  = 0;

		foreach ($writer_res as $res)
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
		$result->skipped_count = $skipped;
		$result->failed_count = $failed;
		$result->metrics = [
			'created' => $created,
			'reused'  => $reused,
			'updated' => 0,
			'skipped' => $skipped,
			'failed'  => $failed,
		];
		$result->next_cursor = (string)$max_cursor;
		$result->current_cursor = (string)$max_cursor;

		$max_total_id = (int)$provider->get_max_source_id('polls', $config);
		if ($max_cursor >= $max_total_id || count($rows) < $batch_size)
		{
			$result->is_completed = true;
		}

		return $result;
	}
}
