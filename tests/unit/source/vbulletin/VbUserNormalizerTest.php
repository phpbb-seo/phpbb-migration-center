<?php
/**
 * phpBB Migration Center Extension
 *
 * @copyright (c) 2026 phpBB SEO Team
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace phpbbseo\migrationcenter\tests\unit\source\vbulletin;

use phpbbseo\migrationcenter\source\vbulletin\normalizer\vb_user_normalizer;

/**
 * Unit Test for vBulletin User Normalizer
 */
class VbUserNormalizerTest
{
	public function run(): array
	{
		$results = [];
		$normalizer = new vb_user_normalizer();

		// 1. Persian and Arabic Unicode Username Normalization
		$persian_row = [
			'userid'         => 10,
			'username'       => 'محمدرضا_آزمایشی',
			'email'          => 'm.reza@example.com',
			'password'       => md5('pass'),
			'salt'           => 'saltsalt',
			'usergroupid'    => 2,
			'joindate'       => 1600000000,
			'lastactivity'   => 1600050000,
			'posts'          => 25,
			'homepage'       => 'https://example.com',
			'signature'      => '[B]امضای فارسی[/B]',
		];
		$dto_fa = $normalizer->normalize($persian_row);

		$results['persian_username_preserved'] = ($dto_fa->username === 'محمدرضا_آزمایشی');
		$results['persian_username_clean']     = (!empty($dto_fa->username_clean));
		$results['persian_signature_converted'] = ($dto_fa->signature === '[B]امضای فارسی[/B]');

		// 2. Control Characters and Invalid UTF-8 Stripping
		$dirty_row = [
			'userid'   => 11,
			'username' => "User\x00\x08Name\x1F\x7F",
			'email'    => 'dirty@example.com',
			'password' => md5('pass'),
			'salt'     => 'saltsalt',
		];
		$dto_dirty = $normalizer->normalize($dirty_row);
		$results['control_characters_stripped'] = ($dto_dirty->username === 'UserName');

		// 3. Email Validation & Placeholder Fallback
		$invalid_email_row = [
			'userid'   => 12,
			'username' => 'NoEmailUser',
			'email'    => 'not-a-valid-email',
			'password' => md5('pass'),
			'salt'     => 'saltsalt',
		];
		$dto_no_email = $normalizer->normalize($invalid_email_row);
		$results['invalid_email_replaced_with_placeholder'] = ($dto_no_email->email === 'imported_user_12@invalid.local');

		// 4. Inactive Group 3 (Awaiting Email Confirmation)
		$group3_row = [
			'userid'      => 13,
			'username'    => 'PendingEmailUser',
			'email'       => 'pending@example.com',
			'usergroupid' => 3,
			'joindate'    => 1650000000,
			'password'    => md5('pass'),
			'salt'        => 'saltsalt',
		];
		$dto_g3 = $normalizer->normalize($group3_row);
		$results['group3_is_inactive']         = ($dto_g3->user_type === 1);
		$results['group3_inactive_reason']     = ($dto_g3->user_inactive_reason === 1); // INACTIVE_REGISTER
		$results['group3_inactive_time_set']   = ($dto_g3->user_inactive_time === 1650000000);

		// 5. Inactive Group 4 (Awaiting Moderation)
		$group4_row = [
			'userid'      => 14,
			'username'    => 'PendingModUser',
			'email'       => 'mod@example.com',
			'usergroupid' => 4,
			'joindate'    => 1660000000,
			'password'    => md5('pass'),
			'salt'        => 'saltsalt',
		];
		$dto_g4 = $normalizer->normalize($group4_row);
		$results['group4_is_inactive']         = ($dto_g4->user_type === 1);
		$results['group4_inactive_reason']     = ($dto_g4->user_inactive_reason === 3); // INACTIVE_MANUAL
		$results['group4_inactive_time_set']   = ($dto_g4->user_inactive_time === 1660000000);

		// 6. Source Administrators (Group 6) — NO PRIVILEGE ELEVATION
		$admin_row = [
			'userid'      => 1,
			'username'    => 'vBAdmin',
			'email'       => 'admin@vb.local',
			'usergroupid' => 6,
			'password'    => md5('pass'),
			'salt'        => 'saltsalt',
		];
		$dto_admin = $normalizer->normalize($admin_row);
		$results['admin_user_type_is_normal']  = ($dto_admin->user_type === 0);
		$results['admin_group_id_is_registered']= ($dto_admin->group_id === 2);
		$results['admin_flag_recorded_in_meta']= ($dto_admin->is_admin === true);

		// 7. Banned Group 8
		$banned_row = [
			'userid'      => 15,
			'username'    => 'BannedUser',
			'email'       => 'banned@vb.local',
			'usergroupid' => 8,
			'password'    => md5('pass'),
			'salt'        => 'saltsalt',
		];
		$dto_banned = $normalizer->normalize($banned_row);
		$results['banned_state_flagged']       = ($dto_banned->banned_state === true);
		$results['banned_user_not_elevated']   = ($dto_banned->user_type === 0 && $dto_banned->group_id === 2);

		// 8. Script Tag Stripping in Signatures
		$script_sig_row = [
			'userid'    => 16,
			'username'  => 'ScriptUser',
			'email'     => 'script@vb.local',
			'password'  => md5('pass'),
			'salt'      => 'saltsalt',
			'signature' => '<script>alert(1)</script>[B]Safe Sig[/B]<iframe src="evil.com"></iframe>',
		];
		$dto_script = $normalizer->normalize($script_sig_row);
		$results['raw_scripts_stripped_from_signature'] = (strpos($dto_script->signature, 'script') === false && strpos($dto_script->signature, 'iframe') === false && strpos($dto_script->signature, '[B]Safe Sig[/B]') !== false);

		// 9. Birthday Formatting (MM-DD-YYYY to DD-MM-YYYY)
		$bday_row = [
			'userid'   => 17,
			'username' => 'BdayUser',
			'email'    => 'bday@vb.local',
			'password' => md5('pass'),
			'salt'     => 'saltsalt',
			'birthday' => '07-25-1990',
		];
		$dto_bday = $normalizer->normalize($bday_row);
		$results['birthday_normalized_to_dd_mm_yyyy'] = ($dto_bday->birthday === '25-07-1990');

		return $results;
	}
}
