<?php
/**
 * phpBB Migration Center Extension
 *
 * @copyright (c) 2026 phpBB SEO Team
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace phpbbseo\migrationcenter\core\dto;

/**
 * Normalized Topic DTO
 */
class topic_dto
{
	/** @var string|int Source thread ID */
	public $source_id;

	/** @var string|int Source node/forum ID */
	public $forum_source_id;

	/** @var string|int Source author user ID */
	public $user_source_id;

	/** @var string Source author username */
	public $source_username = '';

	/** @var string Topic title (transformed with prefix if policy applied) */
	public $topic_title = '';

	/** @var string Original topic title before prefix prepending */
	public $original_title = '';

	/** @var int Source prefix ID */
	public $prefix_id = 0;

	/** @var string Resolved prefix title */
	public $prefix_title = '';

	/** @var int Creation timestamp */
	public $topic_time = 0;

	/** @var int Total view count */
	public $topic_views = 0;

	/** @var int Total replies count */
	public $reply_count = 0;

	/** @var int phpBB topic_type: 0 = POST_NORMAL, 1 = POST_STICKY */
	public $topic_type = 0;

	/** @var int phpBB topic_status: 0 = ITEM_UNLOCKED, 1 = ITEM_LOCKED */
	public $topic_status = 0;

	/** @var int phpBB topic_visibility: 1 = ITEM_APPROVED, 0 = ITEM_UNAPPROVED, 2 = ITEM_DELETED */
	public $topic_visibility = 1;

	/** @var string Source discussion_type ('discussion', 'poll', 'question', 'article', 'redirect') */
	public $discussion_type = 'discussion';

	/** @var string|int Source first post ID */
	public $first_post_source_id = 0;

	/** @var string|int Source last post ID */
	public $last_post_source_id = 0;

	/** @var int Last post timestamp */
	public $last_post_time = 0;

	/** @var string|int Source last poster user ID */
	public $last_post_source_user_id = 0;

	/** @var string Source last poster username */
	public $last_post_username = '';

	/** @var int Deletion timestamp (for soft-deleted threads) */
	public $delete_time = 0;

	/** @var string|int Deleting user source ID */
	public $delete_user_source_id = 0;

	/** @var string Deleting username */
	public $delete_username = '';

	/** @var string Deletion reason */
	public $delete_reason = '';

	/** @var array List of unsupported or reduced-fidelity features for this topic */
	public $unsupported_features = [];

	/** @var array Raw source record */
	public $raw_source_data = [];
}
