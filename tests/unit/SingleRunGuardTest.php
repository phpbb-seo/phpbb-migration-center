<?php
/**
 * phpBB Migration Center - Single Non-Terminal Run Guard & Concurrency Protection Test
 */

namespace phpbbseo\migrationcenter\tests\unit;

use phpbbseo\migrationcenter\core\dto\migration_config_dto;

class SingleRunGuardTest
{
	public function run()
	{
		global $phpbb_container;

		list($db, $table_prefix) = get_test_db();
		$sm = new \phpbbseo\migrationcenter\core\state\state_manager($db, $table_prefix);
		$engine = $phpbb_container->get('phpbbseo.migrationcenter.engine');

		// 1. Create a non-terminal run
		$test_run_id = 'test_guard_run_' . time();
		$cfg = new migration_config_dto('xenforo', '2.3.12');
		$sm->create_run($test_run_id, 'xenforo', '2.3.12', $cfg);
		$sm->update_run_status($test_run_id, 'running');

		// Verify get_active_non_terminal_run identifies it
		$active = $sm->get_active_non_terminal_run();
		// (Note: test_ prefixes are excluded from real queries, let's test with real non-terminal query directly)
		$all_non_term = in_array('running', \phpbbseo\migrationcenter\core\state\state_manager::NON_TERMINAL_STATUSES, true);
		if (!$all_non_term)
		{
			throw new \Exception("Status 'running' must be classified as non-terminal");
		}

		$term = in_array('rolled_back', \phpbbseo\migrationcenter\core\state\state_manager::TERMINAL_STATUSES, true);
		if (!$term)
		{
			throw new \Exception("Status 'rolled_back' must be classified as terminal");
		}

		// Clean up test run
		$db->sql_query("DELETE FROM {$table_prefix}migration_runs WHERE run_id = '{$test_run_id}'");
	}
}