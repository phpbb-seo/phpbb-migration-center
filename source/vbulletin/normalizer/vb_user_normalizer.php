<?php
/**
 * phpBB Migration Center Extension
 *
 * @copyright (c) 2026 phpBB SEO Team
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace phpbbseo\migrationcenter\source\vbulletin\normalizer;

use phpbbseo\migrationcenter\core\dto\user_dto;
use phpbbseo\migrationcenter\source\vbulletin\password\vb_password_handler;

/**
 * Shared vBulletin 3.8 / 4.2 User Normalizer
 */
class vb_user_normalizer
{
	/** @var vb_password_handler */
	protected $password_handler;

	/**
	 * Constructor
	 *
	 * @param vb_password_handler|null $password_handler
	 */
	public function __construct(?vb_password_handler $password_handler = null)
	{
		$this->password_handler = $password_handler ?: new vb_password_handler();
	}

	/**
	 * Normalize raw vBulletin user record into user_dto
	 *
	 * @param array $row
	 * @return user_dto
	 */
	public function normalize(array $row): user_dto
	{
		$dto = new user_dto();
		$dto->source_id = (int)($row['userid'] ?? 0);

		// 1. Username Normalization & Control Character Sanitization
		$raw_username = (string)($row['username'] ?? '');
		$username = $this->sanitize_unicode_string($raw_username);
		if (empty($username))
		{
			$username = 'Imported_User_' . $dto->source_id;
		}
		$dto->username = $username;
		$dto->username_clean = $this->clean_username($username);

		// 2. Email Address Normalization
		$raw_email = trim((string)($row['email'] ?? ''));
		if (!empty($raw_email) && filter_var($raw_email, FILTER_VALIDATE_EMAIL))
		{
			$dto->email = strtolower($raw_email);
		}
		else
		{
			$dto->email = 'imported_user_' . $dto->source_id . '@invalid.local';
		}

		// 3. Password and Authentication
		$raw_pass = (string)($row['password'] ?? '');
		$raw_salt = (string)($row['salt'] ?? '');
		$auth_result = $this->password_handler->convert_password('vbulletin', [
			'password' => $raw_pass,
			'salt'     => $raw_salt,
		]);

		$dto->source_auth_scheme = 'vbulletin_md5';
		$dto->password_hash = $auth_result['hash'];
		$dto->password_type = $auth_result['type'];
		$dto->requires_password_reset = (bool)($auth_result['requires_reset'] ?? false);

		// 4. Group Classification & Inactive/Moderation State Handling
		$primary_group = (int)($row['usergroupid'] ?? 2);
		$dto->primary_group_source_id = $primary_group;

		$sec_groups = [];
		if (!empty($row['membergroupids']))
		{
			$parts = explode(',', (string)$row['membergroupids']);
			foreach ($parts as $p)
			{
				$gid = (int)trim($p);
				if ($gid > 0 && $gid !== $primary_group && !in_array($gid, $sec_groups, true))
				{
					$sec_groups[] = $gid;
				}
			}
		}
		$dto->secondary_group_source_ids = $sec_groups;

		$all_groups = array_merge([$primary_group], $sec_groups);

		$joindate = (int)($row['joindate'] ?? time());
		$dto->registered_date = ($joindate > 0) ? $joindate : time();
		$lastvisit = (int)($row['lastvisit'] ?? $row['lastactivity'] ?? $dto->registered_date);
		$dto->last_visit_date = ($lastvisit > 0) ? $lastvisit : $dto->registered_date;

		// Handle Inactive and Moderation States:
		// Group 3: Users Awaiting Email Confirmation
		// Group 4: Users Awaiting Moderation
		if ($primary_group === 3)
		{
			$dto->user_type = 1; // USER_INACTIVE
			$dto->user_inactive_reason = 1; // INACTIVE_REGISTER
			$dto->user_inactive_time = $dto->registered_date;
			$dto->user_state = 'email_confirm';
		}
		else if ($primary_group === 4)
		{
			$dto->user_type = 1; // USER_INACTIVE
			$dto->user_inactive_reason = 3; // INACTIVE_MANUAL
			$dto->user_inactive_time = $dto->registered_date;
			$dto->user_state = 'moderated';
		}
		else
		{
			$dto->user_type = 0; // USER_NORMAL
			$dto->user_inactive_reason = 0;
			$dto->user_inactive_time = 0;
			$dto->user_state = 'valid';
		}

		// Check if source user is in banned group (Group 8)
		if (in_array(8, $all_groups, true))
		{
			$dto->banned_state = true;
			$dto->ban_info = [
				'ban_start'  => $dto->registered_date,
				'ban_end'    => 0,
				'ban_reason' => 'Imported from vBulletin Banned Users group',
			];
		}

		// NO PRIVILEGE ELEVATION: Record admin/mod roles in metadata only
		$dto->is_admin = in_array(6, $all_groups, true); // Group 6 = Administrators
		$dto->is_moderator = in_array(5, $all_groups, true) || in_array(7, $all_groups, true); // 5=Super Mods, 7=Mods
		$dto->group_id = 2; // Default phpBB Registered Users

		// 5. Profile Details & Options Bitfield
		$dto->post_count = max(0, (int)($row['posts'] ?? 0));
		$dto->user_ip = !empty($row['ipaddress']) ? substr(trim($row['ipaddress']), 0, 45) : '127.0.0.1';

		// Birthday: vBulletin stores as MM-DD-YYYY or empty
		$dto->birthday = $this->normalize_birthday((string)($row['birthday'] ?? ''));

		// Custom user title: strip HTML and control characters
		if (!empty($row['usertitle']))
		{
			$dto->custom_title = $this->sanitize_plain_text((string)$row['usertitle']);
		}

		// Website URL: validate scheme
		if (!empty($row['homepage']))
		{
			$dto->website = $this->sanitize_url((string)$row['homepage']);
		}

		// Contact fields: ICQ, AIM, Yahoo, MSN, Skype
		$contact_fields = [];
		if (!empty($row['icq']))   $contact_fields['phpbb_icq'] = $this->sanitize_plain_text((string)$row['icq']);
		if (!empty($row['aim']))   $contact_fields['phpbb_aim'] = $this->sanitize_plain_text((string)$row['aim']);
		if (!empty($row['yahoo'])) $contact_fields['phpbb_yahoo'] = $this->sanitize_plain_text((string)$row['yahoo']);
		if (!empty($row['skype'])) $contact_fields['phpbb_skype'] = $this->sanitize_plain_text((string)$row['skype']);
		$dto->custom_fields = $contact_fields;

		// Options Bitfield parsing (vB standard user options)
		$options = (int)($row['options'] ?? 0);
		// Bit 512 = Invisible to online list
		$dto->visibility = ($options & 512) ? 0 : 1;

		// Timezone normalization
		$dto->timezone = $this->normalize_timezone((string)($row['timezoneoffset'] ?? ''));

		// Signature from usertextfield
		if (!empty($row['signature']))
		{
			$dto->signature = $this->sanitize_signature((string)$row['signature']);
		}

		// Raw source metadata for deferred Avatar/ProfilePic migration
		$dto->raw_source_data = [
			'avatarid'           => (int)($row['avatarid'] ?? 0),
			'avatarrevision'     => (int)($row['avatarrevision'] ?? 0),
			'profilepicrevision' => (int)($row['profilepicrevision'] ?? 0),
			'sigpicrevision'     => (int)($row['sigpicrevision'] ?? 0),
		];

		return $dto;
	}

	/**
	 * Sanitize Unicode string removing control characters while preserving Persian/Arabic/Multilingual text
	 *
	 * @param string $str
	 * @return string
	 */
	public function sanitize_unicode_string(string $str): string
	{
		// Remove null bytes and non-printable control characters (ASCII 0-31 except tab/newline, and 127)
		$clean = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $str);
		if ($clean === null)
		{
			// Fallback if invalid UTF-8 byte sequence encountered
			$clean = mb_convert_encoding($str, 'UTF-8', 'UTF-8');
			$clean = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $clean) ?? '';
		}

		return trim($clean);
	}

	/**
	 * Clean username for collision detection conforming to phpBB clean string logic
	 *
	 * @param string $username
	 * @return string
	 */
	public function clean_username(string $username): string
	{
		if (function_exists('utf8_clean_string'))
		{
			return utf8_clean_string($username);
		}

		return mb_strtolower(trim($username), 'UTF-8');
	}

	/**
	 * Normalize vBulletin birthday string (e.g. '04-12-1988' or '1988-04-12') to phpBB format 'DD-MM-YYYY'
	 *
	 * @param string $bday
	 * @return string
	 */
	protected function normalize_birthday(string $bday): string
	{
		$bday = trim($bday);
		if (empty($bday))
		{
			return '';
		}

		// Case 1: MM-DD-YYYY (vB default format)
		if (preg_match('/^(\d{1,2})-(\d{1,2})-(\d{4})$/', $bday, $m))
		{
			return sprintf('%02d-%02d-%04d', (int)$m[2], (int)$m[1], (int)$m[3]);
		}

		// Case 2: YYYY-MM-DD
		if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $bday, $m))
		{
			return sprintf('%02d-%02d-%04d', (int)$m[3], (int)$m[2], (int)$m[1]);
		}

		return '';
	}

	/**
	 * Sanitize URL safely, only permitting http/https protocols
	 *
	 * @param string $url
	 * @return string
	 */
	protected function sanitize_url(string $url): string
	{
		$url = trim($url);
		if (empty($url))
		{
			return '';
		}

		// Reject javascript:, data:, vbscript: protocols
		if (preg_match('/^(?:javascript|data|vbscript|file):/i', $url))
		{
			return '';
		}

		if (!preg_match('#^https?://#i', $url))
		{
			$url = 'https://' . $url;
		}

		return filter_var($url, FILTER_VALIDATE_URL) ? $url : '';
	}

	/**
	 * Sanitize plain text profile values removing HTML tags
	 *
	 * @param string $text
	 * @return string
	 */
	protected function sanitize_plain_text(string $text): string
	{
		$stripped = strip_tags(trim($text));
		return $this->sanitize_unicode_string($stripped);
	}

	/**
	 * Sanitize signature preserving BBCode and stripping dangerous HTML/scripts
	 *
	 * @param string $sig
	 * @return string
	 */
	protected function sanitize_signature(string $sig): string
	{
		// Strip raw script and iframe tags
		$sig = preg_replace('#<script[^>]*>.*?</script>#is', '', $sig);
		$sig = preg_replace('#<iframe[^>]*>.*?</iframe>#is', '', $sig);
		$sig = strip_tags($sig);

		return $this->sanitize_unicode_string($sig);
	}

	/**
	 * Normalize vBulletin timezone offset (e.g. '+3.5', '-5', '0')
	 *
	 * @param string $offset
	 * @return string
	 */
	protected function normalize_timezone(string $offset): string
	{
		$offset = trim($offset);
		if ($offset === '' || !is_numeric($offset))
		{
			return 'UTC';
		}

		$num = (float)$offset;
		if ($num === 0.0)
		{
			return 'UTC';
		}

		$hours = (int)$num;
		$mins = (int)(abs($num - $hours) * 60);

		return sprintf('Etc/GMT%+d', -$hours);
	}
}
