<?php
/**
 * phpBB Migration Center Extension - Unified Worker & Stage Order Acceptance Tests
 */

namespace phpbbseo\migrationcenter\tests\unit;

use phpbbseo\migrationcenter\core\dto\migration_config_dto;
use phpbbseo\migrationcenter\core\engine\migration_engine;
use phpbbseo\migrationcenter\core\engine\step_registry;
use phpbbseo\migrationcenter\core\state\lock_manager;
use phpbbseo\migrationcenter\core\state\state_manager;
use phpbbseo\migrationcenter\source\xenforo\config\xf_config_detector;

class UnifiedWorkerAndStageOrderTest
{
	public function run(): void
	{
		global $phpbb_container;
		$db = $phpbb_container->get('dbal.conn');
		$table_prefix = $phpbb_container->getParameter('core.table_prefix');

		$step_registry = $phpbb_container->get('phpbbseo.migrationcenter.step_registry');
		$state_manager = $phpbb_container->get('phpbbseo.migrationcenter.state_manager');
		$lock_manager = $phpbb_container->get('phpbbseo.migrationcenter.lock_manager');
		$engine = $phpbb_container->get('phpbbseo.migrationcenter.engine');

		// ---------------------------------------------------------------------
		// Test 1: Canonical Stage Ordering with Scrambled & Reverse Inputs
		// ---------------------------------------------------------------------
		$scrambled_input = [
			'posts',
			'users',
			'attachments',
			'forums',
			'topics',
			'global_permissions',
			'avatars',
			'groups',
			'node_permissions',
			'group_memberships'
		];
		$resolved = $step_registry->resolve_order($scrambled_input);
		$expected = [
			'groups',
			'users',
			'group_memberships',
			'global_permissions',
			'forums',
			'node_permissions',
			'topics',
			'posts',
			'attachments',
			'avatars'
		];

		if ($resolved !== $expected)
		{
			throw new \Exception("Canonical ordering failed! Expected: " . json_encode($expected) . " Got: " . json_encode($resolved));
		}

		// Reverse input test
		$reverse_input = array_reverse($expected);
		$resolved_rev = $step_registry->resolve_order($reverse_input);
		if ($resolved_rev !== $expected)
		{
			throw new \Exception("Reverse canonical ordering failed! Expected: " . json_encode($expected) . " Got: " . json_encode($resolved_rev));
		}

		// ---------------------------------------------------------------------
		// Test 2: AJAX Batch Execution on Isolated Run & Structured JSON
		// ---------------------------------------------------------------------
		$source_path = 'C:/xampp/htdocs/xen';
		$config = xf_config_detector::detect_from_path($source_path);
		if (!$config)
		{
			$config = new migration_config_dto([
				'source_system' => 'xenforo',
				'source_path'   => 'C:\\xampp\\htdocs\\xen',
				'db_host'       => 'localhost',
				'db_port'       => 3306,
				'db_name'       => 'xen',
				'db_user'       => 'root',
				'db_prefix'     => 'xf_',
				'db_charset'    => 'utf8mb4',
			]);
		}
		$config->batch_size = 500;
		$config->dry_run = true;
		$config->selected_steps = ['groups', 'users', 'topics', 'posts'];

		$run_id = 'test_unified_' . substr(md5(uniqid('', true)), 0, 8);
		$state_manager->create_run($run_id, 'xenforo', '2.3.12', $config);
		$state_manager->init_steps($run_id, [
			['step_name' => 'groups', 'step_order' => 1, 'total_records' => 6, 'max_source_id' => '6'],
			['step_name' => 'users', 'step_order' => 2, 'total_records' => 100, 'max_source_id' => '100'],
			['step_name' => 'topics', 'step_order' => 3, 'total_records' => 538, 'max_source_id' => '538'],
			['step_name' => 'posts', 'step_order' => 4, 'total_records' => 7822, 'max_source_id' => '7822'],
		]);
		$state_manager->update_run_status($run_id, 'ready', 'groups');

		// Execute AJAX batch for Groups
		$ajax_res = $engine->execute_next_batch($run_id, 'ajax', 500, 'ajax_test_worker');

		// Validate required structured JSON fields
		$required_keys = [
			'success', 'run_id', 'run_status', 'worker_type', 'stage_key', 'stage_status',
			'cursor', 'processed', 'created', 'reused', 'updated', 'skipped', 'failed',
			'total', 'percentage', 'rate', 'eta', 'heartbeat_at', 'message', 'next_action',
			'error_code', 'stage_completed', 'awaiting_approval'
		];
		foreach ($required_keys as $k)
		{
			if (!array_key_exists($k, $ajax_res))
			{
				throw new \Exception("Missing required key '{$k}' in AJAX batch response: " . json_encode($ajax_res));
			}
		}

		if ($ajax_res['stage_key'] !== 'groups')
		{
			throw new \Exception("First stage was not groups! Got: " . $ajax_res['stage_key']);
		}

		if (!$ajax_res['stage_completed'] || !$ajax_res['awaiting_approval'])
		{
			throw new \Exception("Groups did not complete with awaiting_approval!");
		}

		// Verify state invariants in DB
		$run_db = $state_manager->get_run($run_id);
		if ($run_db->status !== 'awaiting_approval')
		{
			throw new \Exception("Run status in DB is not awaiting_approval! Got: " . $run_db->status);
		}

		$step_groups = $state_manager->get_step($run_id, 'groups');
		$step_users = $state_manager->get_step($run_id, 'users');
		if ($step_groups['status'] !== 'completed')
		{
			throw new \Exception("Groups step status is not completed!");
		}
		if ($step_users['status'] !== 'pending' || (int)$step_users['imported_records'] !== 0)
		{
			throw new \Exception("Users step was modified prematurely!");
		}

		// ---------------------------------------------------------------------
		// Test 3: Manual Checkpoint Protection
		// Calling execute_next_batch before admin approval must halt
		// ---------------------------------------------------------------------
		$halt_res = $engine->execute_next_batch($run_id, 'ajax');
		if (!$halt_res['awaiting_approval'] || $halt_res['stage_key'] !== 'groups')
		{
			throw new \Exception("Engine did not halt on awaiting_approval!");
		}

		// Approve transition to Users
		$engine->approve_stage_continuation($run_id, 'users');
		$run_db = $state_manager->get_run($run_id);
		if ($run_db->status !== 'running' || $run_db->current_step !== 'users')
		{
			throw new \Exception("Approval did not transition run to running users!");
		}

		// ---------------------------------------------------------------------
		// Test 4: Concurrency Lock Exclusivity (CLI vs AJAX)
		// ---------------------------------------------------------------------
		$cli_run_id = 'test_cli_' . substr(md5(uniqid('', true)), 0, 8);
		$state_manager->create_run($cli_run_id, 'xenforo', '2.3.12', $config);
		$state_manager->init_steps($cli_run_id, [
			['step_name' => 'groups', 'step_order' => 1, 'total_records' => 6, 'max_source_id' => '6'],
		]);
		$state_manager->update_run_status($cli_run_id, 'ready', 'groups');

		// Acquire CLI lock
		$lock_name = 'migration_xenforo';
		$lock_manager->force_release($lock_name);
		$cli_locked = $lock_manager->acquire($lock_name, $cli_run_id, 'cli', 'cli_token_123');
		if (!$cli_locked)
		{
			throw new \Exception("Failed to acquire CLI lock!");
		}

		$lock_info = $lock_manager->get_lock_info($lock_name);
		if ($lock_info['worker_type'] !== 'cli' || $lock_info['is_stale'])
		{
			throw new \Exception("Lock info mismatch for CLI worker: " . json_encode($lock_info));
		}

		// AJAX attempt while CLI lock is active must fail
		$ajax_attempt = $lock_manager->acquire($lock_name, $cli_run_id, 'ajax', 'ajax_token_456');
		if ($ajax_attempt)
		{
			throw new \Exception("AJAX was able to acquire lock while CLI worker is active!");
		}

		// Engine execute batch in AJAX mode must throw exception while CLI is active
		$blocked = false;
		try
		{
			$engine->execute_next_batch($cli_run_id, 'ajax');
		}
		catch (\Throwable $e)
		{
			$blocked = true;
		}
		if (!$blocked)
		{
			throw new \Exception("Engine failed to block AJAX execution while CLI lock was held!");
		}

		// Release CLI lock
		$lock_manager->force_release($lock_name);

		// ---------------------------------------------------------------------
		// Test 5: Abandoned Run Guard & Non-Terminal Query Invariants
		// ---------------------------------------------------------------------
		$abandoned_run_id = 'test_abandoned_' . substr(md5(uniqid('', true)), 0, 8);
		$state_manager->create_run($abandoned_run_id, 'xenforo', '2.3.12', $config);
		$state_manager->update_run_status($abandoned_run_id, 'abandoned');

		$blocked_abandon = false;
		try
		{
			$engine->execute_next_batch($abandoned_run_id, 'ajax');
		}
		catch (\Throwable $e)
		{
			$blocked_abandon = true;
		}
		if (!$blocked_abandon)
		{
			throw new \Exception("Engine allowed execution on abandoned run!");
		}

		// Verify get_active_non_terminal_run excludes abandoned runs
		$active_check = $state_manager->get_active_non_terminal_run();
		if ($active_check && $active_check->status === 'abandoned')
		{
			throw new \Exception("get_active_non_terminal_run returned an abandoned run!");
		}

		// ---------------------------------------------------------------------
		// Clean up test runs
		// ---------------------------------------------------------------------
		$db->sql_query("DELETE FROM {$table_prefix}migration_runs WHERE run_id IN ('{$run_id}', '{$cli_run_id}', '{$abandoned_run_id}')");
		$db->sql_query("DELETE FROM {$table_prefix}migration_steps WHERE run_id IN ('{$run_id}', '{$cli_run_id}', '{$abandoned_run_id}')");
		$db->sql_query("DELETE FROM {$table_prefix}migration_errors WHERE run_id IN ('{$run_id}', '{$cli_run_id}', '{$abandoned_run_id}')");
		$lock_manager->force_release($lock_name);
	}
}
