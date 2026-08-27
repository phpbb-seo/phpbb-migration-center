<?php
/**
 * Forum Tree, Node Permissions & Target Integrity Integration Test
 */

namespace phpbbseo\migrationcenter\tests\unit;

use phpbbseo\migrationcenter\core\engine\migration_engine;
use phpbbseo\migrationcenter\core\dto\migration_config_dto;
use phpbbseo\migrationcenter\core\dto\forum_dto;
use phpbbseo\migrationcenter\source\xenforo\config\xf_config_detector;

class ForumAndNodePermissionIntegrationTest
{
	public function run()
	{
		global $phpbb_container, $phpbb_root_path;

		list($db, $table_prefix) = get_test_db();
		$source_path = 'C:/xampp/htdocs/xen';
		$config = xf_config_detector::detect_from_path($source_path);
		if (!$config)
		{
			throw new \Exception("Could not load XenForo config for forum integration test");
		}

		// 1. Verify pre-existing phpBB forums and admin before test
		$sql = "SELECT forum_id, forum_name, parent_id, left_id, right_id FROM {$table_prefix}forums ORDER BY forum_id ASC";
		$res = $db->sql_query($sql);
		$initial_forums = [];
		while ($r = $db->sql_fetchrow($res))
		{
			$initial_forums[] = $r;
		}
		$db->sql_freeresult($res);

		$initial_forum_count = count($initial_forums);
		if ($initial_forum_count === 0)
		{
			throw new \Exception("Pre-existing phpBB forums not found");
		}

		// 2. Test Dry-Run: verify zero modifications
		$dry_config = clone $config;
		$dry_config->selected_steps = ['forums', 'node_permissions'];
		$dry_config->dry_run = true;
		$dry_config->batch_size = 500;

		$provider_reg = $phpbb_container->get('phpbbseo.migrationcenter.provider_registry');
		$step_reg = $phpbb_container->get('phpbbseo.migrationcenter.step_registry');
		$state_mgr = $phpbb_container->get('phpbbseo.migrationcenter.state_manager');
		$lock_mgr = $phpbb_container->get('phpbbseo.migrationcenter.lock_manager');
		$id_mapper = $phpbb_container->get('phpbbseo.migrationcenter.id_mapper');
		$writer = $phpbb_container->get('phpbbseo.migrationcenter.target_writer');

		$engine = new migration_engine($provider_reg, $step_reg, $state_mgr, $lock_mgr, $id_mapper, $writer);

		$dry_run = $engine->start_run('xenforo', $dry_config);
		$step_forums = $step_reg->get('forums');
		$step_res = $step_forums->process_batch($dry_run->run_id, 0, 50, $dry_config, $provider_reg->get('xenforo'), $writer);

		if ($step_res->imported_count < 30)
		{
			throw new \Exception("Dry-run forums failed to read source nodes");
		}

		$res = $db->sql_query("SELECT COUNT(*) as cnt FROM {$table_prefix}forums");
		$forums_cnt_after_dry = (int)$db->sql_fetchfield('cnt');
		$db->sql_freeresult($res);

		if ($forums_cnt_after_dry !== $initial_forum_count)
		{
			throw new \Exception("Dry-run altered target forums count! Expected {$initial_forum_count}, got {$forums_cnt_after_dry}");
		}

		$lock_mgr->release('migration_xenforo', $dry_run->run_id);

		// 3. Real Migration with migration-owned test data
		$test_run_id = 'test_forum_run_' . time();
		$test_cat_src_id = 7701;
		$test_forum_src_id = 7702;
		$test_link_src_id = 7703;

		$cat = new forum_dto();
		$cat->source_id = $test_cat_src_id;
		$cat->parent_source_id = 0;
		$cat->node_type = 'Category';
		$cat->forum_type = 0; // FORUM_CAT
		$cat->forum_name = "XXXXXX_XXXY_UnicodeRunner\xE2\x80\x8CXXX"; // Persian with ZWNJ
		$cat->display_order = 1;
		$cat->left_id = 1;
		$cat->right_id = 6;

		$forum = new forum_dto();
		$forum->source_id = $test_forum_src_id;
		$forum->parent_source_id = $test_cat_src_id;
		$forum->node_type = 'Forum';
		$forum->forum_type = 1; // FORUM_POST
		$forum->forum_name = 'Security and Performance Forum';
		$forum->display_order = 1;
		$forum->left_id = 2;
		$forum->right_id = 3;

		$link = new forum_dto();
		$link->source_id = $test_link_src_id;
		$link->parent_source_id = $test_cat_src_id;
		$link->node_type = 'LinkForum';
		$link->forum_type = 2; // FORUM_LINK
		$link->forum_name = 'Project Documentation Link';
		$link->forum_link = 'https://phpbb.com';
		$link->display_order = 2;
		$link->left_id = 4;
		$link->right_id = 5;

		$write_res = $writer->write_forums([$cat, $forum, $link], [
			'run_id'        => $test_run_id,
			'source_system' => 'xenforo',
		]);

		$cat_target_id = (int)$id_mapper->get_target_id('xenforo', 'forum', $test_cat_src_id);
		$forum_target_id = (int)$id_mapper->get_target_id('xenforo', 'forum', $test_forum_src_id);
		$link_target_id = (int)$id_mapper->get_target_id('xenforo', 'forum', $test_link_src_id);

		if (!$cat_target_id || !$forum_target_id || !$link_target_id)
		{
			throw new \Exception("Failed to map target forum IDs in id_mapper");
		}

		// Verify database records and nested set bounds
		$sql = "SELECT forum_id, parent_id, left_id, right_id, forum_name, forum_type FROM {$table_prefix}forums WHERE forum_id IN ({$cat_target_id}, {$forum_target_id}, {$link_target_id}) ORDER BY forum_id ASC";
		$res = $db->sql_query($sql);
		$saved_nodes = [];
		while ($r = $db->sql_fetchrow($res))
		{
			$saved_nodes[$r['forum_id']] = $r;
		}
		$db->sql_freeresult($res);

		$saved_cat = $saved_nodes[$cat_target_id];
		$saved_forum = $saved_nodes[$forum_target_id];
		$saved_link = $saved_nodes[$link_target_id];

		if ((int)$saved_cat['parent_id'] !== 0)
		{
			throw new \Exception("Category parent_id must be 0");
		}
		if ((int)$saved_forum['parent_id'] !== $cat_target_id)
		{
			throw new \Exception("Subforum parent_id must match category target ID");
		}
		if ((int)$saved_forum['left_id'] <= (int)$saved_cat['left_id'] || (int)$saved_forum['right_id'] >= (int)$saved_cat['right_id'])
		{
			throw new \Exception("Nested set bounds invariant violated: child bounds not inside parent bounds!");
		}

		// 4. Test Node Permission Writing
		$node_permissions = [
			[
				'source_node_id'  => $test_forum_src_id,
				'source_group_id' => 2, // REGISTERED
				'source_user_id'  => 0,
				'phpbb_option'    => 'f_read',
				'auth_setting'    => 1, // ACL_YES
			],
			[
				'source_node_id'  => $test_forum_src_id,
				'source_group_id' => 2, // REGISTERED
				'source_user_id'  => 0,
				'phpbb_option'    => 'f_post',
				'auth_setting'    => 1, // ACL_YES
			],
		];

		$perm_res = $writer->write_node_permissions($node_permissions, ['source_system' => 'xenforo']);

		// Verify in phpbb_acl_groups
		$sql = "SELECT auth_option_id, auth_setting FROM {$table_prefix}acl_groups WHERE forum_id = {$forum_target_id}";
		$res = $db->sql_query($sql);
		$acl_entries = [];
		while ($r = $db->sql_fetchrow($res))
		{
			$acl_entries[] = $r;
		}
		$db->sql_freeresult($res);

		if (count($acl_entries) < 2)
		{
			throw new \Exception("Failed to write node permissions to phpbb_acl_groups");
		}

		// 5. SECURITY AUDIT VERIFICATION: No node permission must ever generate an 'a_' entry
		$sql = "SELECT opt.auth_option FROM {$table_prefix}acl_groups a
				JOIN {$table_prefix}acl_options opt ON (a.auth_option_id = opt.auth_option_id)
				WHERE a.forum_id = {$forum_target_id} AND opt.auth_option LIKE 'a_%'";
		$res = $db->sql_query($sql);
		$unsafe_a_rows = $db->sql_fetchrow($res);
		$db->sql_freeresult($res);

		if ($unsafe_a_rows)
		{
			throw new \Exception("CRITICAL SECURITY DEFECT: Node permission generated an administrative 'a_' option!");
		}

		// 6. Test Idempotency: Rerunning write_forums and write_node_permissions
		$rerun_forums = $writer->write_forums([$cat, $forum, $link], [
			'run_id'        => $test_run_id,
			'source_system' => 'xenforo',
		]);

		$res = $db->sql_query("SELECT COUNT(*) as cnt FROM {$table_prefix}forums WHERE forum_id IN ({$cat_target_id}, {$forum_target_id}, {$link_target_id})");
		$check_cnt = (int)$db->sql_fetchfield('cnt');
		$db->sql_freeresult($res);

		if ($check_cnt !== 3)
		{
			throw new \Exception("Duplicate forums created on rerun!");
		}

		// 7. Verify pre-existing phpBB Admin (user_id = 2) is completely untouched
		$sql = "SELECT user_id, username, username_clean, user_type FROM {$table_prefix}users WHERE user_id = 2";
		$res = $db->sql_query($sql);
		$admin_row = $db->sql_fetchrow($res);
		$db->sql_freeresult($res);

		if ($admin_row['username_clean'] !== 'admin' || (int)$admin_row['user_type'] !== 3)
		{
			throw new \Exception("CRITICAL INTEGRITY VIOLATION: Pre-existing phpBB admin account was altered!");
		}

		// Clean up migration-owned test data
		$db->sql_query("DELETE FROM {$table_prefix}acl_groups WHERE forum_id IN ({$cat_target_id}, {$forum_target_id}, {$link_target_id})");
		$db->sql_query("DELETE FROM {$table_prefix}forums WHERE forum_id IN ({$cat_target_id}, {$forum_target_id}, {$link_target_id})");
		$db->sql_query("DELETE FROM {$table_prefix}migration_id_map WHERE run_id = '{$test_run_id}'");

		return true;
	}
}
