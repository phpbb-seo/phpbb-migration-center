<?php
/**
 * phpBB Migration Center Extension
 *
 * @copyright (c) 2026 phpBB SEO Team
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace phpbbseo\migrationcenter\core\dto;

/**
 * Normalized User DTO
 */
class user_dto
{
	/** @var string|int Source user ID */
	public $source_id;

	/** @var string Username */
	public $username = '';

	/** @var string Cleaned username for comparison */
	public $username_clean = '';

	/** @var string Email address */
	public $email = '';

	/** @var string Source authentication scheme name (e.g. 'XF:Core12') */
	public $source_auth_scheme = '';

	/** @var array|string Sanitized source authentication payload (NO raw passwords) */
	public $source_auth_payload = [];

	/** @var string Converted or preserved password hash */
	public $password_hash = '';

	/** @var string Password hash type (e.g. 'bcrypt', 'xenforo', 'legacy') */
	public $password_type = 'bcrypt';

	/** @var bool Whether the account requires a password reset */
	public $requires_password_reset = false;

	/** @var int Primary group source ID */
	public $primary_group_source_id = 2;

	/** @var array Secondary group source IDs */
	public $secondary_group_source_ids = [];

	/** @var int phpBB user_type (0 = normal, 1 = inactive, 2 = bot, 3 = founder) */
	public $user_type = 0;

	/** @var int phpBB inactive reason (1 = register, 2 = profile, 3 = manual, 4 = remind) */
	public $user_inactive_reason = 0;

	/** @var int Timestamp when user became inactive */
	public $user_inactive_time = 0;

	/** @var int Target phpBB primary group_id (default 2 = Registered Users) */
	public $group_id = 2;

	/** @var int Registration timestamp */
	public $registered_date = 0;

	/** @var int Last activity/visit timestamp */
	public $last_visit_date = 0;

	/** @var int Post count from source */
	public $post_count = 0;

	/** @var string Timezone identifier */
	public $timezone = 'UTC';

	/** @var string Language preference */
	public $language = 'en';

	/** @var string Source user state (e.g. 'valid', 'email_confirm', 'moderated', 'rejected') */
	public $user_state = 'valid';

	/** @var bool Banned status flag */
	public $banned_state = false;

	/** @var array Ban details if banned: [ban_start, ban_end, ban_reason] */
	public $ban_info = [];

	/** @var string Avatar type */
	public $avatar_type = '';

	/** @var string Avatar source path or filename */
	public $avatar_path = '';

	/** @var string User signature */
	public $signature = '';

	/** @var string Signature BBCode UID */
	public $sig_bbcode_uid = '';

	/** @var string Signature BBCode bitfield */
	public $sig_bbcode_bitfield = '';

	/** @var string Website URL */
	public $website = '';

	/** @var string Location string */
	public $location = '';

	/** @var string About / occupation text */
	public $about = '';

	/** @var string Birthday string formatted as 'DD-MM-YYYY' */
	public $birthday = '';

	/** @var int Profile visibility (0 = hidden, 1 = visible) */
	public $visibility = 1;

	/** @var bool Whether user was an admin in source */
	public $is_admin = false;

	/** @var bool Whether user was a moderator in source */
	public $is_moderator = false;

	/** @var string Custom user title */
	public $custom_title = '';

	/** @var string User registration IP */
	public $user_ip = '127.0.0.1';

	/** @var array Custom profile fields */
	public $custom_fields = [];

	/** @var array Raw source data for deferred processing */
	public $raw_source_data = [];
}
