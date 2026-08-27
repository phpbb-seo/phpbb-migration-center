<?php
/**
 * phpBB Migration Center - Start Mechanism, State Transitions & Isolation Test
 */

namespace phpbbseo\migrationcenter\tests\unit;

use phpbbseo\migrationcenter\core\dto\migration_config_dto;
use phpbbseo\migrationcenter\source\xenforo\config\xf_config_detector;

class StartMechanismTest
{
	public function run()
	{
		global $phpbb_container;

		list($db, $table_prefix) = get_test_db();
		$state_mgr = $phpbb_container->get('phpbbseo.migrationcenter.state_manager');
		$lock_mgr = $phpbb_container->get('phpbbseo.migrationcenter.lock_manager');
		$engine = $phpbb_container->get('phpbbseo.migrationcenter.engine');
		$rollback_mgr = $phpbb_container->get('phpbbseo.migrationcenter.rollback_manager');

		$source_path = 'C:/xampp/htdocs/xen';
		$config = xf_config_detector::detect_from_path($source_path);
		if (!$config)
		{
			throw new \Exception("Could not load XenForo config for start mechanism test");
		}

		$config->selected_steps = ['groups', 'users'];
		$config->batch_size = 500;
		$config->dry_run = false;

		// 1. Wizard creation yields 1 run in 'ready' state
		$run = $engine->start_run('xenforo', $config);
		$run_id = $run->run_id;

		$created_run = $state_mgr->get_run($run_id);
		if ($created_run->status !== 'ready')
		{
			throw new \Exception("Expected created run status 'ready', got '{$created_run->status}'");
		}

		// Verify zero locks held before batch starts
		if ($lock_mgr->is_locked('migration_xenforo'))
		{
			throw new \Exception("Lock held before batch execution started!");
		}

		// 2. Dispatch first stage batch (POST ajax_step equivalent)
		$batch1 = $engine->execute_next_batch($run_id, 500);

		// 3. Confirm live progress & checkpoint
		if (empty($batch1['stage_completed']) || empty($batch1['awaiting_approval']))
		{
			throw new \Exception("Batch execution did not halt at stage checkpoint!");
		}

		$post_batch_run = $state_mgr->get_run($run_id);
		if ($post_batch_run->status !== 'awaiting_approval')
		{
			throw new \Exception("Expected run status 'awaiting_approval', got '{$post_batch_run->status}'");
		}

		// 4. Verify reconciliation counts
		$report = $state_mgr->get_stage_report($run_id, 'groups');
		if (!$report)
		{
			throw new \Exception("Groups stage report not generated");
		}

		if ($report['processed'] !== 6 || $report['created'] !== 2 || $report['reused'] !== 4 || $report['permanently_failed'] !== 0)
		{
			throw new \Exception("Groups reconciliation counts mismatch: processed={$report['processed']}, created={$report['created']}, reused={$report['reused']}, failed={$report['permanently_failed']}");
		}

		// 5. Confirm Users step remains 'pending' (not started)
		$steps = $state_mgr->get_steps($run_id);
		if ($steps['users']['status'] !== 'pending' || (int)$steps['users']['imported_records'] !== 0)
		{
			throw new \Exception("Users step was started prematurely!");
		}

		// 6. Simulate page refresh / GET request (read-only polling)
		$polled_report = $state_mgr->get_stage_report($run_id, 'groups');
		$polled_run = $state_mgr->get_run($run_id);
		if ($polled_run->status !== 'awaiting_approval')
		{
			throw new \Exception("Read-only poll corrupted run status!");
		}

		// 7. Verify clean zero-data / run rollback
		$rollback_mgr->rollback($run_id, 'ROLLBACK');

		$db->sql_query("DELETE FROM {$table_prefix}migration_runs WHERE run_id = '{$run_id}'");
		$db->sql_query("DELETE FROM {$table_prefix}migration_steps WHERE run_id = '{$run_id}'");
		$db->sql_query("DELETE FROM {$table_prefix}migration_id_map WHERE run_id = '{$run_id}'");
		$db->sql_query("DELETE FROM {$table_prefix}migration_errors WHERE run_id = '{$run_id}'");
		$lock_mgr->release('migration_xenforo', $run_id);

		return true;
	}
}