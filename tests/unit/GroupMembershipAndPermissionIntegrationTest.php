<?php
/**
 * Group Membership, Permissions, and Admin Integrity Integration Test
 */

namespace phpbbseo\migrationcenter\tests\unit;

use phpbbseo\migrationcenter\core\engine\migration_engine;
use phpbbseo\migrationcenter\core\dto\migration_config_dto;
use phpbbseo\migrationcenter\core\dto\group_dto;
use phpbbseo\migrationcenter\source\xenforo\config\xf_config_detector;

class GroupMembershipAndPermissionIntegrationTest
{
	public function run()
	{
		global $phpbb_container, $phpbb_root_path;

		list($db, $table_prefix) = get_test_db();
		$source_path = 'C:/xampp/htdocs/xen';
		$config = xf_config_detector::detect_from_path($source_path);
		if (!$config)
		{
			throw new \Exception("Could not load XenForo config for group integration test");
		}

		// 1. Check existing phpBB Admin (user_id = 2) before test
		$sql = "SELECT u.user_id, u.username, u.username_clean, u.user_type, u.group_id, ug.group_id as ug_gid
				FROM {$table_prefix}users u
				LEFT JOIN {$table_prefix}user_group ug ON (u.user_id = ug.user_id)
				WHERE u.user_id = 2";
		$res = $db->sql_query($sql);
		$original_admin_rows = [];
		while ($r = $db->sql_fetchrow($res))
		{
			$original_admin_rows[] = $r;
		}
		$db->sql_freeresult($res);

		if (empty($original_admin_rows))
		{
			throw new \Exception("phpBB Admin user_id 2 not found");
		}

		$original_admin_ug = array_column($original_admin_rows, 'ug_gid');

		// 2. Test Dry-Run: groups, memberships, permissions
		$dry_config = clone $config;
		$dry_config->selected_steps = ['groups', 'group_memberships', 'global_permissions'];
		$dry_config->dry_run = true;
		$dry_config->batch_size = 500;

		$provider_reg = $phpbb_container->get('phpbbseo.migrationcenter.provider_registry');
		$step_reg = $phpbb_container->get('phpbbseo.migrationcenter.step_registry');
		$state_mgr = $phpbb_container->get('phpbbseo.migrationcenter.state_manager');
		$lock_mgr = $phpbb_container->get('phpbbseo.migrationcenter.lock_manager');
		$id_mapper = $phpbb_container->get('phpbbseo.migrationcenter.id_mapper');
		$writer = $phpbb_container->get('phpbbseo.migrationcenter.target_writer');

		$engine = new migration_engine($provider_reg, $step_reg, $state_mgr, $lock_mgr, $id_mapper, $writer);

		$res = $db->sql_query('SELECT count(*) as cnt FROM ' . $table_prefix . 'groups');
		$groups_before = (int)$db->sql_fetchfield('cnt');
		$db->sql_freeresult($res);

		$dry_run = $engine->start_run('xenforo', $dry_config);
		$step_groups = $step_reg->get('groups');
		$step_res = $step_groups->process_batch($dry_run->run_id, 0, 50, $dry_config, $provider_reg->get('xenforo'), $writer);

		if ($step_res->imported_count < 4)
		{
			throw new \Exception("Dry-run groups failed to read source groups");
		}

		$res = $db->sql_query('SELECT count(*) as cnt FROM ' . $table_prefix . 'groups');
		$groups_after_dry = (int)$db->sql_fetchfield('cnt');
		$db->sql_freeresult($res);
		if ($groups_before !== $groups_after_dry)
		{
			throw new \Exception("Dry-run altered target groups count!");
		}

		$lock_mgr->release('migration_xenforo', $dry_run->run_id);

		// 3. Real Migration of Groups & Custom Unicode Group
		$test_run_id = 'test_group_run_' . time();
		$custom_group_source_id = 9999;
		$custom_group_title = "KXXXXXX_VIP_XYXX_UnicodeRunner\xE2\x80\x8CXXX";

		$custom_g = new group_dto();
		$custom_g->source_id = $custom_group_source_id;
		$custom_g->group_name = $custom_group_title;
		$custom_g->is_builtin = false;
		$custom_g->group_colour = 'AA0000';

		$standard_g1 = new group_dto();
		$standard_g1->source_id = 1;
		$standard_g1->group_name = 'Unregistered / Unconfirmed';
		$standard_g1->is_builtin = true;
		$standard_g1->canonical_name = 'GUESTS';

		$standard_g2 = new group_dto();
		$standard_g2->source_id = 2;
		$standard_g2->group_name = 'Registered';
		$standard_g2->is_builtin = true;
		$standard_g2->canonical_name = 'REGISTERED';

		$standard_g3 = new group_dto();
		$standard_g3->source_id = 3;
		$standard_g3->group_name = 'Administrative';
		$standard_g3->is_builtin = true;
		$standard_g3->canonical_name = 'ADMINISTRATORS';

		$standard_g4 = new group_dto();
		$standard_g4->source_id = 4;
		$standard_g4->group_name = 'Moderating';
		$standard_g4->is_builtin = true;
		$standard_g4->canonical_name = 'GLOBAL_MODERATORS';

		$groups_to_write = [$standard_g1, $standard_g2, $standard_g3, $standard_g4, $custom_g];
		$write_res = $writer->write_groups($groups_to_write, [
			'run_id'        => $test_run_id,
			'source_system' => 'xenforo',
		]);

		$custom_target_gid = $id_mapper->get_target_id('xenforo', 'group', $custom_group_source_id);
		if (!$custom_target_gid)
		{
			throw new \Exception("Custom group was not mapped in id_mapper");
		}

		// Verify custom group in database
		$sql = "SELECT group_name FROM {$table_prefix}groups WHERE group_id = " . (int)$custom_target_gid;
		$res = $db->sql_query($sql);
		$saved_gname = $db->sql_fetchfield('group_name');
		$db->sql_freeresult($res);

		if ($saved_gname !== $custom_group_title)
		{
			throw new \Exception("Custom group Unicode round-trip mismatch! Expected '{$custom_group_title}', got '{$saved_gname}'");
		}

		// 4. Test User Membership Reconciliation
		// Create temporary test users in phpbb_users & id_mapper
		$test_user_src_id = 88881;
		$test_admin_src_id = 88882;

		$u1 = new \phpbbseo\migrationcenter\core\dto\user_dto();
		$u1->source_id = $test_user_src_id;
		$u1->username = 'TestUserGroupMember';
		$u1->email = 'testgroup1@invalid.local';
		$u1->group_id = 2;

		$u2 = new \phpbbseo\migrationcenter\core\dto\user_dto();
		$u2->source_id = $test_admin_src_id;
		$u2->username = 'TestAdminGroupMember';
		$u2->email = 'testgroup2@invalid.local';
		$u2->is_admin = true;
		$u2->group_id = 2;

		$u_results = $writer->write_users([$u1, $u2], [
			'run_id'        => $test_run_id,
			'source_system' => 'xenforo',
		]);

		$test_user_target_id = (int)$u_results[$test_user_src_id]['target_id'];
		$test_admin_target_id = (int)$u_results[$test_admin_src_id]['target_id'];

		$memberships = [
			[
				'user_source_id'             => $test_user_src_id,
				'primary_group_source_id'    => 2,
				'secondary_group_source_ids' => [$custom_group_source_id],
				'is_admin'                   => false,
				'is_moderator'               => false,
			],
			[
				'user_source_id'             => $test_admin_src_id,
				'primary_group_source_id'    => 3,
				'secondary_group_source_ids' => [$custom_group_source_id],
				'is_admin'                   => true,
				'is_moderator'               => false,
			],
		];

		$mem_res = $writer->write_group_memberships($memberships, ['source_system' => 'xenforo']);

		// Verify memberships in phpbb_user_group
		$sql = "SELECT group_id FROM {$table_prefix}user_group WHERE user_id = {$test_user_target_id}";
		$res = $db->sql_query($sql);
		$u1_groups = [];
		while ($r = $db->sql_fetchrow($res))
		{
			$u1_groups[] = (int)$r['group_id'];
		}
		$db->sql_freeresult($res);

		if (!in_array((int)$custom_target_gid, $u1_groups, true))
		{
			throw new \Exception("Test user was not added to custom group membership");
		}

		// Verify admin membership in ADMINISTRATORS (group_id = 5)
		$admin_gid = (int)$id_mapper->get_target_id('xenforo', 'group', 3);
		$sql = "SELECT group_id FROM {$table_prefix}user_group WHERE user_id = {$test_admin_target_id}";
		$res = $db->sql_query($sql);
		$u2_groups = [];
		while ($r = $db->sql_fetchrow($res))
		{
			$u2_groups[] = (int)$r['group_id'];
		}
		$db->sql_freeresult($res);

		if (!in_array($admin_gid, $u2_groups, true))
		{
			throw new \Exception("Verified admin was not assigned to ADMINISTRATORS group");
		}

		// Security check: Verify NO founder promotion (user_type MUST remain 0, NOT 3)
		$sql = "SELECT user_type FROM {$table_prefix}users WHERE user_id = {$test_admin_target_id}";
		$res = $db->sql_query($sql);
		$admin_utype = (int)$db->sql_fetchfield('user_type');
		$db->sql_freeresult($res);

		if ($admin_utype === 3)
		{
			throw new \Exception("CRITICAL SECURITY VIOLATION: Migrated admin was granted USER_FOUNDER!");
		}

		// 5. Test Global Permissions Writing & phpBB ACL
		$permissions_to_write = [
			[
				'group_source_id' => $custom_group_source_id,
				'phpbb_option'    => 'u_search',
				'auth_setting'    => 1,
			],
			[
				'group_source_id' => $custom_group_source_id,
				'phpbb_option'    => 'u_viewprofile',
				'auth_setting'    => 1,
			],
		];

		$perm_res = $writer->write_global_permissions($permissions_to_write, ['source_system' => 'xenforo']);

		// Verify in phpbb_acl_groups
		$sql = "SELECT COUNT(*) as cnt FROM {$table_prefix}acl_groups WHERE group_id = " . (int)$custom_target_gid;
		$res = $db->sql_query($sql);
		$acl_count = (int)$db->sql_fetchfield('cnt');
		$db->sql_freeresult($res);

		if ($acl_count < 2)
		{
			throw new \Exception("Failed to write ACL entries for custom group");
		}

		// 6. Test Idempotency / Duplicate Prevention on Rerun
		$mem_res_rerun = $writer->write_group_memberships($memberships, ['source_system' => 'xenforo']);
		$sql = "SELECT COUNT(*) as cnt FROM {$table_prefix}user_group WHERE user_id = {$test_user_target_id} AND group_id = " . (int)$custom_target_gid;
		$res = $db->sql_query($sql);
		$membership_count = (int)$db->sql_fetchfield('cnt');
		$db->sql_freeresult($res);

		if ($membership_count !== 1)
		{
			throw new \Exception("Idempotency failed: found {$membership_count} duplicate memberships for test user!");
		}

		// 7. Verify pre-existing phpBB admin (user_id = 2) is completely untouched
		$sql = "SELECT u.user_id, u.username, u.username_clean, u.user_type, u.group_id, ug.group_id as ug_gid
				FROM {$table_prefix}users u
				LEFT JOIN {$table_prefix}user_group ug ON (u.user_id = ug.user_id)
				WHERE u.user_id = 2";
		$res = $db->sql_query($sql);
		$final_admin_rows = [];
		while ($r = $db->sql_fetchrow($res))
		{
			$final_admin_rows[] = $r;
		}
		$db->sql_freeresult($res);

		$final_admin_ug = array_column($final_admin_rows, 'ug_gid');

		if ($final_admin_rows[0]['username_clean'] !== 'admin' || $final_admin_rows[0]['user_type'] != 3 || $original_admin_ug != $final_admin_ug)
		{
			throw new \Exception("CRITICAL INTEGRITY VIOLATION: Existing phpBB admin account or memberships were modified!");
		}

		// Clean up test data
		$db->sql_query("DELETE FROM {$table_prefix}users WHERE user_id IN ({$test_user_target_id}, {$test_admin_target_id})");
		$db->sql_query("DELETE FROM {$table_prefix}user_group WHERE user_id IN ({$test_user_target_id}, {$test_admin_target_id})");
		$db->sql_query("DELETE FROM {$table_prefix}acl_groups WHERE group_id = " . (int)$custom_target_gid);
		$db->sql_query("DELETE FROM {$table_prefix}groups WHERE group_id = " . (int)$custom_target_gid);
		$db->sql_query("DELETE FROM {$table_prefix}migration_id_map WHERE run_id = '{$test_run_id}'");

		return true;
	}
}
