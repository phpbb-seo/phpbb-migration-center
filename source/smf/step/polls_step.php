<?php
/**
 * phpBB Migration Center Extension
 *
 * @copyright (c) 2026 phpBB SEO Team
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace phpbbseo\migrationcenter\source\smf\step;

use phpbbseo\migrationcenter\core\contract\step_interface;
use phpbbseo\migrationcenter\core\contract\source_provider_interface;
use phpbbseo\migrationcenter\core\contract\target_writer_interface;
use phpbbseo\migrationcenter\core\dto\migration_config_dto;
use phpbbseo\migrationcenter\core\dto\poll_dto;
use phpbbseo\migrationcenter\core\dto\poll_option_dto;
use phpbbseo\migrationcenter\core\dto\poll_vote_dto;
use phpbbseo\migrationcenter\core\dto\step_result_dto;
use phpbbseo\migrationcenter\source\smf\adapter\smf_db_adapter;

/**
 * SMF 2.x Polls, Options and Votes Migration Step
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
		$db = new smf_db_adapter($config);

		$cursor_id = (int)$cursor;
		$tbl_poll = $db->get_table_name('polls');
		$tbl_choice = $db->get_table_name('poll_choices');
		$tbl_log = $db->get_table_name('log_polls');
		$tbl_topics = $db->get_table_name('topics');
		$tbl_messages = $db->get_table_name('messages');

		if (!$db->table_exists('polls'))
		{
			$result->is_completed = true;
			return $result;
		}

		$sql = "SELECT p.id_poll, p.question, p.voting_locked, p.max_votes, p.expire_time, p.hide_results,
				       t.id_topic, m.poster_time AS poll_start_date
				FROM {$tbl_poll} p
				INNER JOIN {$tbl_topics} t ON t.id_poll = p.id_poll
				LEFT JOIN {$tbl_messages} m ON m.id_msg = t.id_first_msg
				WHERE p.id_poll > {$cursor_id}
				ORDER BY p.id_poll ASC
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

		$poll_ids = array_map(function ($r) {
			return (int)$r['id_poll'];
		}, $rows);
		$in_poll_ids = implode(',', $poll_ids);

		// Fetch choices
		$choices_by_poll = [];
		if ($db->table_exists('poll_choices'))
		{
			$choice_rows = $db->fetch_all("SELECT id_poll, id_choice, label, votes FROM {$tbl_choice} WHERE id_poll IN ({$in_poll_ids}) ORDER BY id_choice ASC");
			foreach ($choice_rows as $c_row)
			{
				$pid = (int)$c_row['id_poll'];
				if (!isset($choices_by_poll[$pid]))
				{
					$choices_by_poll[$pid] = [];
				}
				$choices_by_poll[$pid][] = $c_row;
			}
		}

		// Fetch votes
		$votes_by_poll = [];
		if ($db->table_exists('log_polls'))
		{
			$vote_rows = $db->fetch_all("SELECT id_poll, id_member, id_choice FROM {$tbl_log} WHERE id_poll IN ({$in_poll_ids})");
			foreach ($vote_rows as $v_row)
			{
				$pid = (int)$v_row['id_poll'];
				if (!isset($votes_by_poll[$pid]))
				{
					$votes_by_poll[$pid] = [];
				}
				$votes_by_poll[$pid][] = $v_row;
			}
		}

		$poll_dtos = [];
		$max_cursor = $cursor_id;

		foreach ($rows as $row)
		{
			$pid = (int)$row['id_poll'];
			if ($pid > $max_cursor)
			{
				$max_cursor = $pid;
			}

			$thread_id = (int)($row['id_topic'] ?? 0);
			if ($thread_id <= 0)
			{
				continue;
			}

			$dto = new poll_dto();
			$dto->source_id = $pid;
			$dto->content_type = 'thread';
			$dto->thread_source_id = $thread_id;
			$dto->question = trim((string)$row['question']);
			$dto->start_date = (int)($row['poll_start_date'] ?? time());
			$dto->public_votes = empty($row['hide_results']);
			$dto->max_votes = max(1, (int)($row['max_votes'] ?? 1));

			$expire_time = (int)($row['expire_time'] ?? 0);
			if ($expire_time > 0)
			{
				$dto->close_date = $expire_time;
			}

			$responses = [];
			$raw_choices = $choices_by_poll[$pid] ?? [];
			$opt_idx = 1;

			foreach ($raw_choices as $c_row)
			{
				$opt_text = trim((string)$c_row['label']);
				if ($opt_text === '')
				{
					continue;
				}

				$choice_num = (int)$c_row['id_choice'];
				$resp = new poll_option_dto();
				$resp->source_id = ($pid * 1000) + ($choice_num + 1);
				$resp->poll_source_id = $pid;
				$resp->option_text = $opt_text;
				$resp->option_order = $opt_idx++;
				$responses[$choice_num] = $resp;
			}

			$dto->responses = array_values($responses);

			// Map votes
			$raw_votes = $votes_by_poll[$pid] ?? [];
			$votes = [];
			$unique_voters = [];

			foreach ($raw_votes as $v_row)
			{
				$member_id = (int)$v_row['id_member'];
				$choice_num = (int)$v_row['id_choice'];

				$v_dto = new poll_vote_dto();
				$v_dto->poll_source_id = $pid;
				$v_dto->user_source_id = $member_id;
				$v_dto->response_source_id = ($pid * 1000) + ($choice_num + 1);
				$v_dto->vote_date = $dto->start_date;
				$votes[] = $v_dto;

				if ($member_id > 0)
				{
					$unique_voters[$member_id] = true;
				}
			}

			$dto->votes = $votes;
			$dto->voter_count = count($unique_voters);

			$poll_dtos[] = $dto;
		}

		$writer_res = $writer->write_polls($poll_dtos, [
			'run_id'        => $run_id,
			'source_system' => $config->source_system ?: 'smf',
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
