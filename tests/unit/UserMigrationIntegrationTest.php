<?php
/**
 * User Migration Complete Vertical Slice Integration Test
 */

namespace phpbbseo\migrationcenter\tests\unit;

use phpbbseo\migrationcenter\core\engine\migration_engine;
use phpbbseo\migrationcenter\core\dto\migration_config_dto;
use phpbbseo\migrationcenter\source\xenforo\config\xf_config_detector;

class UserMigrationIntegrationTest
{
	public function run()
	{
		global $phpbb_container, $phpbb_root_path;

		list($db, $table_prefix) = get_test_db();
		$source_path = 'C:/xampp/htdocs/xen';
		$config = xf_config_detector::detect_from_path($source_path);
		if (!$config)
		{
			throw new \Exception("Could not load XenForo config for integration test");
		}

		// 1. Verify Existing phpBB Admin Integrity before test
		$sql = "SELECT user_id, username, username_clean, user_password, user_type FROM {$table_prefix}users WHERE user_id = 2";
		$res = $db->sql_query($sql);
		$original_admin = $db->sql_fetchrow($res);
		$db->sql_freeresult($res);

		if (!$original_admin || $original_admin['username_clean'] !== 'admin')
		{
			throw new \Exception("Pre-existing phpBB admin account not found at user_id 2");
		}

		$original_admin_password_hash = $original_admin['user_password'];
		$res = $db->sql_query('SELECT count(*) as cnt FROM ' . $table_prefix . 'users');
		$initial_user_count = (int)$db->sql_fetchfield('cnt');
		$db->sql_freeresult($res);

		// 2. Test Dry-Run: verify zero modifications
		$dry_config = clone $config;
		$dry_config->selected_steps = ['users'];
		$dry_config->batch_size = 5;
		$dry_config->dry_run = true;

		$provider_reg = $phpbb_container->get('phpbbseo.migrationcenter.provider_registry');
		$step_reg = $phpbb_container->get('phpbbseo.migrationcenter.step_registry');
		$state_mgr = $phpbb_container->get('phpbbseo.migrationcenter.state_manager');
		$lock_mgr = $phpbb_container->get('phpbbseo.migrationcenter.lock_manager');
		$id_mapper = $phpbb_container->get('phpbbseo.migrationcenter.id_mapper');
		$writer = $phpbb_container->get('phpbbseo.migrationcenter.target_writer');

		$engine = new migration_engine($provider_reg, $step_reg, $state_mgr, $lock_mgr, $id_mapper, $writer);

		$dry_run = $engine->start_run('xenforo', $dry_config);
		$batch1 = $engine->execute_next_batch($dry_run->run_id, 5);

		if ($batch1['read_count'] !== 5 || $batch1['imported_count'] !== 5)
		{
			throw new \Exception("Dry run batch 1 unexpected results");
		}

		// Check DB user count remains unchanged after dry-run
		$sql = "SELECT COUNT(*) as cnt FROM {$table_prefix}users";
		$res = $db->sql_query($sql);
		$current_cnt = (int)$db->sql_fetchfield('cnt');
		$db->sql_freeresult($res);

		if ($current_cnt !== $initial_user_count)
		{
			throw new \Exception("Dry-run altered target users count! Expected {$initial_user_count}, got {$current_cnt}");
		}

		// Release lock and mark dry run abandoned
		$lock_mgr->release('migration_xenforo', $dry_run->run_id);
		$state_mgr->update_run_status($dry_run->run_id, 'abandoned');

		// 3. Create a dedicated XenForo test user with known password for Real Login Verification
		$known_plain_password = 'KnownTestPassword123!';
		$test_user_id = 99999;
		$test_username = 'TestLoginMigratedUser';
		$test_email = 'test_login_migrated@example.com';
		$known_bcrypt_hash = password_hash($known_plain_password, PASSWORD_BCRYPT, ['cost' => 10]);

		// Create in XenForo test database
		$xf_pdo = new \PDO("mysql:host=" . ($config->db_host ?: 'localhost') . ";dbname=" . $config->db_name, $config->db_user, $config->db_password);
		$xf_pdo->exec("DELETE FROM xf_user WHERE user_id = {$test_user_id}");
		$xf_pdo->exec("DELETE FROM xf_user_authenticate WHERE user_id = {$test_user_id}");
		$xf_pdo->exec("DELETE FROM xf_user_profile WHERE user_id = {$test_user_id}");

		$stmt = $xf_pdo->prepare("INSERT INTO xf_user (user_id, username, email, language_id, style_id, timezone, user_group_id, secondary_group_ids, permission_combination_id, secret_key, register_date, last_activity, message_count, user_state, is_banned, is_admin, is_moderator) VALUES (?, ?, ?, 1, 0, 'UTC', 2, '', 1, '', UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), 10, 'valid', 0, 0, 0)");
		$stmt->execute([$test_user_id, $test_username, $test_email]);

		$auth_payload = serialize(['hash' => $known_bcrypt_hash]);
		$stmt = $xf_pdo->prepare("INSERT INTO xf_user_authenticate (user_id, scheme_class, data) VALUES (?, 'XF:Core12', ?)");
		$stmt->execute([$test_user_id, $auth_payload]);

		// 4. Run real migration for this test user
		$real_config = clone $config;
		$real_config->selected_steps = ['users'];
		$real_config->batch_size = 1;
		$real_config->dry_run = false;
		$real_config->preserve_ids = false;

		$real_run = $engine->start_run('xenforo', $real_config);

		// Execute batch on test user ID
		$step_handler = $step_reg->get('users');
		$step_res = $step_handler->process_batch(
			$real_run->run_id,
			$test_user_id - 1, // cursor just before test user
			1,
			$real_config,
			$provider_reg->get('xenforo'),
			$writer
		);

		if ($step_res->imported_count !== 1)
		{
			throw new \Exception("Failed to import dedicated test user");
		}

		$target_user_id = (int)$id_mapper->get_target_id('xenforo', 'user', $test_user_id);
		if ($target_user_id <= 0)
		{
			throw new \Exception("ID mapping was not created for dedicated test user");
		}

		// 5. Test Duplicate Prevention (Rerunning same user)
		$step_res_dup = $step_handler->process_batch(
			$real_run->run_id,
			$test_user_id - 1,
			1,
			$real_config,
			$provider_reg->get('xenforo'),
			$writer
		);
		// Should be skipped/mapped without duplicate row
		$sql = "SELECT COUNT(*) as cnt FROM {$table_prefix}users WHERE username = '{$test_username}'";
		$res = $db->sql_query($sql);
		$dup_cnt = (int)$db->sql_fetchfield('cnt');
		$db->sql_freeresult($res);

		if ($dup_cnt !== 1)
		{
			throw new \Exception("Duplicate prevention failed: found {$dup_cnt} records for test user!");
		}

		// 6. REAL LOGIN VERIFICATION:
		// A. Incorrect password rejection
		$passwords_manager = $phpbb_container->get('passwords.manager');
		$sql = "SELECT * FROM {$table_prefix}users WHERE user_id = {$target_user_id}";
		$res = $db->sql_query($sql);
		$migrated_user_row = $db->sql_fetchrow($res);
		$db->sql_freeresult($res);

		$wrong_login_ok = $passwords_manager->check('WrongPass123', $migrated_user_row['user_password'], $migrated_user_row);
		if ($wrong_login_ok)
		{
			throw new \Exception("Security failure: incorrect password was accepted for migrated user!");
		}

		// B. Correct known password acceptance
		$correct_login_ok = $passwords_manager->check($known_plain_password, $migrated_user_row['user_password'], $migrated_user_row);
		if (!$correct_login_ok)
		{
			throw new \Exception("Login verification failed: known password was rejected for migrated user!");
		}

		// C. Rehash simulation (phpBB auth db behavior)
		if ($passwords_manager->convert_flag)
		{
			$new_hash = $passwords_manager->hash($known_plain_password);
			$sql = "UPDATE {$table_prefix}users SET user_password = '" . $db->sql_escape($new_hash) . "' WHERE user_id = {$target_user_id}";
			$db->sql_query($sql);
		}

		// D. Second login check with updated native hash
		$sql = "SELECT * FROM {$table_prefix}users WHERE user_id = {$target_user_id}";
		$res = $db->sql_query($sql);
		$rehashed_user_row = $db->sql_fetchrow($res);
		$db->sql_freeresult($res);

		$second_login_ok = $passwords_manager->check($known_plain_password, $rehashed_user_row['user_password'], $rehashed_user_row);
		if (!$second_login_ok)
		{
			throw new \Exception("Second login failed after password rehash!");
		}

		// 7. Verify phpBB Admin account was completely untouched
		$sql = "SELECT user_id, username, username_clean, user_password, user_type FROM {$table_prefix}users WHERE user_id = 2";
		$res = $db->sql_query($sql);
		$final_admin = $db->sql_fetchrow($res);
		$db->sql_freeresult($res);

		if ($final_admin['username_clean'] !== 'admin' || $final_admin['user_password'] !== $original_admin_password_hash)
		{
			throw new \Exception("CRITICAL INTEGRITY VIOLATION: Existing phpBB admin account was modified!");
		}

		// Clean up only the dedicated test user
		$db->sql_query("DELETE FROM {$table_prefix}users WHERE user_id = {$target_user_id}");
		$db->sql_query("DELETE FROM {$table_prefix}user_group WHERE user_id = {$target_user_id}");
		$db->sql_query("DELETE FROM {$table_prefix}migration_id_map WHERE run_id = '{$real_run->run_id}'");
		$db->sql_query("DELETE FROM {$table_prefix}migration_runs WHERE run_id = '{$real_run->run_id}'");
		$db->sql_query("DELETE FROM {$table_prefix}migration_steps WHERE run_id = '{$real_run->run_id}'");
		$lock_mgr->release('migration_xenforo', $real_run->run_id);

		$xf_pdo->exec("DELETE FROM xf_user WHERE user_id = {$test_user_id}");
		$xf_pdo->exec("DELETE FROM xf_user_authenticate WHERE user_id = {$test_user_id}");
		$xf_pdo->exec("DELETE FROM xf_user_profile WHERE user_id = {$test_user_id}");

		return true;
	}
}
