<?php
/**
 * phpBB Migration Center Extension
 *
 * @copyright (c) 2026 phpBB SEO Team
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace phpbbseo\migrationcenter\core\dto;

/**
 * Normalized Conversation Recipient DTO
 */
class conversation_recipient_dto
{
	/** @var int Source user ID */
	public $user_source_id;

	/** @var string Recipient state: 'active', 'deleted', 'deleted_ignored' */
	public $recipient_state = 'active';

	/** @var int Timestamp of last read message */
	public $last_read_date = 0;

	/** @var bool Starred/marked flag */
	public $is_starred = false;

	/** @var bool Unread flag */
	public $is_unread = false;

	/** @var int Timestamp when participant joined conversation (0 = from beginning) */
	public $join_date = 0;
}
