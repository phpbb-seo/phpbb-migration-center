<?php
/**
 * Post Migration, BBCode Storage, Topic Finalization & Forum Synchronization Integration Test
 */

namespace phpbbseo\migrationcenter\tests\unit;

use phpbbseo\migrationcenter\core\engine\migration_engine;
use phpbbseo\migrationcenter\core\dto\migration_config_dto;
use phpbbseo\migrationcenter\core\dto\forum_dto;
use phpbbseo\migrationcenter\core\dto\user_dto;
use phpbbseo\migrationcenter\core\dto\topic_dto;
use phpbbseo\migrationcenter\core\dto\post_dto;
use phpbbseo\migrationcenter\source\xenforo\config\xf_config_detector;
use phpbbseo\migrationcenter\source\xenforo\normalizer\xf_post_normalizer;

class PostMigrationIntegrationTest
{
	public function run()
	{
		global $phpbb_container;

		list($db, $table_prefix) = get_test_db();
		$source_path = 'C:/xampp/htdocs/xen';
		$config = xf_config_detector::detect_from_path($source_path);
		if (!$config)
		{
			throw new \Exception("Could not load XenForo config for post integration test");
		}

		// 1. Check pre-existing phpBB posts, topics, and admin
		$sql = "SELECT post_id, topic_id, post_text FROM {$table_prefix}posts ORDER BY post_id ASC";
		$res = $db->sql_query($sql);
		$initial_posts = [];
		while ($r = $db->sql_fetchrow($res))
		{
			$initial_posts[] = $r;
		}
		$db->sql_freeresult($res);

		$initial_posts_count = count($initial_posts);
		if ($initial_posts_count === 0)
		{
			throw new \Exception("Pre-existing phpBB posts not found");
		}

		// 2. Test Dry-Run for posts step
		$dry_config = clone $config;
		$dry_config->selected_steps = ['posts'];
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
		$step_posts = $step_reg->get('posts');
		$step_res = $step_posts->process_batch($dry_run->run_id, 0, 50, $dry_config, $provider_reg->get('xenforo'), $writer);

		if ($step_res->read_count !== 50 || $step_res->imported_count !== 50)
		{
			throw new \Exception("Dry-run posts failed to read 50 source posts");
		}

		$res = $db->sql_query("SELECT COUNT(*) as cnt FROM {$table_prefix}posts");
		$posts_cnt_after_dry = (int)$db->sql_fetchfield('cnt');
		$db->sql_freeresult($res);

		if ($posts_cnt_after_dry !== $initial_posts_count)
		{
			throw new \Exception("Dry-run altered target posts count! Expected {$initial_posts_count}, got {$posts_cnt_after_dry}");
		}

		$lock_mgr->release('migration_xenforo', $dry_run->run_id);

		// 3. Setup Migration-Owned Target Test Data
		$test_run_id = 'test_post_run_' . time();
		$test_forum_src_id = 9971;
		$test_user_src_id = 9972;
		$test_topic_src_id = 9973;

		// Map test forum
		$f = new forum_dto();
		$f->source_id = $test_forum_src_id;
		$f->forum_name = 'Post Test Category/Forum';
		$f_res = $writer->write_forums([$f], ['run_id' => $test_run_id, 'source_system' => 'xenforo']);
		$target_forum_id = (int)$f_res[$test_forum_src_id]['target_id'];

		// Map test user
		$u = new user_dto();
		$u->source_id = $test_user_src_id;
		$u->username = 'PostTestUser';
		$u->email = 'posttest@invalid.local';
		$u_res = $writer->write_users([$u], ['run_id' => $test_run_id, 'source_system' => 'xenforo']);
		$target_user_id = (int)$u_res[$test_user_src_id]['target_id'];

		// Map test topic
		$t = new topic_dto();
		$t->source_id = $test_topic_src_id;
		$t->forum_source_id = $test_forum_src_id;
		$t->user_source_id = $test_user_src_id;
		$t->source_username = 'PostTestUser';
		$t->topic_title = "XXXXX_XXXY_XXX_UnicodeRunner\xE2\x80\x8CXXX 🚀";
		$t_res = $writer->write_topics([$t], ['run_id' => $test_run_id, 'source_system' => 'xenforo']);
		$target_topic_id = (int)$t_res[$test_topic_src_id]['target_id'];

		// 4. Create Diverse Migration-Owned Posts
		$normalizer = new xf_post_normalizer();

		// Post 1: First post with Persian & ZWNJ
		$row1 = [
			'post_id'       => 88001,
			'thread_id'     => $test_topic_src_id,
			'user_id'       => $test_user_src_id,
			'username'      => 'PostTestUser',
			'post_date'     => 1785000100,
			'position'      => 0,
			'message'       => "[b]Main Topic with Bold Text[/b]\nXYX YK XXX XXXY XX Unicode (UnicodeRunner\xE2\x80\x8CXXX) XXX.",
			'message_state' => 'visible',
			'ip_address'    => '192.0.2.1', // Documentation IP
		];
		$p1 = $normalizer->normalize_post($row1, $config);

		// Post 2: Reply with Quote and Code block
		$row2 = [
			'post_id'       => 88002,
			'thread_id'     => $test_topic_src_id,
			'user_id'       => $test_user_src_id,
			'username'      => 'PostTestUser',
			'post_date'     => 1785000200,
			'position'      => 1,
			'message'       => "[quote=\"PostTestUser\"]Main Topic[/quote]\n[code=php]<?php echo 'Hello'; ?>[/code]",
			'message_state' => 'visible',
			'ip_address'    => '2001:db8::1', // Documentation IPv6
		];
		$p2 = $normalizer->normalize_post($row2, $config);

		// Post 3: Reply with 2 attachment deferred markers
		$row3 = [
			'post_id'       => 88003,
			'thread_id'     => $test_topic_src_id,
			'user_id'       => $test_user_src_id,
			'username'      => 'PostTestUser',
			'post_date'     => 1785000300,
			'position'      => 2,
			'message'       => "Screenshot 1: [ATTACH=full]701[/ATTACH]\nScreenshot 2: [ATTACH]702[/ATTACH]",
			'message_state' => 'visible',
			'ip_address'    => '192.0.2.2',
		];
		$p3 = $normalizer->normalize_post($row3, $config);

		// Post 4: Soft-deleted post
		$row4 = [
			'post_id'       => 88004,
			'thread_id'     => $test_topic_src_id,
			'user_id'       => $test_user_src_id,
			'username'      => 'PostTestUser',
			'post_date'     => 1785000400,
			'position'      => 3,
			'message'       => "Deleted spam response",
			'message_state' => 'deleted',
			'ip_address'    => '192.0.2.3',
		];
		$del_log = [
			'delete_date'     => 1785000450,
			'delete_user_id'  => 2,
			'delete_username' => 'admin',
			'delete_reason'   => 'Inappropriate content',
		];
		$p4 = $normalizer->normalize_post($row4, $config, $del_log);

		$posts_to_write = [$p1, $p2, $p3, $p4];

		// 5. Execute write_posts()
		$write_res = $writer->write_posts($posts_to_write, [
			'run_id'        => $test_run_id,
			'source_system' => 'xenforo',
		]);

		foreach ($posts_to_write as $p)
		{
			if (!isset($write_res[$p->source_id]) || $write_res[$p->source_id]['status'] !== 'success')
			{
				throw new \Exception("Post write failed for source post {$p->source_id}: " . json_encode($write_res[$p->source_id] ?? []));
			}
		}

		$target_p1_id = (int)$id_mapper->get_target_id('xenforo', 'post', 88001);
		$target_p2_id = (int)$id_mapper->get_target_id('xenforo', 'post', 88002);
		$target_p3_id = (int)$id_mapper->get_target_id('xenforo', 'post', 88003);
		$target_p4_id = (int)$id_mapper->get_target_id('xenforo', 'post', 88004);

		// Verify database records in phpbb_posts
		$sql = "SELECT post_id, topic_id, forum_id, poster_id, poster_ip, post_visibility, post_text 
				FROM {$table_prefix}posts 
				WHERE post_id IN ({$target_p1_id}, {$target_p2_id}, {$target_p3_id}, {$target_p4_id})";
		$res = $db->sql_query($sql);
		$db_posts = [];
		while ($r = $db->sql_fetchrow($res))
		{
			$db_posts[$r['post_id']] = $r;
		}
		$db->sql_freeresult($res);

		if (count($db_posts) !== 4)
		{
			throw new \Exception("Expected 4 inserted posts in database, found: " . count($db_posts));
		}

		// Verify attachment markers in post 3
		$p3_row = $db_posts[$target_p3_id];
		if (strpos($p3_row['post_text'], '[[MC_ATTACH:701]]') === false || strpos($p3_row['post_text'], '[[MC_ATTACH:702]]') === false)
		{
			throw new \Exception("Deferred attachment markers not found in stored post_text: {$p3_row['post_text']}");
		}

		// Verify IPv6 in post 2
		$p2_row = $db_posts[$target_p2_id];
		if ($p2_row['poster_ip'] !== '2001:db8::1')
		{
			throw new \Exception("IPv6 address storage mismatch. Got: {$p2_row['poster_ip']}");
		}

		// 6. Test Topic Finalization
		$writer->finalize_topics([$target_topic_id]);

		$sql = "SELECT topic_first_post_id, topic_last_post_id, topic_posts_approved, topic_posts_unapproved, topic_posts_softdeleted 
				FROM {$table_prefix}topics WHERE topic_id = {$target_topic_id}";
		$res = $db->sql_query($sql);
		$t_final = $db->sql_fetchrow($res);
		$db->sql_freeresult($res);

		if ((int)$t_final['topic_first_post_id'] !== $target_p1_id)
		{
			throw new \Exception("Topic first post pointer mismatch! Expected {$target_p1_id}, got: {$t_final['topic_first_post_id']}");
		}
		if ((int)$t_final['topic_last_post_id'] !== $target_p4_id)
		{
			throw new \Exception("Topic last post pointer mismatch! Expected {$target_p4_id}, got: {$t_final['topic_last_post_id']}");
		}
		if ((int)$t_final['topic_posts_approved'] !== 2) // 3 approved posts total -> 2 replies
		{
			throw new \Exception("Topic approved replies mismatch! Expected 2, got: {$t_final['topic_posts_approved']}");
		}
		if ((int)$t_final['topic_posts_softdeleted'] !== 1)
		{
			throw new \Exception("Topic softdeleted posts mismatch! Expected 1, got: {$t_final['topic_posts_softdeleted']}");
		}

		// 7. Test Forum Synchronization
		$writer->synchronize_forums([$target_forum_id]);

		$sql = "SELECT forum_topics_approved, forum_posts_approved, forum_last_post_id 
				FROM {$table_prefix}forums WHERE forum_id = {$target_forum_id}";
		$res = $db->sql_query($sql);
		$f_sync = $db->sql_fetchrow($res);
		$db->sql_freeresult($res);

		if ((int)$f_sync['forum_topics_approved'] !== 1 || (int)$f_sync['forum_posts_approved'] !== 3 || (int)$f_sync['forum_last_post_id'] !== $target_p3_id)
		{
			throw new \Exception("Forum synchronization mismatch: " . json_encode($f_sync));
		}

		// 8. Test Idempotency / Duplicate Prevention on Rerun
		$rerun_res = $writer->write_posts($posts_to_write, [
			'run_id'        => $test_run_id,
			'source_system' => 'xenforo',
		]);

		$res = $db->sql_query("SELECT COUNT(*) as cnt FROM {$table_prefix}posts WHERE post_id IN ({$target_p1_id}, {$target_p2_id}, {$target_p3_id}, {$target_p4_id})");
		$cnt_check = (int)$db->sql_fetchfield('cnt');
		$db->sql_freeresult($res);

		if ($cnt_check !== 4)
		{
			throw new \Exception("Duplicate posts created on rerun!");
		}

		// 9. Verify pre-existing phpBB content & admin integrity
		$sql = "SELECT user_id, username_clean, user_type FROM {$table_prefix}users WHERE user_id = 2";
		$res = $db->sql_query($sql);
		$admin_row = $db->sql_fetchrow($res);
		$db->sql_freeresult($res);

		if ($admin_row['username_clean'] !== 'admin' || (int)$admin_row['user_type'] !== 3)
		{
			throw new \Exception("CRITICAL INTEGRITY VIOLATION: Pre-existing admin modified!");
		}

		// Clean up migration-owned test records
		$target_post_ids = implode(',', [$target_p1_id, $target_p2_id, $target_p3_id, $target_p4_id]);
		$db->sql_query("DELETE FROM {$table_prefix}posts WHERE post_id IN ({$target_post_ids})");
		$db->sql_query("DELETE FROM {$table_prefix}topics WHERE topic_id = {$target_topic_id}");
		$db->sql_query("DELETE FROM {$table_prefix}users WHERE user_id = {$target_user_id}");
		$db->sql_query("DELETE FROM {$table_prefix}forums WHERE forum_id = {$target_forum_id}");
		$db->sql_query("DELETE FROM {$table_prefix}migration_id_map WHERE run_id = '{$test_run_id}'");

		return true;
	}
}
