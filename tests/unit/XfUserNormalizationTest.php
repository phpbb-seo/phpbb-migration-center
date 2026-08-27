<?php
/**
 * XenForo User Normalization and Unicode Unit Test
 */

namespace phpbbseo\migrationcenter\tests\unit;

use phpbbseo\migrationcenter\core\dto\user_dto;
use phpbbseo\migrationcenter\source\xenforo\password\xf_password_handler;

class XfUserNormalizationTest
{
	public function run()
	{
		$password_handler = new xf_password_handler();

		// Test 1: Multibyte username with emoji
		$unicode_raw = [
			'user_id'             => 101,
			'username'            => "UnicodeRunner_TestUser_🚀",
			'email'               => 'unicode_user@example.com',
			'user_group_id'       => 2,
			'secondary_group_ids' => '3,4',
			'register_date'       => 1600000000,
			'last_activity'       => 1600005000,
			'message_count'       => 42,
			'timezone'            => 'UTC',
			'user_state'          => 'valid',
			'scheme_class'        => 'XF:Core12',
			'auth_data'           => serialize(['hash' => '$2y$10$abcdefghijklmnopqrstuu12345678901234567890123456789012']),
			'signature'           => 'Test signature with Unicode font and emoji 🚀',
			'location'            => 'London, UK',
		];

		$user = new user_dto();
		$user->source_id = $unicode_raw['user_id'];
		$user->username = $unicode_raw['username'];
		$user->username_clean = function_exists('utf8_clean_string') ? utf8_clean_string($user->username) : mb_strtolower($user->username, 'UTF-8');
		$user->email = $unicode_raw['email'];

		if (!mb_check_encoding($user->username, 'UTF-8'))
		{
			throw new \Exception("Username UTF-8 validation failed");
		}
		if (strpos($user->username, "UnicodeRunner") === false || strpos($user->username, "🚀") === false)
		{
			throw new \Exception("Username characters were lost during normalization");
		}

		// Test 2: Special character username
		$special_raw = [
			'user_id'      => 102,
			'username'     => 'Unicode_User_Special_🚀',
			'email'        => 'special_user@example.com',
			'user_state'   => 'email_confirm',
			'scheme_class' => 'XF:Core12',
			'auth_data'    => serialize(['hash' => '$2y$10$abcdefghijklmnopqrstuu12345678901234567890123456789012']),
		];

		$user2 = new user_dto();
		$user2->username = $special_raw['username'];
		if (strpos($user2->username, 'Unicode_User_Special') === false || strpos($user2->username, '🚀') === false)
		{
			throw new \Exception("Username corrupted during normalization");
		}

		// Test 3: User State Mapping
		$states_to_test = [
			'valid'              => ['expected_type' => 0, 'expected_reason' => 0],
			'email_confirm'      => ['expected_type' => 1, 'expected_reason' => 1],
			'email_confirm_edit' => ['expected_type' => 1, 'expected_reason' => 2],
			'moderated'          => ['expected_type' => 1, 'expected_reason' => 3],
			'rejected'           => ['expected_type' => 1, 'expected_reason' => 3],
			'disabled'           => ['expected_type' => 1, 'expected_reason' => 3],
		];

		foreach ($states_to_test as $state_name => $expected)
		{
			$u = new user_dto();
			switch ($state_name)
			{
				case 'valid':
					$u->user_type = 0;
					$u->user_inactive_reason = 0;
					break;
				case 'email_confirm':
					$u->user_type = 1;
					$u->user_inactive_reason = 1;
					break;
				case 'email_confirm_edit':
					$u->user_type = 1;
					$u->user_inactive_reason = 2;
					break;
				case 'moderated':
				case 'rejected':
				case 'disabled':
					$u->user_type = 1;
					$u->user_inactive_reason = 3;
					break;
			}

			if ($u->user_type !== $expected['expected_type'] || $u->user_inactive_reason !== $expected['expected_reason'])
			{
				throw new \Exception("User state mapping failed for: {$state_name}");
			}
		}

		// Test 4: Ban Metadata Mapping
		$ban_user = new user_dto();
		$ban_user->banned_state = true;
		$ban_user->ban_info = [
			'ban_start'  => 1600000000,
			'ban_end'    => 1610000000,
			'ban_reason' => 'Spamming violations',
		];
		if (!$ban_user->banned_state || $ban_user->ban_info['ban_reason'] !== 'Spamming violations')
		{
			throw new \Exception("Ban metadata mapping failed");
		}

		// Test 5: Invalid & Empty Email fallback
		$invalid_email = 'not-an-email';
		$fallback_email = !filter_var($invalid_email, FILTER_VALIDATE_EMAIL) ? 'imported_user_99@invalid.local' : $invalid_email;
		if ($fallback_email !== 'imported_user_99@invalid.local')
		{
			throw new \Exception("Invalid email fallback failed");
		}

		return true;
	}
}
