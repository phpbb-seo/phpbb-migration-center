<?php
/**
 * phpBB Migration Center Extension
 *
 * @copyright (c) 2026 phpBB SEO Team
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace phpbbseo\migrationcenter\core\dto;

/**
 * Normalized Avatar DTO
 */
class avatar_dto
{
	/** @var string|int Source user ID (xf_user.user_id) */
	public $user_source_id;

	/** @var string Avatar type: 'upload', 'gravatar', 'default' */
	public $avatar_type = 'default';

	/** @var string Gravatar email if type is gravatar */
	public $gravatar_email = '';

	/** @var int Source avatar timestamp (avatar_date) */
	public $avatar_date = 0;

	/** @var string Resolved source physical absolute file path */
	public $source_physical_path = '';

	/** @var string Size variant used ('o', 'l', 'm', 's') */
	public $source_size_variant = '';

	/** @var string Lowercase file extension without dot */
	public $extension = 'jpg';

	/** @var string Detected MIME type */
	public $mimetype = 'image/jpeg';

	/** @var int Source width */
	public $source_width = 0;

	/** @var int Source height */
	public $source_height = 0;

	/** @var int Source file size in bytes */
	public $source_filesize = 0;

	/** @var string Source SHA-256 hash */
	public $source_sha256 = '';

	/** @var string Generated target physical filename in phpBB avatar directory */
	public $target_physical_filename = '';

	/** @var string Value to store in phpbb_users.user_avatar */
	public $target_avatar_value = '';

	/** @var int Final target width */
	public $target_width = 0;

	/** @var int Final target height */
	public $target_height = 0;

	/** @var string Transformation performed: 'copied', 'resized', 'none' */
	public $transformation_performed = 'none';

	/** @var array Warnings encountered */
	public $warnings = [];

	/** @var array Raw source user row */
	public $raw_source_data = [];
}
