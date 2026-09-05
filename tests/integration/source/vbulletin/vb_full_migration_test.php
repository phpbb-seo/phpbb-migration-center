<?php
/**
 * phpBB Migration Center Extension
 *
 * @copyright (c) 2026 phpBB SEO Team
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace phpbbseo\migrationcenter\tests\integration\source\vbulletin;

use phpbbseo\migrationcenter\core\dto\migration_config_dto;

/**
 * Comprehensive Integration Test for vBulletin 3.8 and 4.2 Full Migration Pipeline
 */
class vb_full_migration_test
{
	public function run(): array
	{
		global $phpbb_container;

		list($db, $table_prefix) = get_test_db();
		$engine = $phpbb_container->get('phpbbseo.migrationcenter.engine');
		$id_mapper = $phpbb_container->get('phpbbseo.migrationcenter.id_mapper');
		$state_mgr = $phpbb_container->get('phpbbseo.migrationcenter.state_manager');
		$rollback_mgr = $phpbb_container->get('phpbbseo.migrationcenter.rollback_manager');

		$env_file = 'C:/vb-migration-lab/.env';
		$env = [];
		if (file_exists($env_file))
		{
			$lines = file($env_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
			foreach ($lines as $l)
			{
				if (strpos(trim($l), '#') === 0 || strpos($l, '=') === false) continue;
				list($k, $v) = explode('=', $l, 2);
				$env[trim($k)] = trim($v);
			}
		}

		$results = [];

		// ==========================================
		// TEST A: Full vBulletin 3.8.11 Migration
		// ==========================================
		$run_id_vb3 = 'vb3_full_int_test_' . time();
		$cfg3 = migration_config_dto::from_array([
			'source_system' => 'vbulletin',
			'source_path'   => 'C:/vb-migration-lab/vb3',
			'db_host'       => '127.0.0.1',
			'db_port'       => 3307,
			'db_name'       => 'vb3_test',
			'db_user'       => 'migration_vb3_readonly',
			'db_password'   => $env['VB3_DB_PASSWORD'] ?? 'vb3_lab_secret_pass_2026',
			'db_prefix'     => '',
			'options'       => [
				'batch_sizes' => [
					'groups'                    => 50,
					'users'                     => 50,
					'group_memberships'         => 50,
					'global_permissions'        => 50,
					'forums'                    => 50,
					'node_permissions'          => 50,
					'topics'                    => 50,
					'posts'                     => 500,
					'attachments'               => 50,
					'avatars'                   => 50,
					'conversations'             => 50,
					'conversation_messages'     => 50,
					'conversation_attachments'  => 50,
					'polls'                     => 50,
					'bans'                      => 50,
					'finalization'              => 50,
					'search_index'              => 500,
					'final_verification'        => 50,
				],
			],
		]);

		$run_state3 = $engine->start_run('vbulletin', $cfg3);
		$run_id_vb3 = $run_state3->run_id;
		$results['vb3_run_started'] = in_array($run_state3->status, ['ready', 'running'], true);

		// Execute all stages till completion
		$steps_completed3 = [];
		$max_iterations = 200;
		$iter = 0;

		$worker_token3 = 'worker_vb3_' . time();
		while ($iter++ < $max_iterations)
		{
			$batch_res = $engine->execute_next_batch($run_id_vb3, 'ajax', 500, $worker_token3);
			if (!empty($batch_res['awaiting_approval']) || ($batch_res['run_status'] ?? '') === 'awaiting_approval')
			{
				$steps_completed3[] = $batch_res['stage_key'];
				$next_stg = $batch_res['next_stage'] ?? $state_mgr->get_next_stage($batch_res['stage_key']);
				$engine->approve_stage_continuation($run_id_vb3, $next_stg);
			}
			else if (!empty($batch_res['completed']) || ($batch_res['run_status'] ?? '') === 'completed')
			{
				break;
			}
			else if (($batch_res['run_status'] ?? '') === 'failed' || ($batch_res['run_status'] ?? '') === 'permanently_failed')
			{
				throw new \Exception("vB3 batch failed on step {$batch_res['stage_key']}");
			}
		}

		$run_state3 = $state_mgr->get_run($run_id_vb3);
		$results['vb3_migration_completed'] = ($run_state3->status === 'completed');

		$count_by_type = function($ctype) use ($db, $table_prefix, $run_id_vb3) {
			$res = $db->sql_query("SELECT COUNT(*) as cnt FROM {$table_prefix}migration_id_map WHERE run_id = '{$run_id_vb3}' AND content_type = '{$ctype}'");
			$cnt = (int)$db->sql_fetchfield('cnt', false, $res);
			$db->sql_freeresult($res);
			return $cnt;
		};

		$results['vb3_users_count_100'] = ($count_by_type('user') === 100);
		$results['vb3_forums_count_38'] = ($count_by_type('forum') === 38);
		$results['vb3_topics_count_538'] = ($count_by_type('topic') === 538);
		$results['vb3_posts_count_7822'] = ($count_by_type('post') === 7822);
		$results['vb3_attach_count_5'] = ($count_by_type('attachment') === 5);
		$results['vb3_avatars_count_2'] = ($count_by_type('avatar') === 2);
		$results['vb3_pm_count_5'] = ($count_by_type('privmsg') === 5);
		$results['vb3_polls_count_1'] = ($count_by_type('poll') === 1);
		$results['vb3_bans_count_1'] = ($count_by_type('ban') === 1);

		// ==========================================
		// TEST B: Rollback vB3 Migration Run
		// ==========================================
		$rollback_res3 = $rollback_mgr->rollback($run_id_vb3, 'ROLLBACK');
		$results['vb3_rollback_status'] = ($rollback_res3['status'] === 'rolled_back');

		// Verify target database is clean after rollback (only core users 1 and 2 remain, plus standard bots)
		$res_u = $db->sql_query("SELECT COUNT(*) as cnt FROM {$table_prefix}users");
		$users_after_rb = (int)$db->sql_fetchfield('cnt', false, $res_u);
		$db->sql_freeresult($res_u);
		$results['users_cleaned_after_rollback'] = in_array($users_after_rb, [56, 57], true);

		// ==========================================
		// TEST C: Full vBulletin 4.2.5 Migration
		// ==========================================
		$cfg4 = migration_config_dto::from_array([
			'source_system' => 'vbulletin',
			'source_path'   => 'C:/vb-migration-lab/vb4',
			'db_host'       => '127.0.0.1',
			'db_port'       => 3308,
			'db_name'       => 'vb4_test',
			'db_user'       => 'migration_vb4_readonly',
			'db_password'   => $env['VB4_DB_PASSWORD'] ?? 'vb4_lab_secret_pass_2026',
			'db_prefix'     => '',
			'options'       => [
				'batch_sizes' => [
					'groups'                    => 50,
					'users'                     => 50,
					'group_memberships'         => 50,
					'global_permissions'        => 50,
					'forums'                    => 50,
					'node_permissions'          => 50,
					'topics'                    => 50,
					'posts'                     => 500,
					'attachments'               => 50,
					'avatars'                   => 50,
					'conversations'             => 50,
					'conversation_messages'     => 50,
					'conversation_attachments'  => 50,
					'polls'                     => 50,
					'bans'                      => 50,
					'finalization'              => 50,
					'search_index'              => 500,
					'final_verification'        => 50,
				],
			],
		]);

		$run_state4 = $engine->start_run('vbulletin', $cfg4);
		$run_id_vb4 = $run_state4->run_id;
		$results['vb4_run_started'] = in_array($run_state4->status, ['ready', 'running'], true);

		$iter4 = 0;
		$worker_token4 = 'worker_vb4_' . time();
		while ($iter4++ < $max_iterations)
		{
			$batch_res = $engine->execute_next_batch($run_id_vb4, 'cli', 500, $worker_token4);
			if (!empty($batch_res['awaiting_approval']) || ($batch_res['run_status'] ?? '') === 'awaiting_approval')
			{
				$next_stg4 = $batch_res['next_stage'] ?? $state_mgr->get_next_stage($batch_res['stage_key']);
				$engine->approve_stage_continuation($run_id_vb4, $next_stg4);
			}
			else if (!empty($batch_res['completed']) || ($batch_res['run_status'] ?? '') === 'completed')
			{
				break;
			}
			else if (($batch_res['run_status'] ?? '') === 'failed' || ($batch_res['run_status'] ?? '') === 'permanently_failed')
			{
				throw new \Exception("vB4 batch failed on step {$batch_res['stage_key']}");
			}
		}

		$run_state4 = $state_mgr->get_run($run_id_vb4);
		$results['vb4_migration_completed'] = ($run_state4->status === 'completed');

		$res_p4 = $db->sql_query("SELECT COUNT(*) as cnt FROM {$table_prefix}migration_id_map WHERE run_id = '{$run_id_vb4}' AND content_type = 'post'");
		$mapped_posts4 = (int)$db->sql_fetchfield('cnt', false, $res_p4);
		$db->sql_freeresult($res_p4);
		$results['vb4_posts_count_7822'] = ($mapped_posts4 === 7822);

		$res_a4 = $db->sql_query("SELECT COUNT(*) as cnt FROM {$table_prefix}migration_id_map WHERE run_id = '{$run_id_vb4}' AND content_type = 'attachment'");
		$mapped_attach4 = (int)$db->sql_fetchfield('cnt', false, $res_a4);
		$db->sql_freeresult($res_a4);
		$results['vb4_attach_count_5'] = ($mapped_attach4 === 5);

		// Rollback vB4 to leave clean baseline
		$rollback_mgr->rollback($run_id_vb4, 'ROLLBACK');

		return $results;
	}
}
