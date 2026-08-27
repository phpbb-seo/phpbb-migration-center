<?php
/**
 * phpBB Migration Center Extension
 *
 * @copyright (c) 2026 phpBB SEO Team
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace phpbbseo\migrationcenter\core\dto;

/**
 * Normalized Poll DTO
 */
class poll_dto
{
	/** @var int Source poll ID (xf_poll.poll_id) */
	public $source_id;

	/** @var string Content type (e.g. 'thread') */
	public $content_type = 'thread';

	/** @var int Source thread ID (xf_poll.content_id) */
	public $thread_source_id;

	/** @var string Poll question / title */
	public $question = '';

	/** @var poll_option_dto[] Array of poll options/responses */
	public $responses = [];

	/** @var poll_vote_dto[] Array of poll votes */
	public $votes = [];

	/** @var int Cached voter count */
	public $voter_count = 0;

	/** @var bool Public votes flag */
	public $public_votes = false;

	/** @var int Maximum options selectable (1 = single-choice, >1 = multiple-choice) */
	public $max_votes = 1;

	/** @var int Poll close timestamp (0 = no close date / permanent) */
	public $close_date = 0;

	/** @var bool Allow vote change flag */
	public $change_vote = false;

	/** @var bool View results without voting flag */
	public $view_results_unvoted = false;

	/** @var int Poll start timestamp */
	public $start_date = 0;

	/** @var array Raw source database record */
	public $raw_data = [];
}
