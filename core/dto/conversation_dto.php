<?php
/**
 * phpBB Migration Center Extension
 *
 * @copyright (c) 2026 phpBB SEO Team
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace phpbbseo\migrationcenter\core\dto;

/**
 * Normalized Conversation Master DTO
 */
class conversation_dto
{
	/** @var int Source conversation ID */
	public $source_id;

	/** @var string Conversation title */
	public $title = '';

	/** @var int Starter user ID */
	public $user_source_id = 0;

	/** @var string Starter username */
	public $username = '';

	/** @var int Start timestamp */
	public $start_date = 0;

	/** @var bool Open invite flag */
	public $open_invite = false;

	/** @var bool Conversation open/locked */
	public $conversation_open = true;

	/** @var int Total replies count */
	public $reply_count = 0;

	/** @var int Total recipients count */
	public $recipient_count = 0;

	/** @var int First source message ID */
	public $first_message_id = 0;

	/** @var int Last message timestamp */
	public $last_message_date = 0;

	/** @var int Last message ID */
	public $last_message_id = 0;

	/** @var int Last message user ID */
	public $last_message_user_id = 0;

	/** @var conversation_recipient_dto[] List of participants */
	public $recipients = [];

	/** @var int|null Target root message ID once established */
	public $target_root_msg_id = null;

	/** @var array Raw source record */
	public $raw_source_data = [];
}
