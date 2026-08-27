<?php
/**
 * phpBB Migration Center Extension
 *
 * @copyright (c) 2026 phpBB SEO Team
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace phpbbseo\migrationcenter\source\xenforo\normalizer;

use phpbbseo\migrationcenter\core\dto\poll_dto;
use phpbbseo\migrationcenter\core\dto\poll_option_dto;
use phpbbseo\migrationcenter\core\dto\poll_vote_dto;
use phpbbseo\migrationcenter\core\dto\migration_config_dto;

/**
 * XenForo Poll Normalizer
 */
class xf_poll_normalizer
{
	/**
	 * Normalize raw XenForo poll record, responses, and votes into poll_dto
	 *
	 * @param array $poll_row
	 * @param array $response_rows
	 * @param array $vote_rows
	 * @param migration_config_dto $config
	 * @return poll_dto
	 */
	public function normalize_poll(array $poll_row, array $response_rows, array $vote_rows, migration_config_dto $config): poll_dto
	{
		$dto = new poll_dto();
		$dto->source_id = (int)$poll_row['poll_id'];
		$dto->content_type = (string)($poll_row['content_type'] ?? 'thread');
		$dto->thread_source_id = (int)($poll_row['content_id'] ?? 0);
		$dto->question = trim((string)($poll_row['question'] ?? ''));
		if ($dto->question === '')
		{
			$dto->question = "Poll #{$dto->source_id}";
		}

		$dto->voter_count = max(0, (int)($poll_row['voter_count'] ?? 0));
		$dto->public_votes = !empty($poll_row['public_votes']);
		$dto->max_votes = max(1, (int)($poll_row['max_votes'] ?? 1));
		$dto->close_date = max(0, (int)($poll_row['close_date'] ?? 0));
		$dto->change_vote = !empty($poll_row['change_vote']);
		$dto->view_results_unvoted = !empty($poll_row['view_results_unvoted']);
		$dto->raw_data = $poll_row;

		// 1. Process Responses / Options (Preserve deterministic order)
		$order = 1;
		foreach ($response_rows as $r_row)
		{
			$opt = new poll_option_dto();
			$opt->source_id = (int)($r_row['poll_response_id'] ?? $r_row['response_id'] ?? 0);
			$opt->poll_source_id = $dto->source_id;
			$opt->option_text = trim((string)($r_row['response'] ?? ''));
			if ($opt->option_text === '')
			{
				$opt->option_text = "Option {$order}";
			}
			$opt->response_vote_count = max(0, (int)($r_row['response_vote_count'] ?? 0));
			$opt->option_order = $order++;
			$opt->raw_data = $r_row;

			$dto->responses[$opt->source_id] = $opt;
		}

		// Ensure max_votes does not exceed available options count
		$num_options = count($dto->responses);
		if ($num_options > 0 && $dto->max_votes > $num_options)
		{
			$dto->max_votes = $num_options;
		}

		// 2. Process Votes
		foreach ($vote_rows as $v_row)
		{
			$v = new poll_vote_dto();
			$v->poll_source_id = $dto->source_id;
			$v->user_source_id = (int)($v_row['user_id'] ?? 0);
			$v->response_source_id = (int)($v_row['poll_response_id'] ?? $v_row['response_id'] ?? 0);
			$v->vote_date = (int)($v_row['vote_date'] ?? time());
			$v->raw_data = $v_row;

			$dto->votes[] = $v;
		}

		return $dto;
	}
}
