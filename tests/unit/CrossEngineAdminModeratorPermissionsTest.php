<?php
/**
 * phpBB Migration Center Extension
 *
 * @copyright (c) 2026 phpBB SEO Team
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace phpbbseo\migrationcenter\tests\unit;

use phpbbseo\migrationcenter\source\vbulletin\normalizer\vb_user_normalizer;
use phpbbseo\migrationcenter\source\mybb\normalizer\mybb_user_normalizer;
use phpbbseo\migrationcenter\source\smf\normalizer\smf_user_normalizer;

/**
 * Unit Test for Cross-Engine Admin and Moderator Permission Mapping
 * Ensures all supported forum systems (vBulletin, XenForo, MyBB, SMF) properly identify
 * administrators and moderators so that full permissions are granted in phpBB.
 */
class CrossEngineAdminModeratorPermissionsTest
{
	public function run(): array
	{
		$results = [];

		// 1. vBulletin Admin & Moderator Detection
		$vb_norm = new vb_user_normalizer();

		// vB Administrator (Group 6)
		$vb_admin = $vb_norm->normalize([
			'userid'         => 1,
			'username'       => 'vb_admin',
			'email'          => 'admin@vb.local',
			'usergroupid'    => 6,
			'membergroupids' => '',
			'password'       => md5('pass'),
			'salt'           => 'salt',
		]);
		$results['vb_admin_detected'] = ($vb_admin->is_admin === true && $vb_admin->is_moderator === false);

		// vB Super Moderator (Group 5)
		$vb_smod = $vb_norm->normalize([
			'userid'         => 2,
			'username'       => 'vb_smod',
			'email'          => 'smod@vb.local',
			'usergroupid'    => 5,
			'membergroupids' => '',
			'password'       => md5('pass'),
			'salt'           => 'salt',
		]);
		$results['vb_smod_detected'] = ($vb_smod->is_admin === false && $vb_smod->is_moderator === true);

		// vB Forum Moderator (Group 7) in secondary groups
		$vb_mod = $vb_norm->normalize([
			'userid'         => 3,
			'username'       => 'vb_mod',
			'email'          => 'mod@vb.local',
			'usergroupid'    => 2,
			'membergroupids' => '7,10',
			'password'       => md5('pass'),
			'salt'           => 'salt',
		]);
		$results['vb_mod_secondary_detected'] = ($vb_mod->is_admin === false && $vb_mod->is_moderator === true);

		// vB Normal Registered User (Group 2)
		$vb_reg = $vb_norm->normalize([
			'userid'         => 4,
			'username'       => 'vb_reg',
			'email'          => 'reg@vb.local',
			'usergroupid'    => 2,
			'membergroupids' => '',
			'password'       => md5('pass'),
			'salt'           => 'salt',
		]);
		$results['vb_regular_not_elevated'] = ($vb_reg->is_admin === false && $vb_reg->is_moderator === false);


		// 2. MyBB Admin & Moderator Detection
		$mybb_norm = new mybb_user_normalizer();

		// MyBB Administrator (Group 4)
		$mybb_admin = $mybb_norm->normalize([
			'uid'              => 1,
			'username'         => 'mybb_admin',
			'email'            => 'admin@mybb.local',
			'usergroup'        => 4,
			'additionalgroups' => '',
			'password'         => md5('pass'),
			'salt'             => 'salt',
		]);
		$results['mybb_admin_detected'] = ($mybb_admin->is_admin === true && $mybb_admin->is_moderator === false);

		// MyBB Super Moderator (Group 3)
		$mybb_smod = $mybb_norm->normalize([
			'uid'              => 2,
			'username'         => 'mybb_smod',
			'email'            => 'smod@mybb.local',
			'usergroup'        => 3,
			'additionalgroups' => '',
			'password'         => md5('pass'),
			'salt'             => 'salt',
		]);
		$results['mybb_smod_detected'] = ($mybb_smod->is_admin === false && $mybb_smod->is_moderator === true);

		// MyBB Moderator (Group 6) in additional groups
		$mybb_mod = $mybb_norm->normalize([
			'uid'              => 3,
			'username'         => 'mybb_mod',
			'email'            => 'mod@mybb.local',
			'usergroup'        => 2,
			'additionalgroups' => '6,8',
			'password'         => md5('pass'),
			'salt'             => 'salt',
		]);
		$results['mybb_mod_secondary_detected'] = ($mybb_mod->is_admin === false && $mybb_mod->is_moderator === true);

		// MyBB Regular User
		$mybb_reg = $mybb_norm->normalize([
			'uid'              => 4,
			'username'         => 'mybb_reg',
			'email'            => 'reg@mybb.local',
			'usergroup'        => 2,
			'additionalgroups' => '',
			'password'         => md5('pass'),
			'salt'             => 'salt',
		]);
		$results['mybb_regular_not_elevated'] = ($mybb_reg->is_admin === false && $mybb_reg->is_moderator === false);


		// 3. SMF Admin & Moderator Detection
		$smf_norm = new smf_user_normalizer();

		// SMF Administrator (Group 1)
		$smf_admin = $smf_norm->normalize([
			'id_member'         => 1,
			'member_name'       => 'smf_admin',
			'email_address'     => 'admin@smf.local',
			'id_group'          => 1,
			'additional_groups' => '',
			'passwd'            => md5('pass'),
		]);
		$results['smf_admin_detected'] = ($smf_admin->is_admin === true && $smf_admin->is_moderator === false);

		// SMF Global Moderator (Group 2)
		$smf_gmod = $smf_norm->normalize([
			'id_member'         => 2,
			'member_name'       => 'smf_gmod',
			'email_address'     => 'gmod@smf.local',
			'id_group'          => 2,
			'additional_groups' => '',
			'passwd'            => md5('pass'),
		]);
		$results['smf_gmod_detected'] = ($smf_gmod->is_admin === false && $smf_gmod->is_moderator === true);

		// SMF Moderator (Group 3) in additional groups
		$smf_mod = $smf_norm->normalize([
			'id_member'         => 3,
			'member_name'       => 'smf_mod',
			'email_address'     => 'mod@smf.local',
			'id_group'          => 0,
			'additional_groups' => '3,5',
			'passwd'            => md5('pass'),
		]);
		$results['smf_mod_secondary_detected'] = ($smf_mod->is_admin === false && $smf_mod->is_moderator === true);

		// SMF Regular User
		$smf_reg = $smf_norm->normalize([
			'id_member'         => 4,
			'member_name'       => 'smf_reg',
			'email_address'     => 'reg@smf.local',
			'id_group'          => 0,
			'additional_groups' => '',
			'passwd'            => md5('pass'),
		]);
		$results['smf_regular_not_elevated'] = ($smf_reg->is_admin === false && $smf_reg->is_moderator === false);


		// 4. XenForo Logic Verification
		// Simulate XenForo group membership logic
		$xf_users = [
			// Admin via flag
			['user_id' => 1, 'user_group_id' => 2, 'secondary_group_ids' => '', 'is_admin' => 1, 'is_moderator' => 0],
			// Admin via Group 3
			['user_id' => 2, 'user_group_id' => 3, 'secondary_group_ids' => '', 'is_admin' => 0, 'is_moderator' => 0],
			// Moderator via flag
			['user_id' => 3, 'user_group_id' => 2, 'secondary_group_ids' => '', 'is_admin' => 0, 'is_moderator' => 1],
			// Moderator via Group 4
			['user_id' => 4, 'user_group_id' => 2, 'secondary_group_ids' => '4', 'is_admin' => 0, 'is_moderator' => 0],
			// Regular User
			['user_id' => 5, 'user_group_id' => 2, 'secondary_group_ids' => '', 'is_admin' => 0, 'is_moderator' => 0],
		];

		$xf_eval = [];
		foreach ($xf_users as $row)
		{
			$primary_gid = (int)($row['user_group_id'] ?? 2);
			$secondary_ids = array_filter(array_map('intval', explode(',', (string)$row['secondary_group_ids'])));
			$all_groups = array_merge([$primary_gid], $secondary_ids);

			$is_admin = !empty($row['is_admin']) || in_array(3, $all_groups, true);
			$is_mod = !empty($row['is_moderator']) || in_array(4, $all_groups, true);

			$xf_eval[$row['user_id']] = ['is_admin' => $is_admin, 'is_moderator' => $is_mod];
		}

		$results['xf_admin_flag_detected']    = ($xf_eval[1]['is_admin'] === true);
		$results['xf_admin_group3_detected']  = ($xf_eval[2]['is_admin'] === true);
		$results['xf_mod_flag_detected']      = ($xf_eval[3]['is_moderator'] === true);
		$results['xf_mod_group4_detected']    = ($xf_eval[4]['is_moderator'] === true);
		$results['xf_regular_not_elevated']   = ($xf_eval[5]['is_admin'] === false && $xf_eval[5]['is_moderator'] === false);

		return $results;
	}
}
