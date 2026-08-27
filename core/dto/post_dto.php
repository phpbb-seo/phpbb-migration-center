<?php
/**
 * phpBB Migration Center Extension
 *
 * @copyright (c) 2026 phpBB SEO Team
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace phpbbseo\migrationcenter\core\dto;

/**
 * Normalized Post DTO
 */
class post_dto
{
	/** @var string|int Source post ID */
	public $source_id;

	/** @var string|int Source thread/topic ID */
	public $topic_source_id;

	/** @var string|int Source node/forum ID */
	public $forum_source_id;

	/** @var string|int Source author user ID */
	public $user_source_id;

	/** @var string Source author username */
	public $username = '';

	/** @var string Subject / Title */
	public $post_subject = '';

	/** @var string Raw source message text */
	public $raw_source_message = '';

	/** @var string Normalized BBCode message before phpBB storage conversion */
	public $normalized_message = '';

	/** @var string Final parsed storage text (phpBB storage format) */
	public $post_text = '';

	/** @var string phpBB BBCode UID */
	public $bbcode_uid = '';

	/** @var string phpBB BBCode Bitfield */
	public $bbcode_bitfield = '';

	/** @var int Creation timestamp */
	public $post_time = 0;

	/** @var string Poster IP address */
	public $poster_ip = '127.0.0.1';

	/** @var int phpBB post_visibility: 1 = ITEM_APPROVED, 0 = ITEM_UNAPPROVED, 2 = ITEM_DELETED */
	public $post_visibility = 1;

	/** @var int Source position */
	public $position = 0;

	/** @var int Edit count */
	public $post_edit_count = 0;

	/** @var int Last edit timestamp */
	public $post_edit_time = 0;

	/** @var string|int Last edit source user ID */
	public $post_edit_source_user_id = 0;

	/** @var string Edit reason */
	public $post_edit_reason = '';

	/** @var int Deletion timestamp (for soft-deleted posts) */
	public $delete_time = 0;

	/** @var string|int Deleting user source ID */
	public $delete_user_source_id = 0;

	/** @var string Deleting username */
	public $delete_username = '';

	/** @var string Deletion reason */
	public $delete_reason = '';

	/** @var bool */
	public $has_attachment = false;

	/** @var array Detected source attachment IDs */
	public $attachment_source_ids = [];

	/** @var array Unsupported features or BBCode tags */
	public $unsupported_features = [];

	/** @var array Conversion warnings */
	public $warnings = [];

	/** @var array Raw source record */
	public $raw_source_data = [];
}
