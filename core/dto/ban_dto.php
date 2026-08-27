<?php
/**
 * phpBB Migration Center Extension
 *
 * @copyright (c) 2026 phpBB SEO Team
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace phpbbseo\migrationcenter\core\dto;

/**
 * Normalized Ban DTO
 */
class ban_dto
{
	/** @var string Ban type: 'user', 'email', 'ip' */
	public $ban_type = 'user';

	/** @var string|int Source identifier */
	public $source_id;

	/** @var int Source user ID (for user bans) */
	public $user_source_id = 0;

	/** @var string Banned email or pattern (for email bans) */
	public $ban_email = '';

	/** @var string Banned IP or pattern (for IP bans) */
	public $ban_ip = '';

	/** @var int Timestamp when ban started */
	public $ban_start = 0;

	/** @var int Timestamp when ban expires (0 = permanent) */
	public $ban_end = 0;

	/** @var string Public reason shown to user on login */
	public $ban_give_reason = '';

	/** @var string Internal administrative reason */
	public $ban_reason = '';

	/** @var bool Exclude flag (0 = ban, 1 = exclude) */
	public $ban_exclude = false;

	/** @var array Raw source record */
	public $raw_data = [];
}
