<?php
/**
 * phpBB Migration Center - Rollback, Reset & Fail-Closed Safety Integration Test
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

		// 3. Test Fail-Closed Rollback Safety Matrix
		$rb_run_id = 'rollback_test_' . time();
		$sm->create_run($rb_run_id, 'xenforo', '2.3.12', $cfg);
		$sm->init_steps($rb_run_id, [['step_name' => 'groups', 'step_order' => 1, 'total_records' => 10, 'max_source_id' => 10]]);
		$sm->update_step($rb_run_id, 'groups', 'completed', '10', 10, 0, 0);

		// Case A: Missing metadata -> must NOT delete
		$db->sql_query("INSERT INTO {$table_prefix}groups (group_name, group_desc, group_type) VALUES ('Group Missing Meta', '', 0)");
		$gid_missing_meta = (int)$db->sql_nextid();
		$db->sql_query("INSERT INTO {$table_prefix}migration_id_map (run_id, source_system, content_type, source_id, target_id, status, metadata_json, created_at)
			VALUES ('{$rb_run_id}', 'xenforo', 'group', '101', '{$gid_missing_meta}', 'mapped', '', " . time() . ")");

		// Case B: Malformed JSON metadata -> must NOT delete
		$db->sql_query("INSERT INTO {$table_prefix}groups (group_name, group_desc, group_type) VALUES ('Group Malformed JSON', '', 0)");
		$gid_malformed_json = (int)$db->sql_nextid();
		$db->sql_query("INSERT INTO {$table_prefix}migration_id_map (run_id, source_system, content_type, source_id, target_id, status, metadata_json, created_at)
			VALUES ('{$rb_run_id}', 'xenforo', 'group', '102', '{$gid_malformed_json}', 'mapped', '{invalid_json', " . time() . ")");

		// Case C: Ownership missing in metadata -> must NOT delete
		$db->sql_query("INSERT INTO {$table_prefix}groups (group_name, group_desc, group_type) VALUES ('Group Ownership Missing', '', 0)");
		$gid_ownership_missing = (int)$db->sql_nextid();
		$id_mapper->set($rb_run_id, 'xenforo', 'group', '103', (string)$gid_ownership_missing, 'mapped', '', ['some_key' => 1]);

		// Case D: Ownership unknown -> must NOT delete
		$db->sql_query("INSERT INTO {$table_prefix}groups (group_name, group_desc, group_type) VALUES ('Group Ownership Unknown', '', 0)");
		$gid_ownership_unknown = (int)$db->sql_nextid();
		$id_mapper->set($rb_run_id, 'xenforo', 'group', '104', (string)$gid_ownership_unknown, 'mapped', '', ['ownership' => 'unknown_policy']);

		// Case E: Ownership reused -> must NOT delete
		$db->sql_query("INSERT INTO {$table_prefix}groups (group_name, group_desc, group_type) VALUES ('Group Reused Custom', '', 0)");
		$gid_ownership_reused = (int)$db->sql_nextid();
		$id_mapper->set($rb_run_id, 'xenforo', 'group', '105', (string)$gid_ownership_reused, 'reused', '', ['ownership' => 'reused']);

		// Case F: Valid run-owned created group -> MUST BE DELETED
		$db->sql_query("INSERT INTO {$table_prefix}groups (group_name, group_desc, group_type) VALUES ('Group Valid Created', '', 0)");
		$gid_valid_created = (int)$db->sql_nextid();
		$id_mapper->set($rb_run_id, 'xenforo', 'group', '106', (string)$gid_valid_created, 'created', '', ['ownership' => 'created', 'builtin' => false]);

		// Execute Rollback
		$rb_result = $rm->rollback($rb_run_id, 'ROLLBACK');

		if ($rb_result['status'] !== 'rolled_back')
		{
			throw new \Exception("Rollback did not complete with status rolled_back");
		}

		// Verify Fail-Closed Assertions:
		// Case A survived
		$res = $db->sql_query("SELECT COUNT(*) as cnt FROM {$table_prefix}groups WHERE group_id = {$gid_missing_meta}");
		$cnt = (int)$db->sql_fetchfield('cnt');
		$db->sql_freeresult($res);
		if ($cnt !== 1) throw new \Exception("Fail-Closed Failure: Group with missing metadata was deleted");

		// Case B survived
		$res = $db->sql_query("SELECT COUNT(*) as cnt FROM {$table_prefix}groups WHERE group_id = {$gid_malformed_json}");
		$cnt = (int)$db->sql_fetchfield('cnt');
		$db->sql_freeresult($res);
		if ($cnt !== 1) throw new \Exception("Fail-Closed Failure: Group with malformed JSON was deleted");

		// Case C survived
		$res = $db->sql_query("SELECT COUNT(*) as cnt FROM {$table_prefix}groups WHERE group_id = {$gid_ownership_missing}");
		$cnt = (int)$db->sql_fetchfield('cnt');
		$db->sql_freeresult($res);
		if ($cnt !== 1) throw new \Exception("Fail-Closed Failure: Group with ownership missing was deleted");

		// Case D survived
		$res = $db->sql_query("SELECT COUNT(*) as cnt FROM {$table_prefix}groups WHERE group_id = {$gid_ownership_unknown}");
		$cnt = (int)$db->sql_fetchfield('cnt');
		$db->sql_freeresult($res);
		if ($cnt !== 1) throw new \Exception("Fail-Closed Failure: Group with unknown ownership was deleted");

		// Case E survived
		$res = $db->sql_query("SELECT COUNT(*) as cnt FROM {$table_prefix}groups WHERE group_id = {$gid_ownership_reused}");
		$cnt = (int)$db->sql_fetchfield('cnt');
		$db->sql_freeresult($res);
		if ($cnt !== 1) throw new \Exception("Fail-Closed Failure: Group with reused ownership was deleted");

		// Case F was deleted
		$res = $db->sql_query("SELECT COUNT(*) as cnt FROM {$table_prefix}groups WHERE group_id = {$gid_valid_created}");
		$cnt = (int)$db->sql_fetchfield('cnt');
		$db->sql_freeresult($res);
		if ($cnt !== 0) throw new \Exception("Positive Ownership Failure: Valid created group was not deleted");

		// Cleanup test preserved groups
		$db->sql_query("DELETE FROM {$table_prefix}groups WHERE group_id IN ({$gid_missing_meta}, {$gid_malformed_json}, {$gid_ownership_missing}, {$gid_ownership_unknown}, {$gid_ownership_reused})");
		$db->sql_query("DELETE FROM {$table_prefix}migration_runs WHERE run_id IN ('{$fast_run_id}', '{$rb_run_id}')");
		$db->sql_query("DELETE FROM {$table_prefix}migration_steps WHERE run_id IN ('{$fast_run_id}', '{$rb_run_id}')");
	}
}