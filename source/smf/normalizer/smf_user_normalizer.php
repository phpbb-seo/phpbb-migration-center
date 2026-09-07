<?php
/**
 * phpBB Migration Center Extension
 *
 * @copyright (c) 2026 phpBB SEO Team
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace phpbbseo\migrationcenter\source\smf\normalizer;

use phpbbseo\migrationcenter\core\dto\user_dto;
use phpbbseo\migrationcenter\source\smf\password\smf_password_handler;
use phpbbseo\migrationcenter\source\smf\content\smf_message_converter;

/**
 * SMF 2.x User Normalizer
 */
class smf_user_normalizer
{
	/** @var smf_password_handler */
	protected $password_handler;

	/** @var smf_message_converter */
	protected $converter;

	public function __construct(?smf_password_handler $password_handler = null, ?smf_message_converter $converter = null)
	{
		$this->password_handler = $password_handler ?: new smf_password_handler();
		$this->converter = $converter ?: new smf_message_converter();
	}

	/**
	 * Normalize raw SMF user row into user_dto
	 *
	 * @param array $row
	 * @return user_dto
	 */
	public function normalize(array $row): user_dto
	{
		$dto = new user_dto();
		$dto->source_id = (int)($row['id_member'] ?? 0);

		// Username sanitization: prefer member_name, fallback to real_name
		$raw_username = (string)($row['member_name'] ?? $row['real_name'] ?? '');
		$clean_uname = preg_replace('/[\x00-\x1F\x7F]/u', '', trim($raw_username));
		if (empty($clean_uname))
		{
			$clean_uname = 'Imported_SMF_User_' . $dto->source_id;
		}
		$dto->username = $clean_uname;
		$dto->username_clean = function_exists('utf8_clean_string') ? utf8_clean_string($clean_uname) : mb_strtolower($clean_uname, 'UTF-8');

		// Email address
		$raw_email = trim((string)($row['email_address'] ?? ''));
		if (!empty($raw_email) && filter_var($raw_email, FILTER_VALIDATE_EMAIL))
		{
			$dto->email = strtolower($raw_email);
		}
		else
		{
			$dto->email = 'smf_user_' . $dto->source_id . '@imported.invalid';
		}

		// Password handling
		$conv_pass = $this->password_handler->convert_password('smf', [
			'passwd'      => $row['passwd'] ?? '',
			'member_name' => $clean_uname,
		]);
		$dto->password_hash    = $conv_pass['hash'];
		$dto->password_type    = $conv_pass['type'];
		$dto->requires_reset   = $conv_pass['requires_reset'];

		// Timestamps
		$dto->registered_date = (int)($row['date_registered'] ?? 0);
		$dto->last_visit_date = (int)($row['last_login'] ?? 0);

		// User status & type
		$activated = (int)($row['is_activated'] ?? 1);
		if ($activated === 1)
		{
			$dto->user_type = 0; // USER_NORMAL
		}
		else
		{
			$dto->user_type = 1; // USER_INACTIVE
		}

		$primary_group = (int)($row['id_group'] ?? 0);
		$secondary_groups = [];
		if (!empty($row['additional_groups']))
		{
			$parts = explode(',', (string)$row['additional_groups']);
			foreach ($parts as $p)
			{
				$p = trim($p);
				if ($p !== '' && ctype_digit($p))
				{
					$secondary_groups[] = (int)$p;
				}
			}
		}
		$dto->primary_group_source_id = $primary_group;
		$dto->secondary_group_source_ids = array_unique($secondary_groups);
		$all_groups = array_merge([$primary_group], $secondary_groups);
		$dto->is_admin = in_array(1, $all_groups, true); // SMF 1 = Administrator
		$dto->is_moderator = in_array(2, $all_groups, true) || in_array(3, $all_groups, true); // 2 = Global Mod, 3 = Mod
		$dto->group_id = 2; // Default phpBB Registered Users

		$dto->post_count = (int)($row['posts'] ?? 0);

		// Profile fields
		$dto->website = trim((string)($row['website_url'] ?? ''));
		$dto->occupation = trim((string)($row['personal_text'] ?? ''));
		$dto->interests = trim((string)($row['usertitle'] ?? ''));

		// Signature handling
		$raw_sig = trim((string)($row['signature'] ?? ''));
		if (!empty($raw_sig))
		{
			$res = $this->converter->convert($raw_sig);
			$dto->signature = $res->normalized_bbcode;
		}
		else
		{
			$dto->signature = '';
		}

		// User IP handling
		$raw_ip = $row['member_ip'] ?? '';
		if (!empty($raw_ip))
		{
			if (strlen($raw_ip) === 4 || strlen($raw_ip) === 16)
			{
				$inet = @inet_ntop($raw_ip);
				$dto->user_ip = ($inet !== false) ? $inet : '127.0.0.1';
			}
			else
			{
				$dto->user_ip = (string)$raw_ip;
			}
		}
		else
		{
			$dto->user_ip = '127.0.0.1';
		}

		return $dto;
	}
}
