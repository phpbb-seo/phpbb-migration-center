<?php
/**
 * phpBB Migration Center Extension
 *
 * @copyright (c) 2026 phpBB SEO Team
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace phpbbseo\migrationcenter\tests\integration\source\vbulletin;

use phpbbseo\migrationcenter\core\dto\migration_config_dto;
use phpbbseo\migrationcenter\core\engine\migration_engine;
use phpbbseo\migrationcenter\source\vbulletin\vbulletin_source_provider;
use phpbbseo\migrationcenter\source\vbulletin\step\groups_step;

/**
 * Integration Test for vBulletin Groups Migration Step & Rollback Safety
 */
class vb_groups_step_test
{
	public function run(): array
	{
		global $phpbb_container;

		$results = [];

		$env_lines = file('C:/vb-migration-lab/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
		$env = [];
		foreach ($env_lines as $l) {
			if (strpos(trim($l), '#') === 0 || strpos($l, '=') === false) continue;
			list($k, $v) = explode('=', $l, 2);
			$env[trim($k)] = trim($v);
		}

		$db = $phpbb_container->get('dbal.conn');
		$prefix = $phpbb_container->getParameter('core.table_prefix');

		$id_mapper = $phpbb_container->get('phpbbseo.migrationcenter.id_mapper');
		$state_mgr = $phpbb_container->get('phpbbseo.migrationcenter.state_manager');
		$lock_mgr  = $phpbb_container->get('phpbbseo.migrationcenter.lock_manager');
		$writer    = $phpbb_container->get('phpbbseo.migrationcenter.target_writer');
		$provider_reg = $phpbb_container->get('phpbbseo.migrationcenter.provider_registry');
		$step_reg     = $phpbb_container->get('phpbbseo.migrationcenter.step_registry');

		// 1. Insert historical run to prove scoped cleanup & rollback isolation
		$historical_run_id = 'historical_vb_test_' . time();
		$db->sql_query("INSERT INTO {$prefix}migration_runs (run_id, source_system, source_version, status, current_step, started_at, created_at, options_json, stats_json)
			VALUES ('{$historical_run_id}', 'xenforo', '2.3.12', 'finalized', 'finalization', " . time() . ", " . time() . ", '{}', '{}')");
		$db->sql_query("INSERT INTO {$prefix}migration_steps (run_id, step_name, status, step_order, total_records, imported_records, skipped_records, failed_records, current_cursor, max_source_id, started_at, completed_at, stats_json)
			VALUES ('{$historical_run_id}', 'groups', 'completed', 1, 6, 6, 0, 0, '6', '6', " . time() . ", " . time() . ", '{}')");
		$db->sql_query("INSERT INTO {$prefix}migration_id_map (run_id, source_system, content_type, source_id, target_id, status, metadata_json, created_at)
			VALUES ('{$historical_run_id}', 'xenforo', 'group', '99', '2', 'reused', '{\"ownership\":\"reused\"}', " . time() . ")");
		$db->sql_query("INSERT INTO {$prefix}migration_errors (run_id, step_name, content_type, source_id, severity, error_code, message, context_json, created_at)
			VALUES ('{$historical_run_id}', 'groups', 'group', '99', 'error', 'TEST_HISTORICAL', 'Historical error entry', '{}', " . time() . ")");
		$db->sql_query("INSERT INTO {$prefix}migration_locks (lock_name, run_id, locked_at, heartbeat_at, worker_id)
			VALUES ('test_historical_lock', '{$historical_run_id}', " . time() . ", " . time() . ", 'tok_hist')");

		$id_mapper->clear_cache();

		// Insert 3 pre-existing custom groups to prove rollback never deletes them
		$db->sql_query("INSERT INTO {$prefix}groups (group_name, group_desc, group_desc_bitfield, group_desc_options, group_desc_uid, group_type, group_colour, group_rank, group_receive_pm, group_legend, group_message_limit, group_max_recipients, group_skip_auth)
			VALUES ('PRE_EXISTING_VIP', 'Pre-existing VIP group', '', 7, '', 0, 'FFAA00', 0, 1, 0, 0, 0, 0)");
		$pre_vip_id = (int)$db->sql_nextid();

		$db->sql_query("INSERT INTO {$prefix}groups (group_name, group_desc, group_desc_bitfield, group_desc_options, group_desc_uid, group_type, group_colour, group_rank, group_receive_pm, group_legend, group_message_limit, group_max_recipients, group_skip_auth)
			VALUES ('PRE_EXISTING_DONORS', 'Pre-existing Donors group', '', 7, '', 0, '00AAFF', 0, 1, 0, 0, 0, 0)");
		$pre_donors_id = (int)$db->sql_nextid();

		$db->sql_query("INSERT INTO {$prefix}groups (group_name, group_desc, group_desc_bitfield, group_desc_options, group_desc_uid, group_type, group_colour, group_rank, group_receive_pm, group_legend, group_message_limit, group_max_recipients, group_skip_auth)
			VALUES ('PRE_EXISTING_STAFF', 'Pre-existing Staff group', '', 7, '', 1, 'AA00AA', 0, 1, 0, 0, 0, 0)");
		$pre_staff_id = (int)$db->sql_nextid();

		$res = $db->sql_query("SELECT COUNT(*) as cnt FROM {$prefix}groups");
		$initial_total_groups = (int)$db->sql_fetchfield('cnt');
		$db->sql_freeresult($res);

		// 2. Configure vB3 Groups Migration Run
		$cfg3 = new migration_config_dto();
		$cfg3->source_system = 'vbulletin';
		$cfg3->db_host = '127.0.0.1';
		$cfg3->db_port = 3307;
		$cfg3->db_name = 'vb3_test';
		$cfg3->db_user = 'migration_vb3_readonly';
		$cfg3->db_password = $env['VB3_DB_PASSWORD'] ?? 'vb3_lab_secret_pass_2026';
		$cfg3->source_path = 'C:/vb-migration-lab/vb3';
		$cfg3->selected_steps = ['groups'];
		$cfg3->batch_size = 50;

		$engine = new migration_engine($provider_reg, $step_reg, $state_mgr, $lock_mgr, $id_mapper, $writer);

		// Start run
		$run = $engine->start_run('vbulletin', $cfg3);
		$run_id = $run->run_id;

		$results['vb3_run_started'] = !empty($run_id);

		// Execute Groups Batch
		$batch_res = $engine->execute_next_batch($run_id, 'browser', 50);

		$results['vb3_groups_batch_success'] = ($batch_res['success'] === true);
		$results['vb3_groups_processed_8']   = ($batch_res['processed'] === 8);
		$results['vb3_stage_completed']      = ($batch_res['stage_completed'] === true);
		$results['vb3_awaiting_approval']    = ($batch_res['awaiting_approval'] === true);

		// Check Reconciliation Details
		$results['vb3_created_count'] = ($batch_res['created'] === 2); // Moderators (7) + Banned (8)
		$results['vb3_reused_count']  = ($batch_res['reused'] === 6);  // Unreg(1), Reg(2), Awaiting(3), COPPA(4), SuperMods(5), Admins(6)

		// 3. Verify Semantic Resolution & ID mappings in database
		$g1_map = $id_mapper->get_target_id('vbulletin', 'group', 1);
		$g2_map = $id_mapper->get_target_id('vbulletin', 'group', 2);
		$g5_map = $id_mapper->get_target_id('vbulletin', 'group', 5);
		$g6_map = $id_mapper->get_target_id('vbulletin', 'group', 6);
		$g7_map = $id_mapper->get_target_id('vbulletin', 'group', 7);
		$g8_map = $id_mapper->get_target_id('vbulletin', 'group', 8);

		// Verify target groups are resolved semantically by group_name
		$res = $db->sql_query("SELECT group_id FROM {$prefix}groups WHERE group_name = 'GUESTS'");
		$canonical_guests_id = (int)$db->sql_fetchfield('group_id');
		$db->sql_freeresult($res);

		$res = $db->sql_query("SELECT group_id FROM {$prefix}groups WHERE group_name = 'REGISTERED'");
		$canonical_reg_id = (int)$db->sql_fetchfield('group_id');
		$db->sql_freeresult($res);

		$res = $db->sql_query("SELECT group_id FROM {$prefix}groups WHERE group_name = 'GLOBAL_MODERATORS'");
		$canonical_gmod_id = (int)$db->sql_fetchfield('group_id');
		$db->sql_freeresult($res);

		$res = $db->sql_query("SELECT group_id FROM {$prefix}groups WHERE group_name = 'ADMINISTRATORS'");
		$canonical_admin_id = (int)$db->sql_fetchfield('group_id');
		$db->sql_freeresult($res);

		$results['mapping_guests_semantic']     = ($g1_map !== null && (int)$g1_map === $canonical_guests_id);
		$results['mapping_registered_semantic'] = ($g2_map !== null && (int)$g2_map === $canonical_reg_id);
		$results['mapping_supermods_semantic']  = ($g5_map !== null && (int)$g5_map === $canonical_gmod_id);
		$results['mapping_admins_semantic']     = ($g6_map !== null && (int)$g6_map === $canonical_admin_id);
		$results['mapping_moderators_created']  = ($g7_map !== null && (int)$g7_map !== $canonical_gmod_id);
		$results['mapping_banned_created']      = ($g8_map !== null && (int)$g8_map > 0);

		// Verify total groups during migration increased by exactly 2 (created custom groups)
		$res = $db->sql_query("SELECT COUNT(*) as cnt FROM {$prefix}groups");
		$total_groups_during_run = (int)$db->sql_fetchfield('cnt');
		$db->sql_freeresult($res);
		$results['total_groups_increased_by_2'] = ($total_groups_during_run === ($initial_total_groups + 2));

		// 4. Test Idempotency (Re-running does not create duplicate groups)
		$vb_step = new groups_step();
		$vb_prov = new vbulletin_source_provider();
		$idempotent_res = $vb_step->process_batch($run_id, 0, 50, $cfg3, $vb_prov, $writer);
		$results['idempotent_read_count_8'] = ($idempotent_res->read_count === 8);
		$results['idempotent_no_new_groups']= ($idempotent_res->metrics['created'] === 0 && $idempotent_res->metrics['reused'] === 8);

		// 5. Test Rollback Groups Safety
		$engine->cancel_run($run_id);
		$rm = new \phpbbseo\migrationcenter\core\rollback\rollback_manager(
			$db,
			$phpbb_container->get('config'),
			$phpbb_container->get('cache.driver'),
			$id_mapper,
			$lock_mgr,
			$state_mgr,
			$prefix,
			$phpbb_container->getParameter('core.root_path')
		);
		$rm->rollback($run_id, 'ROLLBACK');

		// Canonical groups preserved
		$res = $db->sql_query("SELECT COUNT(*) as cnt FROM {$prefix}groups WHERE group_type = 3");
		$canonical_count = (int)$db->sql_fetchfield('cnt');
		$db->sql_freeresult($res);
		$results['canonical_groups_preserved'] = ($canonical_count >= 4);

		// Pre-existing custom groups STILL EXIST and are untouched
		$res = $db->sql_query("SELECT group_id FROM {$prefix}groups WHERE group_id = {$pre_vip_id}");
		$survived_vip = (int)$db->sql_fetchfield('group_id');
		$db->sql_freeresult($res);

		$res = $db->sql_query("SELECT group_id FROM {$prefix}groups WHERE group_id = {$pre_donors_id}");
		$survived_donors = (int)$db->sql_fetchfield('group_id');
		$db->sql_freeresult($res);

		$res = $db->sql_query("SELECT group_id FROM {$prefix}groups WHERE group_id = {$pre_staff_id}");
		$survived_staff = (int)$db->sql_fetchfield('group_id');
		$db->sql_freeresult($res);

		$results['pre_existing_vip_survived']    = ($survived_vip === $pre_vip_id);
		$results['pre_existing_donors_survived'] = ($survived_donors === $pre_donors_id);
		$results['pre_existing_staff_survived']  = ($survived_staff === $pre_staff_id);

		// Migration-created groups (Moderators, Banned Users) are removed
		$res = $db->sql_query("SELECT COUNT(*) as cnt FROM {$prefix}groups WHERE group_id IN ({$g7_map}, {$g8_map})");
		$migration_groups_left = (int)$db->sql_fetchfield('cnt');
		$db->sql_freeresult($res);
		$results['migration_created_groups_deleted'] = ($migration_groups_left === 0);

		// Prove historical data survived test cleanup & rollback
		$res = $db->sql_query("SELECT COUNT(*) as cnt FROM {$prefix}migration_runs WHERE run_id = '{$historical_run_id}'");
		$cnt_hrun = (int)$db->sql_fetchfield('cnt');
		$db->sql_freeresult($res);

		$res = $db->sql_query("SELECT COUNT(*) as cnt FROM {$prefix}migration_steps WHERE run_id = '{$historical_run_id}'");
		$cnt_hstep = (int)$db->sql_fetchfield('cnt');
		$db->sql_freeresult($res);

		$res = $db->sql_query("SELECT COUNT(*) as cnt FROM {$prefix}migration_id_map WHERE run_id = '{$historical_run_id}'");
		$cnt_hmap = (int)$db->sql_fetchfield('cnt');
		$db->sql_freeresult($res);

		$res = $db->sql_query("SELECT COUNT(*) as cnt FROM {$prefix}migration_errors WHERE run_id = '{$historical_run_id}'");
		$cnt_herr = (int)$db->sql_fetchfield('cnt');
		$db->sql_freeresult($res);

		$res = $db->sql_query("SELECT COUNT(*) as cnt FROM {$prefix}migration_locks WHERE lock_name = 'test_historical_lock'");
		$cnt_hlock = (int)$db->sql_fetchfield('cnt');
		$db->sql_freeresult($res);

		$results['historical_run_survived']  = ($cnt_hrun === 1);
		$results['historical_step_survived'] = ($cnt_hstep === 1);
		$results['historical_map_survived']  = ($cnt_hmap === 1);
		$results['historical_err_survived']  = ($cnt_herr === 1);
		$results['historical_lock_survived'] = ($cnt_hlock === 1);

		// Scoped Cleanup: remove only the historical test records and test groups
		$db->sql_query("DELETE FROM {$prefix}groups WHERE group_id IN ({$pre_vip_id}, {$pre_donors_id}, {$pre_staff_id})");
		$db->sql_query("DELETE FROM {$prefix}migration_runs WHERE run_id = '{$historical_run_id}'");
		$db->sql_query("DELETE FROM {$prefix}migration_steps WHERE run_id = '{$historical_run_id}'");
		$db->sql_query("DELETE FROM {$prefix}migration_id_map WHERE run_id = '{$historical_run_id}'");
		$db->sql_query("DELETE FROM {$prefix}migration_errors WHERE run_id = '{$historical_run_id}'");
		$db->sql_query("DELETE FROM {$prefix}migration_locks WHERE lock_name = 'test_historical_lock'");

		return $results;
	}
}
