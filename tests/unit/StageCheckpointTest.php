<?php
/**
 * phpBB Migration Center - Stage Checkpoint & Approval Lifecycle Integration Test
 */

namespace phpbbseo\migrationcenter\tests\unit;

use phpbbseo\migrationcenter\core\dto\migration_config_dto;
use phpbbseo\migrationcenter\source\xenforo\config\xf_config_detector;

class StageCheckpointTest
{
	public function run()
	{
		global $phpbb_container;

		list($db, $table_prefix) = get_test_db();
		$state_mgr = $phpbb_container->get('phpbbseo.migrationcenter.state_manager');
		$lock_mgr = $phpbb_container->get('phpbbseo.migrationcenter.lock_manager');
		$engine = $phpbb_container->get('phpbbseo.migrationcenter.engine');

		$source_path = 'C:/xampp/htdocs/xen';
		$config = xf_config_detector::detect_from_path($source_path);
		if (!$config)
		{
			throw new \Exception("Could not load XenForo config for stage checkpoint test");
		}

		$config->selected_steps = ['groups', 'users'];
		$config->batch_size = 100;
		$config->dry_run = false;

		// 1. Start a single migration lifecycle
		$run = $engine->start_run('xenforo', $config);
		$run_id = $run->run_id;

		// Verify attempting to start a second run throws an exception
		$duplicate_blocked = false;
		try
		{
			$engine->start_run('xenforo', $config);
		}
		catch (\RuntimeException $e)
		{
			$duplicate_blocked = true;
		}

		if (!$duplicate_blocked)
		{
			throw new \Exception("Single lifecycle violation: Second concurrent run was not blocked!");
		}

		// 2. Execute batch for stage 1 (groups)
		$batch1 = $engine->execute_next_batch($run_id, 100);

		// Verify stage 1 completed and halted automatically
		if (empty($batch1['stage_completed']) || empty($batch1['awaiting_approval']))
		{
			throw new \Exception("Stage groups did not halt automatically at stage checkpoint!");
		}

		// Verify run status in state manager
		$current_run = $state_mgr->get_run($run_id);
		if ($current_run->status !== 'awaiting_approval')
		{
			throw new \Exception("Run status expected 'awaiting_approval', got '{$current_run->status}'");
		}

		// 3. Verify Stage Reconciliation Report Invariant
		$report = $state_mgr->get_stage_report($run_id, 'groups');
		if (!$report)
		{
			throw new \Exception("Stage reconciliation report for 'groups' was not generated");
		}

		$calculated_processed = $report['created'] + $report['reused'] + $report['updated'] + $report['skipped'] + $report['permanently_failed'];
		if ($report['processed'] !== $calculated_processed)
		{
			throw new \Exception("Stage report invariant violation: processed ({$report['processed']}) != created+reused+updated+skipped+failed ({$calculated_processed})");
		}

		if ($report['next_stage'] !== 'users')
		{
			throw new \Exception("Expected next stage 'users', got '{$report['next_stage']}'");
		}

		// 4. Verify batch execution does NOT proceed while awaiting approval
		$batch_blocked = $engine->execute_next_batch($run_id, 100);
		if (empty($batch_blocked['awaiting_approval']) || $batch_blocked['status'] !== 'awaiting_approval')
		{
			throw new \Exception("execute_next_batch bypassed stage approval gate!");
		}

		// 5. Explicitly approve next stage ('users')
		$engine->approve_stage_continuation($run_id, 'users');
		$approved_run = $state_mgr->get_run($run_id);
		if ($approved_run->status !== 'running' || $approved_run->current_step !== 'users')
		{
			throw new \Exception("Stage continuation approval failed to set status 'running' and step 'users'");
		}

		// 6. Test Warning & Failure Gating
		// Log a test warning and complete stage with warning
		$state_mgr->log_error($run_id, 'users', 'WARN_TEST', 'Test warning for gating check', 'warning');
		$warn_report = $state_mgr->complete_stage($run_id, 'users', ['reused' => 0, 'created' => 0]);
		if ($warn_report['stage_status'] !== 'stage_completed_with_warnings')
		{
			throw new \Exception("Stage with warning expected status 'stage_completed_with_warnings', got '{$warn_report['stage_status']}'");
		}

		// 7. Clean up test data and rollback
		$rollback_mgr = $phpbb_container->get('phpbbseo.migrationcenter.rollback_manager');
		$rollback_mgr->rollback($run_id, 'ROLLBACK');

		$db->sql_query("DELETE FROM {$table_prefix}migration_runs WHERE run_id = '{$run_id}'");
		$db->sql_query("DELETE FROM {$table_prefix}migration_steps WHERE run_id = '{$run_id}'");
		$db->sql_query("DELETE FROM {$table_prefix}migration_id_map WHERE run_id = '{$run_id}'");
		$db->sql_query("DELETE FROM {$table_prefix}migration_errors WHERE run_id = '{$run_id}'");
		$lock_mgr->release('migration_xenforo', $run_id);

		return true;
	}
}