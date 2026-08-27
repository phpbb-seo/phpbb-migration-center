<?php
/**
 * phpBB Migration Center Extension
 *
 * @copyright (c) 2026 phpBB SEO Team
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace phpbbseo\migrationcenter\core\dto;

/**
 * Normalized Conversation Message DTO
 */
class conversation_message_dto
{
	/** @var int Source message ID */
	public $source_id;

	/** @var int Source conversation ID */
	public $conversation_source_id = 0;

	/** @var int Message creation timestamp */
	public $message_date = 0;

	/** @var int Author source user ID */
	public $user_source_id = 0;

	/** @var string Author username */
	public $username = '';

	/** @var string Raw message BBCode */
	public $message_text = '';

	/** @var int Attachment count */
	public $attach_count = 0;

	/** @var string Author IP address */
	public $author_ip = '';

	/** @var int|null Target message ID once inserted */
	public $target_msg_id = null;

	/** @var int|null Target root message ID */
	public $target_root_msg_id = null;

	/** @var array Raw source record */
	public $raw_source_data = [];
}
