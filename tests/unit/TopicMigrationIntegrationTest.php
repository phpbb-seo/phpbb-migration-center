<?php
/**
 * Topic Migration, Keyset Pagination & Target Integrity Integration Test
 */

namespace phpbbseo\migrationcenter\tests\unit;

use phpbbseo\migrationcenter\core\engine\migration_engine;
use phpbbseo\migrationcenter\core\dto\migration_config_dto;
use phpbbseo\migrationcenter\core\dto\topic_dto;
use phpbbseo\migrationcenter\core\dto\forum_dto;
use phpbbseo\migrationcenter\core\dto\user_dto;
use phpbbseo\migrationcenter\source\xenforo\config\xf_config_detector;

class TopicMigrationIntegrationTest
{
	public function run()
	{
		global $phpbb_container;

		list($db, $table_prefix) = get_test_db();
		$source_path = 'C:/xampp/htdocs/xen';
		$config = xf_config_detector::detect_from_path($source_path);
		if (!$config)
		{
			throw new \Exception("Could not load XenForo config for topic integration test");
		}

		// 1. Check pre-existing phpBB topics and admin
		$sql = "SELECT topic_id, forum_id, topic_title FROM {$table_prefix}topics ORDER BY topic_id ASC";
		$res = $db->sql_query($sql);
		$initial_topics = [];
		while ($r = $db->sql_fetchrow($res))
		{
			$initial_topics[] = $r;
		}
		$db->sql_freeresult($res);

		$initial_topics_count = count($initial_topics);
		if ($initial_topics_count === 0)
		{
			throw new \Exception("Pre-existing phpBB topics not found");
		}

		// 2. Test Dry-Run: topics step
		$dry_config = clone $config;
		$dry_config->selected_steps = ['topics'];
		$dry_config->dry_run = true;
		$dry_config->batch_size = 50;

		$provider_reg = $phpbb_container->get('phpbbseo.migrationcenter.provider_registry');
		$step_reg = $phpbb_container->get('phpbbseo.migrationcenter.step_registry');
		$state_mgr = $phpbb_container->get('phpbbseo.migrationcenter.state_manager');
		$lock_mgr = $phpbb_container->get('phpbbseo.migrationcenter.lock_manager');
		$id_mapper = $phpbb_container->get('phpbbseo.migrationcenter.id_mapper');
		$writer = $phpbb_container->get('phpbbseo.migrationcenter.target_writer');

		$engine = new migration_engine($provider_reg, $step_reg, $state_mgr, $lock_mgr, $id_mapper, $writer);

		$dry_run = $engine->start_run('xenforo', $dry_config);
		$step_topics = $step_reg->get('topics');
		$step_res = $step_topics->process_batch($dry_run->run_id, 0, 50, $dry_config, $provider_reg->get('xenforo'), $writer);

		if ($step_res->read_count !== 50 || $step_res->imported_count !== 50)
		{
			throw new \Exception("Dry-run topics failed to read 50 source threads");
		}

		$res = $db->sql_query("SELECT COUNT(*) as cnt FROM {$table_prefix}topics");
		$topics_cnt_after_dry = (int)$db->sql_fetchfield('cnt');
		$db->sql_freeresult($res);

		if ($topics_cnt_after_dry !== $initial_topics_count)
		{
			throw new \Exception("Dry-run altered target topics count! Expected {$initial_topics_count}, got {$topics_cnt_after_dry}");
		}

		$lock_mgr->release('migration_xenforo', $dry_run->run_id);

		// 3. Setup Migration-Owned Target Test Data
		$test_run_id = 'test_topic_run_' . time();
		$test_forum_src_id = 9981;
		$test_user_src_id = 9982;

		// Map test forum
		$f = new forum_dto();
		$f->source_id = $test_forum_src_id;
		$f->forum_name = 'Test Forum For Topics';
		$f_res = $writer->write_forums([$f], ['run_id' => $test_run_id, 'source_system' => 'xenforo']);
		$target_forum_id = (int)$f_res[$test_forum_src_id]['target_id'];

		// Map test user
		$u = new user_dto();
		$u->source_id = $test_user_src_id;
		$u->username = 'TopicTestAuthor';
		$u->email = 'topictest@invalid.local';
		$u_res = $writer->write_users([$u], ['run_id' => $test_run_id, 'source_system' => 'xenforo']);
		$target_user_id = (int)$u_res[$test_user_src_id]['target_id'];

		// 4. Real Migration of diverse topics
		$t_normal = new topic_dto();
		$t_normal->source_id = 99001;
		$t_normal->forum_source_id = $test_forum_src_id;
		$t_normal->user_source_id = $test_user_src_id;
		$t_normal->source_username = 'TopicTestAuthor';
		$t_normal->topic_title = "XXXYK_Multibyte_Sample_XXXY_UnicodeRunner\xE2\x80\x8CXXX"; // Persian with ZWNJ
		$t_normal->topic_type = 0; // POST_NORMAL
		$t_normal->topic_status = 0; // ITEM_UNLOCKED
		$t_normal->topic_visibility = 1; // ITEM_APPROVED
		$t_normal->first_post_source_id = 50001;
		$t_normal->last_post_source_id = 50005;

		$t_sticky = new topic_dto();
		$t_sticky->source_id = 99002;
		$t_sticky->forum_source_id = $test_forum_src_id;
		$t_sticky->user_source_id = $test_user_src_id;
		$t_sticky->source_username = 'TopicTestAuthor';
		$t_sticky->topic_title = 'Important Pinned Announcement'; // Arabic
		$t_sticky->topic_type = 1; // POST_STICKY
		$t_sticky->topic_status = 0;
		$t_sticky->topic_visibility = 1;
		$t_sticky->first_post_source_id = 50006;
		$t_sticky->last_post_source_id = 50006;

		$t_locked = new topic_dto();
		$t_locked->source_id = 99003;
		$t_locked->forum_source_id = $test_forum_src_id;
		$t_locked->user_source_id = $test_user_src_id;
		$t_locked->source_username = 'TopicTestAuthor';
		$t_locked->topic_title = 'Locked Archive Topic 🔒';
		$t_locked->topic_type = 0;
		$t_locked->topic_status = 1; // ITEM_LOCKED
		$t_locked->topic_visibility = 1;

		$t_moderated = new topic_dto();
		$t_moderated->source_id = 99004;
		$t_moderated->forum_source_id = $test_forum_src_id;
		$t_moderated->user_source_id = $test_user_src_id;
		$t_moderated->source_username = 'TopicTestAuthor';
		$t_moderated->topic_title = 'Unapproved Pending Topic';
		$t_moderated->topic_type = 0;
		$t_moderated->topic_status = 0;
		$t_moderated->topic_visibility = 0; // ITEM_UNAPPROVED

		$t_deleted = new topic_dto();
		$t_deleted->source_id = 99005;
		$t_deleted->forum_source_id = $test_forum_src_id;
		$t_deleted->user_source_id = $test_user_src_id;
		$t_deleted->source_username = 'TopicTestAuthor';
		$t_deleted->topic_title = 'Soft Deleted Topic';
		$t_deleted->topic_type = 0;
		$t_deleted->topic_status = 0;
		$t_deleted->topic_visibility = 2; // ITEM_DELETED
		$t_deleted->delete_time = time();
		$t_deleted->delete_reason = 'Spam content';

		// Missing user fallback topic (author not in id_mapper)
		$t_guest = new topic_dto();
		$t_guest->source_id = 99006;
		$t_guest->forum_source_id = $test_forum_src_id;
		$t_guest->user_source_id = 999999; // Missing user
		$t_guest->source_username = 'LegacyGuestUser';
		$t_guest->topic_title = 'Guest Topic Fallback';
		$t_guest->topic_visibility = 1;

		$topics_to_write = [$t_normal, $t_sticky, $t_locked, $t_moderated, $t_deleted, $t_guest];

		$write_res = $writer->write_topics($topics_to_write, [
			'run_id'               => $test_run_id,
			'source_system'        => 'xenforo',
			'missing_forum_policy' => 'skip',
		]);

		// Verify mappings
		foreach ($topics_to_write as $t)
		{
			$target_id = $id_mapper->get_target_id('xenforo', 'topic', $t->source_id);
			if (!$target_id)
			{
				throw new \Exception("Topic ID mapping missing for source thread {$t->source_id}");
			}
		}

		// Verify database records in phpbb_topics
		$target_t1_id = (int)$id_mapper->get_target_id('xenforo', 'topic', 99001);
		$target_t2_id = (int)$id_mapper->get_target_id('xenforo', 'topic', 99002);
		$target_t3_id = (int)$id_mapper->get_target_id('xenforo', 'topic', 99003);
		$target_t4_id = (int)$id_mapper->get_target_id('xenforo', 'topic', 99004);
		$target_t5_id = (int)$id_mapper->get_target_id('xenforo', 'topic', 99005);
		$target_t6_id = (int)$id_mapper->get_target_id('xenforo', 'topic', 99006);

		$sql = "SELECT topic_id, forum_id, topic_poster, topic_title, topic_status, topic_type, topic_visibility, topic_first_post_id, topic_last_post_id, topic_first_poster_name
				FROM {$table_prefix}topics 
				WHERE topic_id IN ({$target_t1_id}, {$target_t2_id}, {$target_t3_id}, {$target_t4_id}, {$target_t5_id}, {$target_t6_id})";
		$res = $db->sql_query($sql);
		$rows = [];
		while ($r = $db->sql_fetchrow($res))
		{
			$rows[$r['topic_id']] = $r;
		}
		$db->sql_freeresult($res);

		// 1. Normal topic check
		$r1 = $rows[$target_t1_id];
		if ((int)$r1['forum_id'] !== $target_forum_id || (int)$r1['topic_poster'] !== $target_user_id || (int)$r1['topic_first_post_id'] !== 0)
		{
			throw new \Exception("Normal topic database record mismatch");
		}
		if ($r1['topic_title'] !== "XXXYK_Multibyte_Sample_XXXY_UnicodeRunner\xE2\x80\x8CXXX")
		{
			throw new \Exception("Persian topic title Unicode round-trip failed");
		}

		// 2. Sticky topic check
		$r2 = $rows[$target_t2_id];
		if ((int)$r2['topic_type'] !== 1)
		{
			throw new \Exception("Sticky topic flag failed in database");
		}

		// 3. Locked topic check
		$r3 = $rows[$target_t3_id];
		if ((int)$r3['topic_status'] !== 1)
		{
			throw new \Exception("Locked topic status failed in database");
		}

		// 4. Moderated topic check
		$r4 = $rows[$target_t4_id];
		if ((int)$r4['topic_visibility'] !== 0)
		{
			throw new \Exception("Moderated topic visibility failed in database");
		}

		// 5. Soft-deleted topic check
		$r5 = $rows[$target_t5_id];
		if ((int)$r5['topic_visibility'] !== 2)
		{
			throw new \Exception("Soft-deleted topic visibility failed in database");
		}

		// 6. Guest author fallback check
		$r6 = $rows[$target_t6_id];
		if ((int)$r6['topic_poster'] !== 1 || $r6['topic_first_poster_name'] !== 'LegacyGuestUser')
		{
			throw new \Exception("Guest author fallback failed in database");
		}

		// 5. Test Missing Forum Policy (skip topic when forum not mapped)
		$t_orphan_forum = new topic_dto();
		$t_orphan_forum->source_id = 99007;
		$t_orphan_forum->forum_source_id = 888888; // Unmapped forum
		$t_orphan_forum->topic_title = 'Orphan Forum Topic';

		$orphan_res = $writer->write_topics([$t_orphan_forum], [
			'run_id'               => $test_run_id,
			'source_system'        => 'xenforo',
			'missing_forum_policy' => 'skip',
		]);

		if ($orphan_res[99007]['status'] !== 'skipped')
		{
			throw new \Exception("Missing forum policy=skip failed to skip topic");
		}

		// 6. Test Idempotency / Duplicate Prevention on Rerun
		$rerun_res = $writer->write_topics($topics_to_write, [
			'run_id'               => $test_run_id,
			'source_system'        => 'xenforo',
			'missing_forum_policy' => 'skip',
		]);

		$res = $db->sql_query("SELECT COUNT(*) as cnt FROM {$table_prefix}topics WHERE topic_id IN ({$target_t1_id}, {$target_t2_id}, {$target_t3_id}, {$target_t4_id}, {$target_t5_id}, {$target_t6_id})");
		$check_cnt = (int)$db->sql_fetchfield('cnt');
		$db->sql_freeresult($res);

		if ($check_cnt !== 6)
		{
			throw new \Exception("Duplicate topics created on rerun!");
		}

		// 7. Verify pre-existing phpBB Admin (user_id = 2) and topics remain completely untouched
		$sql = "SELECT user_id, username, username_clean, user_type FROM {$table_prefix}users WHERE user_id = 2";
		$res = $db->sql_query($sql);
		$admin_row = $db->sql_fetchrow($res);
		$db->sql_freeresult($res);

		if ($admin_row['username_clean'] !== 'admin' || (int)$admin_row['user_type'] !== 3)
		{
			throw new \Exception("CRITICAL INTEGRITY VIOLATION: Pre-existing phpBB admin account was altered!");
		}

		// Clean up migration-owned test data
		$target_topic_ids = implode(',', [$target_t1_id, $target_t2_id, $target_t3_id, $target_t4_id, $target_t5_id, $target_t6_id]);
		$db->sql_query("DELETE FROM {$table_prefix}topics WHERE topic_id IN ({$target_topic_ids})");
		$db->sql_query("DELETE FROM {$table_prefix}users WHERE user_id = {$target_user_id}");
		$db->sql_query("DELETE FROM {$table_prefix}forums WHERE forum_id = {$target_forum_id}");
		$db->sql_query("DELETE FROM {$table_prefix}migration_id_map WHERE run_id = '{$test_run_id}'");

		return true;
	}
}
