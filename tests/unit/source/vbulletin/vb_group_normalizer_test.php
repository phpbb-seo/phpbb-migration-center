<?php
/**
 * phpBB Migration Center Extension
 *
 * @copyright (c) 2026 phpBB SEO Team
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace phpbbseo\migrationcenter\tests\unit\source\vbulletin;

use phpbbseo\migrationcenter\source\vbulletin\normalizer\vb_group_normalizer;

/**
 * Unit Test for vBulletin Group Normalizer
 */
class vb_group_normalizer_test
{
	public function run(): array
	{
		$results = [];

		// 1. Group 1: Unregistered / Not Logged In -> GUESTS
		$g1 = vb_group_normalizer::normalize([
			'usergroupid' => 1,
			'title'       => 'Unregistered / Not Logged In',
			'usertitle'   => 'Guest',
			'opentag'     => '',
			'closetag'    => '',
		]);
		$results['g1_is_builtin']      = ($g1->is_builtin === true);
		$results['g1_canonical_name']  = ($g1->canonical_name === 'GUESTS');

		// 2. Group 2: Registered Users -> REGISTERED
		$g2 = vb_group_normalizer::normalize([
			'usergroupid' => 2,
			'title'       => 'Registered Users',
			'usertitle'   => '',
			'opentag'     => '',
			'closetag'    => '',
		]);
		$results['g2_is_builtin']      = ($g2->is_builtin === true);
		$results['g2_canonical_name']  = ($g2->canonical_name === 'REGISTERED');

		// 3. Group 3: Users Awaiting Email Confirmation -> REGISTERED
		$g3 = vb_group_normalizer::normalize([
			'usergroupid' => 3,
			'title'       => 'Users Awaiting Email Confirmation',
			'usertitle'   => '',
			'opentag'     => '',
			'closetag'    => '',
		]);
		$results['g3_is_builtin']      = ($g3->is_builtin === true);
		$results['g3_canonical_name']  = ($g3->canonical_name === 'REGISTERED');

		// 4. Group 4: Users Awaiting Moderation -> REGISTERED
		$g4 = vb_group_normalizer::normalize([
			'usergroupid' => 4,
			'title'       => '(COPPA) Users Awaiting Moderation',
			'usertitle'   => '',
			'opentag'     => '',
			'closetag'    => '',
		]);
		$results['g4_is_builtin']      = ($g4->is_builtin === true);
		$results['g4_canonical_name']  = ($g4->canonical_name === 'REGISTERED');

		// 5. Group 5: Super Moderators -> GLOBAL_MODERATORS
		$g5 = vb_group_normalizer::normalize([
			'usergroupid' => 5,
			'title'       => 'Super Moderators',
			'usertitle'   => 'Super Moderator',
			'opentag'     => '<font color="#00aa00"><b>',
			'closetag'    => '</b></font>',
		]);
		$results['g5_is_builtin']      = ($g5->is_builtin === true);
		$results['g5_canonical_name']  = ($g5->canonical_name === 'GLOBAL_MODERATORS');
		$results['g5_color_extracted'] = ($g5->group_colour === '00AA00');

		// 6. Group 6: Administrators -> ADMINISTRATORS
		$g6 = vb_group_normalizer::normalize([
			'usergroupid' => 6,
			'title'       => 'Administrators',
			'usertitle'   => 'Administrator',
			'opentag'     => '<span style="color: #FF0000; font-weight: bold;">',
			'closetag'    => '</span>',
		]);
		$results['g6_is_builtin']      = ($g6->is_builtin === true);
		$results['g6_canonical_name']  = ($g6->canonical_name === 'ADMINISTRATORS');
		$results['g6_color_extracted'] = ($g6->group_colour === 'FF0000');

		// 7. Group 7: Moderators -> Custom Group (NOT Global Moderators)
		$g7 = vb_group_normalizer::normalize([
			'usergroupid' => 7,
			'title'       => 'Moderators',
			'usertitle'   => 'Moderator',
			'opentag'     => '',
			'closetag'    => '',
		]);
		$results['g7_not_builtin']         = ($g7->is_builtin === false);
		$results['g7_not_global_moderator']= ($g7->canonical_name === '');

		// 8. Group 8: Banned Users -> Custom Group
		$g8 = vb_group_normalizer::normalize([
			'usergroupid' => 8,
			'title'       => 'Banned Users',
			'usertitle'   => 'Banned',
			'opentag'     => '',
			'closetag'    => '',
		]);
		$results['g8_not_builtin']     = ($g8->is_builtin === false);
		$results['g8_canonical_empty'] = ($g8->canonical_name === '');

		// 9. Custom VIP Group with Persian Title
		$g_vip = vb_group_normalizer::normalize([
			'usergroupid' => 9,
			'title'       => 'کاربران ویژه VIP',
			'description' => 'دسترسی ویژه به بخش‌های VIP',
			'usertitle'   => 'VIP Member',
			'opentag'     => '<font color="orange">',
			'closetag'    => '</font>',
			'ispublicgroup'=> 1,
		]);
		$results['vip_persian_title']   = ($g_vip->group_name === 'کاربران ویژه VIP');
		$results['vip_is_public']       = ($g_vip->group_type === 0);
		$results['vip_color_extracted'] = ($g_vip->group_colour === 'FFA500');

		// 10. Malicious Script Rejection in Opentag
		$g_evil = vb_group_normalizer::normalize([
			'usergroupid' => 10,
			'title'       => 'Hacker <script>alert(1)</script> Group',
			'opentag'     => '<script>evil()</script><span style="color:#0000FF">',
			'closetag'    => '</span>',
		]);
		$results['evil_script_stripped_from_title'] = (strpos($g_evil->group_name, '<script>') === false && $g_evil->group_name === 'Hacker alert(1) Group');
		$results['evil_color_extracted_safely']     = ($g_evil->group_colour === '0000FF');

		return $results;
	}
}
