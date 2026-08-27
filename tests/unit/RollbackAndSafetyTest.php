<?php
/**
 * phpBB Migration Center - Rollback, Reset & Safety Integration Test
 */

namespace phpbbseo\migrationcenter\tests\unit;

use phpbbseo\migrationcenter\core\dto\migration_config_dto;

class RollbackAndSafetyTest
{
	public function run()
	{
		global $phpbb_container;

		list($db, $table_prefix) = get_test_db();
		$id_mapper = $phpbb_container->get('phpbbseo.migrationcenter.id_mapper');
		$lock_mgr = $phpbb_container->get('phpbbseo.migrationcenter.lock_manager');
		$sm = new \phpbbseo\migrationcenter\core\state\state_manager($db, $table_prefix);
		$rm = new \phpbbseo\migrationcenter\core\rollback\rollback_manager(
			$db,
			$phpbb_container->get('config'),
			$phpbb_container->get('cache.driver'),
			$id_mapper,
			$lock_mgr,
			$sm,
			$table_prefix,
			$phpbb_container->getParameter('core.root_path')
		);
		$engine = $phpbb_container->get('phpbbseo.migrationcenter.engine');

		// 1. Test Rollback keyword confirmation
		$rejected = false;
		try
		{
			$rm->rollback('dummy_run', 'wrong_word');
		}
		catch (\InvalidArgumentException $e)
		{
			$rejected = true;
		}

		if (!$rejected)
		{
			throw new \Exception("Rollback did not reject invalid confirmation word");
		}

		// 2. Test Fast Reset Zero-Import Run
		$fast_run_id = 'fast_reset_test_' . time();
		$cfg = new migration_config_dto('xenforo', '2.3.12');
		$sm->create_run($fast_run_id, 'xenforo', '2.3.12', $cfg);
		$sm->init_steps($fast_run_id, [['step_name' => 'groups', 'step_order' => 1, 'total_records' => 6, 'max_source_id' => 6]]);

		if (!$rm->can_fast_reset($fast_run_id))
		{
			throw new \Exception("Zero-import run should qualify for fast reset");
		}

		$reset_res = $rm->fast_reset($fast_run_id);
		if ($reset_res['status'] !== 'abandoned')
		{
			throw new \Exception("Fast reset did not transition run to abandoned");
		}

		// 3. Test Full Rollback of Created Mappings and Data
		$rb_run_id = 'rollback_test_' . time();
		$sm->create_run($rb_run_id, 'xenforo', '2.3.12', $cfg);
		$sm->init_steps($rb_run_id, [['step_name' => 'groups', 'step_order' => 1, 'total_records' => 6, 'max_source_id' => 6]]);
		$sm->update_step($rb_run_id, 'groups', 'completed', '6', 6, 0, 0);

		// Create mock custom group & map it
		$db->sql_query("INSERT INTO {$table_prefix}groups (group_name, group_desc, group_type) VALUES ('Test Rollback Group', '', 0)");
		$created_group_id = (int)$db->sql_nextid();
		$id_mapper->set($rb_run_id, 'xenforo', 'group', '999', (string)$created_group_id);

		// Execute rollback
		$rb_result = $rm->rollback($rb_run_id, 'ROLLBACK');

		if ($rb_result['status'] !== 'rolled_back')
		{
			throw new \Exception("Rollback did not complete with status rolled_back");
		}

		// Verify custom group was deleted
		$res = $db->sql_query("SELECT COUNT(*) as cnt FROM {$table_prefix}groups WHERE group_id = {$created_group_id}");
		$cnt = (int)$db->sql_fetchfield('cnt');
		$db->sql_freeresult($res);

		if ($cnt !== 0)
		{
			throw new \Exception("Rollback did not delete created custom group");
		}

		// Verify mapping was removed
		$mapped = $id_mapper->get_target_id($rb_run_id, 'xenforo', 'group', '999');
		if ($mapped !== null)
		{
			throw new \Exception("Rollback did not clear id_map entries");
		}

		// Clean up test run
		$db->sql_query("DELETE FROM {$table_prefix}migration_runs WHERE run_id IN ('{$fast_run_id}', '{$rb_run_id}')");
		$db->sql_query("DELETE FROM {$table_prefix}migration_steps WHERE run_id IN ('{$fast_run_id}', '{$rb_run_id}')");
	}
}