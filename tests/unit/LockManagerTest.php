<?php
/**
 * Lock Manager Unit Test
 */

namespace phpbbseo\migrationcenter\tests\unit;

use phpbbseo\migrationcenter\core\state\lock_manager;

class LockManagerTest
{
	public function run()
	{
		list($db, $table_prefix) = get_test_db();
		$lock_mgr = new lock_manager($db, $table_prefix, 2); // 2 second timeout for testing stale recovery

		$lock_name = 'test_lock_' . time();
		$run_id_1 = 'run_1_' . time();
		$run_id_2 = 'run_2_' . time();

		// Clean up any leftovers
		$db->sql_query("DELETE FROM {$table_prefix}migration_locks WHERE lock_name = '{$lock_name}'");

		// Test 1: Acquire new lock
		$acquired = $lock_mgr->acquire($lock_name, $run_id_1, 'worker_1');
		if (!$acquired)
		{
			throw new \Exception("Failed to acquire new lock");
		}

		// Test 2: Cannot acquire by another run while active
		$acquired2 = $lock_mgr->acquire($lock_name, $run_id_2, 'worker_2');
		if ($acquired2)
		{
			throw new \Exception("Should not acquire active lock from another run");
		}

		// Test 3: Heartbeat keeps lock alive
		$hb = $lock_mgr->heartbeat($lock_name, $run_id_1);
		if (!$hb)
		{
			throw new \Exception("Heartbeat update failed");
		}

		// Test 4: Is locked
		$info = $lock_mgr->is_locked($lock_name);
		if (!$info || $info['run_id'] !== $run_id_1)
		{
			throw new \Exception("is_locked() returned invalid data");
		}

		// Test 5: Release lock
		$released = $lock_mgr->release($lock_name, $run_id_1);
		if (!$released)
		{
			throw new \Exception("Failed to release lock");
		}

		// Clean up
		$db->sql_query("DELETE FROM {$table_prefix}migration_locks WHERE lock_name = '{$lock_name}'");

		return true;
	}
}
