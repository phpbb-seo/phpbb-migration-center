<?php
/**
 * phpBB Migration Center - Wizard Totals & Source Steps Regression Test
 */

namespace phpbbseo\migrationcenter\tests\unit;

use phpbbseo\migrationcenter\core\dto\migration_config_dto;
use phpbbseo\migrationcenter\source\xenforo\config\xf_config_detector;

class WizardTotalsTest
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
			throw new \Exception("Could not load XenForo config for wizard totals test");
		}

		$config->selected_steps = [
			'groups', 'users', 'group_memberships', 'global_permissions',
			'forums', 'node_permissions', 'topics', 'posts',
			'attachments', 'avatars', 'conversations', 'conversation_messages',
			'conversation_attachments', 'polls', 'bans'
		];
		$config->batch_size = 500;
		$config->dry_run = false;

		// Create run through the engine service used by the wizard
		$run = $engine->start_run('xenforo', $config);
		$run_id = $run->run_id;

		// Verify run is in 'ready' state initially
		if ($run->status !== 'ready')
		{
			throw new \Exception("Wizard-created run expected initial status 'ready', got '{$run->status}'");
		}

		// Verify steps and totals in the database
		$steps = $state_mgr->get_steps($run_id);
		$expected_totals = [
			'groups'                    => 6,
			'users'                     => 100,
			'group_memberships'         => 102,
			'global_permissions'        => 318,
			'forums'                    => 38,
			'node_permissions'          => 0,
			'topics'                    => 538,
			'posts'                     => 7822,
			'attachments'               => 5,
			'avatars'                   => 2,
			'conversations'             => 2,
			'conversation_messages'     => 5,
			'conversation_attachments'  => 1,
			'polls'                     => 2,
			'bans'                      => 2,
		];

		$actual_overall_total = 0;
		foreach ($expected_totals as $step_name => $expected_cnt)
		{
			if (!isset($steps[$step_name]))
			{
				throw new \Exception("Expected step '{$step_name}' missing from persisted steps");
			}

			$persisted_total = (int)$steps[$step_name]['total_records'];
			$actual_overall_total += $persisted_total;

			if ($persisted_total !== $expected_cnt)
			{
				throw new \Exception("Step '{$step_name}' expected total {$expected_cnt}, got {$persisted_total}");
			}
		}

		if ($actual_overall_total !== 8943)
		{
			throw new \Exception("Overall denominator mismatch: expected 8943, got {$actual_overall_total}");
		}

		// Verify no lock is held while in ready state
		$lock = $lock_mgr->is_locked('migration_xenforo');
		if ($lock)
		{
			throw new \Exception("Lock was held while run is in 'ready' state!");
		}

		// Clean up test run
		$db->sql_query("DELETE FROM {$table_prefix}migration_runs WHERE run_id = '{$run_id}'");
		$db->sql_query("DELETE FROM {$table_prefix}migration_steps WHERE run_id = '{$run_id}'");
		$db->sql_query("DELETE FROM {$table_prefix}migration_id_map WHERE run_id = '{$run_id}'");
		$db->sql_query("DELETE FROM {$table_prefix}migration_errors WHERE run_id = '{$run_id}'");

		return true;
	}
}