<?php
/**
 * phpBB Migration Center Extension
 *
 * @copyright (c) 2026 phpBB SEO Team
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace phpbbseo\migrationcenter\source\mybb\normalizer;

use phpbbseo\migrationcenter\core\dto\user_dto;
use phpbbseo\migrationcenter\source\mybb\password\mybb_password_handler;
use phpbbseo\migrationcenter\source\mybb\content\mybb_message_converter;

/**
 * MyBB User Normalizer
 */
class mybb_user_normalizer
{
	/** @var mybb_password_handler */
	protected $password_handler;

	/** @var mybb_message_converter */
	protected $converter;

	public function __construct(?mybb_password_handler $password_handler = null, ?mybb_message_converter $converter = null)
	{
		$this->password_handler = $password_handler ?: new mybb_password_handler();
		$this->converter = $converter ?: new mybb_message_converter();
	}

	/**
	 * Normalize raw MyBB user row into user_dto
	 *
	 * @param array $row
	 * @return user_dto
	 */
	public function normalize(array $row): user_dto
	{
		$dto = new user_dto();
		$dto->source_id = (int)($row['uid'] ?? 0);

		// Username sanitization
		$raw_username = (string)($row['username'] ?? '');
		$clean_uname = preg_replace('/[\x00-\x1F\x7F]/u', '', trim($raw_username));
		if (empty($clean_uname))
		{
			$clean_uname = 'Imported_User_' . $dto->source_id;
		}
		$dto->username = $clean_uname;
		$dto->username_clean = function_exists('utf8_clean_string') ? utf8_clean_string($clean_uname) : mb_strtolower($clean_uname, 'UTF-8');

		// Email address
		$raw_email = trim((string)($row['email'] ?? ''));
		if (!empty($raw_email) && filter_var($raw_email, FILTER_VALIDATE_EMAIL))
		{
			$dto->email = strtolower($raw_email);
		}
		else
		{
			$dto->email = 'imported_user_' . $dto->source_id . '@invalid.local';
		}

		// Password handling
		$raw_pass = (string)($row['password'] ?? '');
		$raw_salt = (string)($row['salt'] ?? '');
		$auth_result = $this->password_handler->convert_password('mybb', [
			'password' => $raw_pass,
			'salt'     => $raw_salt,
		]);

		$dto->source_auth_scheme = 'mybb_md5';
		$dto->password_hash = $auth_result['hash'];
		$dto->password_type = $auth_result['type'];
		$dto->requires_password_reset = (bool)($auth_result['requires_reset'] ?? false);

		// Groups
		$primary_gid = (int)($row['usergroup'] ?? 2);
		$dto->primary_group_source_id = $primary_gid > 0 ? $primary_gid : 2;

		// Secondary groups from additionalgroups (comma-separated, e.g. "8,9,11")
		$additional_gids = [];
		if (!empty($row['additionalgroups']))
		{
			$gids = explode(',', (string)$row['additionalgroups']);
			foreach ($gids as $g)
			{
				$g = (int)trim($g);
				if ($g > 0 && $g !== $primary_gid)
				{
					$additional_gids[] = $g;
				}
			}
		}
		$dto->secondary_group_source_ids = array_unique($additional_gids);

		// Registration and activity timestamps
		$regdate = (int)($row['regdate'] ?? time());
		$lastactive = (int)($row['lastactive'] ?? $row['lastvisit'] ?? 0);
		$posts = (int)($row['postnum'] ?? 0);

		$dto->registered_date = $regdate;
		$dto->last_visit_date = $lastactive;
		$dto->post_count = $posts;
		$dto->user_ip = (string)($row['lastip'] ?? '127.0.0.1');

		// Legacy aliases for backward compatibility
		$dto->user_regdate = $regdate;
		$dto->user_lastvisit = $lastactive;
		$dto->user_posts = $posts;

		// User status: usergroup 5 = awaiting activation, usergroup 7 = banned
		if ($primary_gid === 5)
		{
			$dto->user_type = 1; // USER_INACTIVE
			$dto->user_inactive_reason = 1; // INACTIVE_REGISTER
			$dto->user_inactive_time = $dto->registered_date;
		}
		else if ($primary_gid === 7)
		{
			$dto->user_type = 0; // USER_NORMAL (phpBB ban table handles banning)
		}
		else if ($primary_gid === 4)
		{
			$dto->user_type = 0; // Will be mapped to Administrator group
		}
		else
		{
			$dto->user_type = 0; // USER_NORMAL
		}

		// Signature
		if (!empty($row['signature']))
		{
			$conv_sig = $this->converter->convert((string)$row['signature']);
			$dto->signature = $conv_sig->normalized_bbcode;
			$dto->user_sig = $conv_sig->normalized_bbcode;
		}

		// Website
		if (!empty($row['website']))
		{
			$web = trim((string)$row['website']);
			if (filter_var($web, FILTER_VALIDATE_URL))
			{
				$dto->website = $web;
				$dto->user_website = $web;
			}
		}

		// Avatar
		if (!empty($row['avatar']))
		{
			$dto->user_avatar = trim((string)$row['avatar']);
			$dto->user_avatar_type = 'avatar.driver.upload';
			if (!empty($row['avatardimensions']) && strpos($row['avatardimensions'], '|') !== false)
			{
				list($w, $h) = explode('|', $row['avatardimensions'], 2);
				$dto->user_avatar_width = (int)$w;
				$dto->user_avatar_height = (int)$h;
			}
			else
			{
				$dto->user_avatar_width = 100;
				$dto->user_avatar_height = 100;
			}
		}

		return $dto;
	}
}
