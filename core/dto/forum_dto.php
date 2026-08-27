<?php
/**
 * phpBB Migration Center Extension
 *
 * @copyright (c) 2026 phpBB SEO Team
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace phpbbseo\migrationcenter\core\dto;

/**
 * Normalized Forum/Category DTO
 */
class forum_dto
{
	/** @var string|int Source node ID */
	public $source_id;

	/** @var string|int Source parent node ID (0 = root) */
	public $parent_source_id = 0;

	/** @var string Node type ('Category', 'Forum', 'LinkForum', 'Page', 'SearchForum') */
	public $node_type = 'Forum';

	/** @var string Forum/Category Title */
	public $forum_name = '';

	/** @var string Clean name for comparison */
	public $forum_name_clean = '';

	/** @var string Forum description */
	public $forum_desc = '';

	/** @var int phpBB forum_type: 0 = FORUM_CAT, 1 = FORUM_POST, 2 = FORUM_LINK */
	public $forum_type = 1;

	/** @var int phpBB forum_status: 0 = ITEM_UNLOCKED, 1 = ITEM_LOCKED */
	public $forum_status = 0;

	/** @var string External redirect URL if forum_type is FORUM_LINK */
	public $forum_link = '';

	/** @var int Display order / sequence */
	public $display_order = 0;

	/** @var int Display on board index (0 or 1) */
	public $display_on_index = 1;

	/** @var bool Whether posting is enabled in this forum */
	public $allow_posting = true;

	/** @var bool Whether posts in this forum increment user post counts */
	public $count_messages = true;

	/** @var int Total topics count */
	public $topics_count = 0;

	/** @var int Total posts count */
	public $posts_count = 0;

	/** @var int Nested set left ID */
	public $left_id = 0;

	/** @var int Nested set right ID */
	public $right_id = 0;

	/** @var array List of unsupported or reduced-fidelity features for this node */
	public $unsupported_features = [];

	/** @var array Raw source data for traceability */
	public $raw_source_data = [];
}
