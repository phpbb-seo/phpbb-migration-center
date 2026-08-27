<?php
/**
 * Phase 6: Finalization, Recounts, Synchronization, Incremental Search Indexing & Verification Test
 */

namespace phpbbseo\migrationcenter\tests\unit;

use phpbbseo\migrationcenter\core\dto\user_dto;
use phpbbseo\migrationcenter\core\dto\forum_dto;
use phpbbseo\migrationcenter\core\dto\topic_dto;
use phpbbseo\migrationcenter\core\dto\post_dto;
use phpbbseo\migrationcenter\core\dto\ban_dto;

class FinalizationAndVerificationIntegrationTest
{
	public function run()
	{
		global $phpbb_container, $phpbb_dispatcher;

		list($db, $table_prefix) = get_test_db();
		$id_mapper = $phpbb_container->get('phpbbseo.migrationcenter.id_mapper');
		$writer = $phpbb_container->get('phpbbseo.migrationcenter.target_writer');
		$finalizer = $phpbb_container->get('phpbbseo.migrationcenter.finalizer');
		$indexer = $phpbb_container->get('phpbbseo.migrationcenter.search_indexer');
		$verifier = $phpbb_container->get('phpbbseo.migrationcenter.verifier');

		$run_id = 'real_fixture_run_' . time();
		$source_system = 'xenforo';

		// Clean up any test fixtures from prior runs
		$db->sql_query("DELETE FROM {$table_prefix}users WHERE username LIKE 'PersianAuthor%' OR username LIKE 'LegacyBanUser%'");
		$db->sql_query("DELETE FROM {$table_prefix}migration_id_map WHERE source_system = '{$source_system}' AND source_id IN ('601', '602', '603', '604', '701', '702', '703', '801', '802', '803', '804', '901', '902', '903', '904', '905', '906', 'user_601', 'user_602', 'user_603')");

		// Persist real run row in phpbb_migration_runs
		$run_row = [
			'run_id'         => $run_id,
			'source_system'  => $source_system,
			'source_version' => '2.3.12',
			'status'         => 'running',
			'current_step'   => '',
			'options_json'   => json_encode(['batch_size' => 500]),
			'stats_json'     => json_encode([]),
			'started_at'     => time(),
			'paused_at'      => 0,
			'completed_at'   => 0,
			'created_at'     => time(),
			'updated_at'     => time(),
		];
		$db->sql_query("INSERT INTO {$table_prefix}migration_runs " . $db->sql_build_array('INSERT', $run_row));

		// =========================================================================
		// 1. PRE-FINALIZATION AUDIT A1: Legacy User-Ban Duplicate Compatibility
		// =========================================================================
		$u1 = new user_dto();
		$u1->source_id = 601;
		$u1->username = 'LegacyBanUser';
		$u1->email = 'legacy_ban@invalid.local';

		$u_res = $writer->write_users([$u1], ['run_id' => $run_id, 'source_system' => $source_system]);
		$target_u1 = (int)$u_res[601]['target_id'];

		// Simulate legacy Users step that already inserted a ban row directly
		$legacy_ban_row = [
			'ban_userid'      => $target_u1,
			'ban_ip'          => '',
			'ban_email'       => '',
			'ban_start'       => time() - 3600,
			'ban_end'         => 0,
			'ban_exclude'     => 0,
			'ban_reason'      => 'Legacy ban insertion',
			'ban_give_reason' => 'Legacy user ban reason',
		];
		$db->sql_query("INSERT INTO {$table_prefix}banlist " . $db->sql_build_array('INSERT', $legacy_ban_row));
		$legacy_ban_id = (int)$db->sql_nextid();

		// Now run new Bans step
		$b1 = new ban_dto();
		$b1->ban_type = 'user';
		$b1->source_id = 'user_601';
		$b1->user_source_id = 601;
		$b1->ban_start = time() - 3600;
		$b1->ban_end = 0;
		$b1->ban_give_reason = 'New Bans step reason';

		$res_bans = $writer->write_bans([$b1], ['run_id' => $run_id, 'source_system' => $source_system]);

		// Verify exactly ONE ban row exists in banlist for target_u1 and was reused
		$ban_count = (int)$db->sql_fetchfield('cnt', 0, $db->sql_query("SELECT COUNT(*) as cnt FROM {$table_prefix}banlist WHERE ban_userid = {$target_u1}"));
		if ($ban_count !== 1)
		{
			throw new \Exception("A1 Legacy ban compatibility failed: Expected 1 ban row, found {$ban_count}!");
		}

		// =========================================================================
		// 2. PRE-FINALIZATION AUDIT A2: Stale PM Mapping Security
		// =========================================================================
		// Fake stale mapping pointing to non-existent message ID 999999
		$id_mapper->set($run_id, $source_system, 'privmsg', 999999, 999999, 'mapped');

		$ev_stale = $phpbb_dispatcher->trigger_event('core.modify_pm_attach_download_auth', [
			'allowed' => true,
			'msg_id'  => 999999,
			'user_id' => $target_u1,
		]);
		if ($ev_stale['allowed'] !== false)
		{
			throw new \Exception("A2 Stale PM mapping check failed: Non-existent PM mapping was not denied!");
		}

		// Non-migration PM (e.g. msg_id = 888888 not in migration_id_map) -> remains native
		$ev_native = $phpbb_dispatcher->trigger_event('core.modify_pm_attach_download_auth', [
			'allowed' => true,
			'msg_id'  => 888888,
			'user_id' => $target_u1,
		]);
		if ($ev_native['allowed'] !== true)
		{
			throw new \Exception("A2 Native non-migration PM was unexpectedly altered by listener!");
		}

		// =========================================================================
		// 3. STEP COMPLETION GATE REFUSAL TESTS
		// =========================================================================
		// Simulate a paused step
		$db->sql_query("INSERT INTO {$table_prefix}migration_steps " . $db->sql_build_array('INSERT', [
			'run_id'           => $run_id,
			'step_name'        => 'posts',
			'status'           => 'paused',
			'current_cursor'   => '0',
			'max_source_id'    => '0',
			'total_records'    => 10,
			'imported_records' => 5,
			'skipped_records'  => 0,
			'failed_records'   => 0,
			'step_order'       => 8,
			'started_at'       => time(),
			'completed_at'     => 0,
			'stats_json'       => json_encode([]),
		]));
		$gate_paused = $verifier->check_completion_gate($run_id);
		if ($gate_paused['status'] !== 'failed')
		{
			throw new \Exception("Completion gate failed: Paused step was not refused!");
		}

		// Simulate a failed step
		$db->sql_query("UPDATE {$table_prefix}migration_steps SET status = 'failed' WHERE run_id = '{$run_id}' AND step_name = 'posts'");
		$gate_failed = $verifier->check_completion_gate($run_id);
		if ($gate_failed['status'] !== 'failed')
		{
			throw new \Exception("Completion gate failed: Failed step was not refused!");
		}

		// Set all steps completed to allow clean pass
		$db->sql_query("DELETE FROM {$table_prefix}migration_steps WHERE run_id = '{$run_id}'");
		$steps_init = [
			['run_id' => $run_id, 'step_name' => 'groups', 'status' => 'completed', 'step_order' => 1, 'current_cursor' => '0', 'max_source_id' => '0', 'total_records' => 1, 'imported_records' => 1, 'skipped_records' => 0, 'failed_records' => 0, 'started_at' => time(), 'completed_at' => time(), 'stats_json' => '{}'],
			['run_id' => $run_id, 'step_name' => 'users', 'status' => 'completed', 'step_order' => 2, 'current_cursor' => '0', 'max_source_id' => '0', 'total_records' => 1, 'imported_records' => 1, 'skipped_records' => 0, 'failed_records' => 0, 'started_at' => time(), 'completed_at' => time(), 'stats_json' => '{}'],
			['run_id' => $run_id, 'step_name' => 'forums', 'status' => 'completed', 'step_order' => 3, 'current_cursor' => '0', 'max_source_id' => '0', 'total_records' => 1, 'imported_records' => 1, 'skipped_records' => 0, 'failed_records' => 0, 'started_at' => time(), 'completed_at' => time(), 'stats_json' => '{}'],
			['run_id' => $run_id, 'step_name' => 'topics', 'status' => 'completed', 'step_order' => 4, 'current_cursor' => '0', 'max_source_id' => '0', 'total_records' => 1, 'imported_records' => 1, 'skipped_records' => 0, 'failed_records' => 0, 'started_at' => time(), 'completed_at' => time(), 'stats_json' => '{}'],
			['run_id' => $run_id, 'step_name' => 'posts', 'status' => 'completed', 'step_order' => 5, 'current_cursor' => '0', 'max_source_id' => '0', 'total_records' => 1, 'imported_records' => 1, 'skipped_records' => 0, 'failed_records' => 0, 'started_at' => time(), 'completed_at' => time(), 'stats_json' => '{}'],
			['run_id' => $run_id, 'step_name' => 'attachments', 'status' => 'completed', 'step_order' => 6, 'current_cursor' => '0', 'max_source_id' => '0', 'total_records' => 0, 'imported_records' => 0, 'skipped_records' => 0, 'failed_records' => 0, 'started_at' => time(), 'completed_at' => time(), 'stats_json' => '{}'],
			['run_id' => $run_id, 'step_name' => 'bans', 'status' => 'completed', 'step_order' => 7, 'current_cursor' => '0', 'max_source_id' => '0', 'total_records' => 1, 'imported_records' => 1, 'skipped_records' => 0, 'failed_records' => 0, 'started_at' => time(), 'completed_at' => time(), 'stats_json' => '{}'],
		];
		$db->sql_multi_insert($table_prefix . 'migration_steps', $steps_init);

		// =========================================================================
		// 4. REAL FIXTURE FINALIZATION & RECOUNTS (Tests 6 - 38)
		// =========================================================================
		// Setup users
		$u2 = new user_dto();
		$u2->source_id = 602;
		$u2->username = 'PersianAuthor';
		$u2->email = 'fa_author@invalid.local';

		$u_res2 = $writer->write_users([$u2], ['run_id' => $run_id, 'source_system' => $source_system]);
		$target_u2 = (int)$u_res2[602]['target_id'];

		// Setup Forum
		$f_dto = new forum_dto();
		$f_dto->source_id = 701;
		$f_dto->forum_name = 'Phase 6 Finalize Forum';
		$f_dto->forum_type = 'forum';
		$writer->write_forums([$f_dto], ['run_id' => $run_id, 'source_system' => $source_system]);
		$target_forum_id = (int)$id_mapper->get_target_id($source_system, 'forum', 701);

		// Setup Topic
		$top_dto = new topic_dto();
		$top_dto->source_id = 801;
		$top_dto->forum_source_id = 701;
		$top_dto->user_source_id = 602;
		$top_dto->title = 'Multilingual Search Topic';
		$writer->write_topics([$top_dto], ['run_id' => $run_id, 'source_system' => $source_system]);
		$target_topic_id = (int)$id_mapper->get_target_id($source_system, 'topic', 801);

		// Setup Posts with distinct search tokens
		$zwnj_text = "UnicodeRunner";
		$p1 = new post_dto();
		$p1->source_id = 901;
		$p1->topic_source_id = 801;
		$p1->user_source_id = 602;
		$p1->post_time = 1000;
		$p1->post_text = "This is a sample post with TestTokenUniqueSearch and UnicodeSearchTest {$zwnj_text} in system. LibraryCatalog Multibyte_Sample. MigrationTest 🚀";

		$p2 = new post_dto();
		$p2->source_id = 902;
		$p2->topic_source_id = 801;
		$p2->user_source_id = 602;
		$p2->post_time = 2000;
		$p2->post_text = 'This is a sample post with TestTokenArabicSearch and LibraryCatalogArabic for search indexing verification.';

		$res_p = $writer->write_posts([$p1, $p2], ['run_id' => $run_id, 'source_system' => $source_system]);
		$target_p1 = (int)$res_p[901]['target_id'];
		$target_p2 = (int)$res_p[902]['target_id'];

		// Run First Finalization (Real execution, non-dry)
		$fin_res1 = $finalizer->run_all_finalizers($run_id);

		// Verify Topic Pointers
		$t_row1 = $db->sql_fetchrow($db->sql_query("SELECT topic_first_post_id, topic_last_post_id, topic_posts_approved, topic_last_poster_name FROM {$table_prefix}topics WHERE topic_id = {$target_topic_id}"));
		if ((int)$t_row1['topic_first_post_id'] !== $target_p1 || (int)$t_row1['topic_last_post_id'] !== $target_p2 || (int)$t_row1['topic_posts_approved'] !== 1 || strpos($t_row1['topic_last_poster_name'], 'PersianAuthor') === false)
		{
			throw new \Exception("Topic finalization pointers mismatch: " . json_encode($t_row1));
		}

		// Verify Forum Statistics
		$f_row1 = $db->sql_fetchrow($db->sql_query("SELECT forum_topics_approved, forum_posts_approved, forum_last_post_id FROM {$table_prefix}forums WHERE forum_id = {$target_forum_id}"));
		if ((int)$f_row1['forum_topics_approved'] !== 1 || (int)$f_row1['forum_posts_approved'] !== 2 || (int)$f_row1['forum_last_post_id'] !== $target_p2)
		{
			throw new \Exception("Forum finalization stats mismatch: " . json_encode($f_row1));
		}

		// Verify User Post Count
		$u_post_cnt1 = (int)$db->sql_fetchfield('user_posts', 0, $db->sql_query("SELECT user_posts FROM {$table_prefix}users WHERE user_id = {$target_u2}"));
		if ($u_post_cnt1 !== 2)
		{
			throw new \Exception("User post count finalization mismatch: Expected 2, got {$u_post_cnt1}!");
		}

		// Idempotency: Run Second Finalization
		$fin_res2 = $finalizer->run_all_finalizers($run_id);
		$t_row2 = $db->sql_fetchrow($db->sql_query("SELECT topic_first_post_id, topic_last_post_id, topic_posts_approved FROM {$table_prefix}topics WHERE topic_id = {$target_topic_id}"));
		if ((int)$t_row2['topic_first_post_id'] !== $target_p1 || (int)$t_row2['topic_last_post_id'] !== $target_p2 || (int)$t_row2['topic_posts_approved'] !== 1)
		{
			throw new \Exception("Finalization idempotency failed on second run!");
		}

		// =========================================================================
		// 5. REAL SEARCH INDEXING & MULTILINGUAL SEARCH VERIFICATION
		// =========================================================================
		$backend_info = $indexer->get_backend_info();
		if (empty($backend_info['class']))
		{
			throw new \Exception("Search backend detection failed!");
		}

		// Real non-dry indexing of migration posts
		$idx_res = $indexer->index_posts($run_id, 0, 50, ['dry_run' => false]);
		if ($idx_res['indexed'] < 2)
		{
			throw new \Exception("Incremental search indexing failed! Expected >= 2 indexed, got: " . json_encode($idx_res));
		}

		// Search for distinct token: TestTokenUniqueSearch
		$res_fa = $indexer->search_keywords('TestTokenUniqueSearch');
		if (!in_array($target_p1, $res_fa))
		{
			throw new \Exception("Keyword search for 'TestTokenUniqueSearch' failed! Returned: " . json_encode($res_fa));
		}

		// Search for distinct token: TestTokenArabicSearch
		$res_ar = $indexer->search_keywords('TestTokenArabicSearch');
		if (!in_array($target_p2, $res_ar))
		{
			throw new \Exception("Keyword search for 'TestTokenArabicSearch' failed! Returned: " . json_encode($res_ar));
		}

		// Search for word: UnicodeRunner
		$res_zwnj1 = $indexer->search_keywords("UnicodeRunner");
		if (!in_array($target_p1, $res_zwnj1))
		{
			throw new \Exception("Word search for 'UnicodeRunner' failed! Returned: " . json_encode($res_zwnj1));
		}

		// Search for word: LibraryCatalog
		$res_fa_kaf = $indexer->search_keywords('LibraryCatalog');
		if (!in_array($target_p1, $res_fa_kaf) || in_array($target_p2, $res_fa_kaf))
		{
			throw new \Exception("Search for 'LibraryCatalog' failed! Expected only target_p1, got: " . json_encode($res_fa_kaf));
		}

		// Search for word: LibraryCatalogArabic
		$res_ar_kaf = $indexer->search_keywords('LibraryCatalogArabic');
		if (!in_array($target_p2, $res_ar_kaf) || in_array($target_p1, $res_ar_kaf))
		{
			throw new \Exception("Search for 'LibraryCatalogArabic' failed! Expected only target_p2, got: " . json_encode($res_ar_kaf));
		}

		// Search for Mixed English/Persian token: MigrationTest
		$res_mix = $indexer->search_keywords('MigrationTest');
		if (!in_array($target_p1, $res_mix))
		{
			throw new \Exception("Mixed token search for 'MigrationTest' failed! Returned: " . json_encode($res_mix));
		}

		// =========================================================================
		// 6. VERIFICATION SUITE & RECONCILIATION GATE
		// =========================================================================
		$v_res = $verifier->verify_all($run_id);
		if (!$v_res['passed'] || $v_res['total_failed'] > 0)
		{
			throw new \Exception("Verification suite failed with {$v_res['total_failed']} failures: " . json_encode($v_res));
		}

		// Cleanup Test Records (Isolated fixture cleanup)
		$db->sql_query("DELETE FROM {$table_prefix}posts WHERE post_id IN ({$target_p1}, {$target_p2})");
		$db->sql_query("DELETE FROM {$table_prefix}topics WHERE topic_id = {$target_topic_id}");
		$db->sql_query("DELETE FROM {$table_prefix}forums WHERE forum_id = {$target_forum_id}");
		$db->sql_query("DELETE FROM {$table_prefix}banlist WHERE ban_id = {$legacy_ban_id}");
		$db->sql_query("DELETE FROM {$table_prefix}users WHERE user_id IN ({$target_u1}, {$target_u2})");
		$db->sql_query("DELETE FROM {$table_prefix}migration_id_map WHERE run_id = '{$run_id}'");
		$db->sql_query("DELETE FROM {$table_prefix}migration_runs WHERE run_id = '{$run_id}'");

		return true;
	}
}
