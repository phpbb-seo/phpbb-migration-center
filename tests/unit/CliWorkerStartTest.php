<?php
/**
 * CLI Worker Start Semantics & State Machine Acceptance Tests
 */

namespace phpbbseo\migrationcenter\tests\unit;

use phpbbseo\migrationcenter\core\dto\migration_config_dto;
use phpbbseo\migrationcenter\core\engine\migration_engine;
use phpbbseo\migrationcenter\core\state\lock_manager;
use phpbbseo\migrationcenter\core\state\state_manager;
use phpbbseo\migrationcenter\source\xenforo\config\xf_config_detector;

class CliWorkerStartTest
{
	public function run(): void
	{
		global $phpbb_container;
		$db = $phpbb_container->get('dbal.conn');
		$table_prefix = $phpbb_container->getParameter('core.table_prefix');

		$state_manager = $phpbb_container->get('phpbbseo.migrationcenter.state_manager');
		$lock_manager = $phpbb_container->get('phpbbseo.migrationcenter.lock_manager');
		$engine = $phpbb_container->get('phpbbseo.migrationcenter.engine');

		$source_path = 'C:/xampp/htdocs/xen';
		$config = xf_config_detector::detect_from_path($source_path);
		if (!$config)
		{
			throw new \Exception("Could not load XenForo config for CLI start test");
		}

		$config->selected_steps = ['groups', 'users'];
		$config->batch_size = 50;

		// ---------------------------------------------------------------------
		// Test 1: Start Run initializes to 'ready', NOT 'running'
		// ---------------------------------------------------------------------
		$run = $engine->start_run('xenforo', $config);
		$run_id = $run->run_id;

		$fresh_run = $state_manager->get_run($run_id);
		if ($fresh_run->status !== 'ready')
		{
			throw new \Exception("New run did not initialize in ready state! Status: {$fresh_run->status}");
		}

		// Verify no lock is held
		$lock_name = 'migration_xenforo';
		if ($lock_manager->is_locked($lock_name))
		{
			throw new \Exception("Lock should not be held for a ready run!");
		}

		// Verify steps are pending
		$steps = $state_manager->get_steps($run_id);
		if (empty($steps['groups']) || $steps['groups']['status'] !== 'pending')
		{
			throw new \Exception("Groups step must be pending in ready state!");
		}

		// ---------------------------------------------------------------------
		// Test 2: Preparing CLI transitions ready -> awaiting_worker (NO LOCK, NO RUNNING)
		// ---------------------------------------------------------------------
		$engine->prepare_cli_run($run_id, 'groups');

		$run_prepared = $state_manager->get_run($run_id);
		if ($run_prepared->status !== 'awaiting_worker')
		{
			throw new \Exception("Run did not transition to awaiting_worker! Status: {$run_prepared->status}");
		}

		if (($run_prepared->options['worker_mode'] ?? '') !== 'cli')
		{
			throw new \Exception("Worker mode was not persisted as 'cli'!");
		}

		if (empty($run_prepared->stats['cli_prepared_at']))
		{
			throw new \Exception("cli_prepared_at timestamp was not recorded in stats!");
		}

		// Verify STILL no lock is acquired from the browser
		if ($lock_manager->is_locked($lock_name))
		{
			throw new \Exception("Browser preparation must NOT acquire a worker lock!");
		}

		// Verify Groups step remains pending
		$steps = $state_manager->get_steps($run_id);
		if ($steps['groups']['status'] !== 'pending')
		{
			throw new \Exception("Groups step must remain pending during awaiting_worker! Status: {$steps['groups']['status']}");
		}

		// ---------------------------------------------------------------------
		// Test 3: Cancel CLI preparation returns run status to 'ready'
		// ---------------------------------------------------------------------
		$engine->cancel_cli_prep($run_id);

		$run_cancelled = $state_manager->get_run($run_id);
		if ($run_cancelled->status !== 'ready')
		{
			throw new \Exception("Cancel CLI preparation did not return status to ready! Status: {$run_cancelled->status}");
		}

		if (!empty($run_cancelled->stats['cli_prepared_at']))
		{
			throw new \Exception("cli_prepared_at was not cleaned up on cancellation!");
		}

		// Re-prepare for next test
		$engine->prepare_cli_run($run_id, 'groups');

		// ---------------------------------------------------------------------
		// Test 4: Real CLI worker connects, acquires CLI lock, and transitions to 'running'
		// ---------------------------------------------------------------------
		$worker_token = 'cli_' . getmypid() . '_test';
		$res = $engine->execute_next_batch($run_id, 'cli', 0, $worker_token);

		$run_running = $state_manager->get_run($run_id);
		if ($run_running->status !== 'running' && $run_running->status !== 'awaiting_approval')
		{
			throw new \Exception("CLI batch execution did not transition run to running/completed! Status: {$run_running->status}");
		}

		// ---------------------------------------------------------------------
		// Test 5: Browser cannot execute while CLI is running (Lock protection)
		// ---------------------------------------------------------------------
		// If Groups is still running, lock is active
		if ($run_running->status === 'running')
		{
			$lock_info = $lock_manager->get_lock_info($lock_name);
			if (!$lock_info || $lock_info['worker_type'] !== 'cli')
			{
				throw new \Exception("Lock should be registered as CLI worker!");
			}
		}

		// Complete remaining batches for groups
		while (true)
		{
			$cur = $state_manager->get_run($run_id);
			if (in_array($cur->status, ['awaiting_approval', 'stage_completed', 'completed'], true))
			{
				break;
			}
			$res = $engine->execute_next_batch($run_id, 'cli', 0, $worker_token);
		}

		// ---------------------------------------------------------------------
		// Test 6: CLI stops at checkpoint (awaiting_approval), lock released, Users remains pending (0/100)
		// ---------------------------------------------------------------------
		$run_checkpoint = $state_manager->get_run($run_id);
		if ($run_checkpoint->status !== 'awaiting_approval' && $run_checkpoint->status !== 'stage_completed')
		{
			throw new \Exception("CLI did not stop at stage checkpoint! Status: {$run_checkpoint->status}");
		}

		if ($lock_manager->is_locked($lock_name))
		{
			throw new \Exception("Lock must be released upon reaching stage checkpoint!");
		}

		$steps = $state_manager->get_steps($run_id);
		if ($steps['groups']['status'] !== 'completed')
		{
			throw new \Exception("Groups stage must be completed!");
		}

		if ($steps['users']['status'] !== 'pending')
		{
			throw new \Exception("Users stage must remain pending and NOT start automatically! Status: {$steps['users']['status']}");
		}

		if ((int)$steps['users']['imported_records'] !== 0)
		{
			throw new \Exception("Users must not have imported records before approval!");
		}

		// ---------------------------------------------------------------------
		// Test 7: Startup error persistence
		// ---------------------------------------------------------------------
		$state_manager->set_startup_error($run_id, "Test startup error", "ERR_TEST");
		$run_err = $state_manager->get_run($run_id);
		if (empty($run_err->stats['startup_error']['message']) || $run_err->stats['startup_error']['message'] !== "Test startup error")
		{
			throw new \Exception("Startup error was not persisted!");
		}

		$state_manager->set_startup_error($run_id, '');
		$run_clean = $state_manager->get_run($run_id);
		if (!empty($run_clean->stats['startup_error']))
		{
			throw new \Exception("Startup error was not cleared!");
		}

		// ---------------------------------------------------------------------
		// Test 8: Persian Language file coverage for new CLI keys
		// ---------------------------------------------------------------------
		$fa_file = 'C:/xampp/htdocs/bb/ext/phpbbseo/migrationcenter/language/fa/migrationcenter.php';
		if (!file_exists($fa_file))
		{
			throw new \Exception("Persian language file not found at {$fa_file}");
		}

		$fa_content = file_get_contents($fa_file);
		$required_fa_keys = [
			'STATUS_AWAITING_WORKER',
			'MIGRATION_AWAITING_WORKER',
			'MIGRATION_CLI_PREPARED_TITLE',
			'MIGRATION_CLI_PREPARED_DESC',
			'MIGRATION_PREPARE_CLI_FOR_STAGE',
			'MIGRATION_START_BROWSER_FOR_STAGE',
			'MIGRATION_CMD_COPIED',
			'MIGRATION_CLI_CONNECTED',
			'MIGRATION_I_HAVE_STARTED',
			'MIGRATION_CANCEL_CLI_PREP',
			'MIGRATION_STILL_WAITING_TITLE',
		];

		foreach ($required_fa_keys as $key)
		{
			if (strpos($fa_content, "'{$key}'") === false)
			{
				throw new \Exception("Missing Persian translation key: {$key}");
			}
		}

		// Clean up test run
		$db->sql_query("DELETE FROM {$table_prefix}migration_runs WHERE run_id = '{$run_id}'");
		$db->sql_query("DELETE FROM {$table_prefix}migration_steps WHERE run_id = '{$run_id}'");
		$db->sql_query("DELETE FROM {$table_prefix}migration_id_map WHERE run_id = '{$run_id}'");
	}
}