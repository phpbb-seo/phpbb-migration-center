<?php
/**
 * phpBB Migration Center Extension
 *
 * @copyright (c) 2026 phpBB SEO Team
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace phpbbseo\migrationcenter\core\dto;

/**
 * Normalized Poll Vote DTO
 */
class poll_vote_dto
{
	/** @var int Source poll ID (xf_poll_vote.poll_id) */
	public $poll_source_id;

	/** @var int Source user ID (xf_poll_vote.user_id) */
	public $user_source_id;

	/** @var int Source response ID (xf_poll_vote.response_id) */
	public $response_source_id;

	/** @var int Vote timestamp (xf_poll_vote.vote_date) */
	public $vote_date = 0;

	/** @var array Raw source record */
	public $raw_data = [];
}
