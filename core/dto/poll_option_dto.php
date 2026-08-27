<?php
/**
 * phpBB Migration Center Extension
 *
 * @copyright (c) 2026 phpBB SEO Team
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace phpbbseo\migrationcenter\core\dto;

/**
 * Normalized Poll Option DTO
 */
class poll_option_dto
{
	/** @var int Source response ID (xf_poll_response.response_id) */
	public $source_id;

	/** @var int Source poll ID (xf_poll_response.poll_id) */
	public $poll_source_id;

	/** @var string Option text (xf_poll_response.response) */
	public $option_text = '';

	/** @var int Cached vote count (xf_poll_response.response_vote_count) */
	public $response_vote_count = 0;

	/** @var int Option order index (1-indexed for phpBB) */
	public $option_order = 1;

	/** @var array Raw source record */
	public $raw_data = [];
}
