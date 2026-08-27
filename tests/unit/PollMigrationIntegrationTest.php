<?php
/**
 * Phase 5D Security & Phase 5E Thread Poll Migration Integration Test
 */

namespace phpbbseo\migrationcenter\tests\unit;

use phpbbseo\migrationcenter\core\dto\user_dto;
use phpbbseo\migrationcenter\core\dto\topic_dto;
use phpbbseo\migrationcenter\core\dto\poll_dto;
use phpbbseo\migrationcenter\core\dto\poll_option_dto;
use phpbbseo\migrationcenter\core\dto\poll_vote_dto;
use phpbbseo\migrationcenter\core\dto\attachment_dto;
use phpbbseo\migrationcenter\source\xenforo\step\polls_step;

class PollMigrationIntegrationTest
{
	public function run()
	{
		global $phpbb_container, $phpbb_dispatcher;

		list($db, $table_prefix) = get_test_db();
		$id_mapper = $phpbb_container->get('phpbbseo.migrationcenter.id_mapper');
		$writer = $phpbb_container->get('phpbbseo.migrationcenter.target_writer');
		$listener = $phpbb_container->get('phpbbseo.migrationcenter.listener');

		$run_id = 'poll_test_run_' . time();
		$source_system = 'xenforo';

		// Clean up any test records from prior runs
		$db->sql_query("DELETE FROM {$table_prefix}migration_id_map WHERE source_system = '{$source_system}' AND source_id IN ('501', '502', '503', '101', '102', '201', '202', '203', '301', '302', '8501', '8502', '8503', '9501', '9502', '9503', '999988', '77702', '9911', '9912', '9913')");

		// =========================================================================
		// PART A: PHASE 5D SECURITY & PM ATTACHMENT VERIFICATION
		// =========================================================================

		// A1: Verify Event Existence in phpBB source code
		$func_download_file = defined('PHPBB_ROOT_PATH') ? PHPBB_ROOT_PATH . 'includes/functions_download.php' : 'C:/xampp/htdocs/bb/includes/functions_download.php';
		if (!file_exists($func_download_file))
		{
			throw new \Exception("phpBB functions_download.php not found at {$func_download_file}");
		}
		$func_download_src = file_get_contents($func_download_file);
		if (strpos($func_download_src, "core.modify_pm_attach_download_auth") === false)
		{
			throw new \Exception("CRITICAL: Event core.modify_pm_attach_download_auth is missing from phpBB source code!");
		}

		// A2: Real Event Dispatcher Test & Migration-Owned Scope
		// Create a synthetic native non-migration PM (msg_id 88801) and a migration PM (msg_id 88802)
		$db->sql_query("INSERT INTO {$table_prefix}privmsgs (msg_id, root_level, author_id, message_time, message_subject, message_text, to_address, bcc_address) 
						VALUES (88801, 0, 2, 1000, 'Native PM', 'Native text', 'u_2:', '')");
		$db->sql_query("INSERT INTO {$table_prefix}privmsgs (msg_id, root_level, author_id, message_time, message_subject, message_text, to_address, bcc_address) 
						VALUES (88802, 0, 2, 1000, 'Migrated PM', 'Migrated text', 'u_2:', '')");

		// Record 88802 in migration_id_map
		$id_mapper->set($run_id, $source_system, 'privmsg', 77702, 88802, 'mapped');

		// Dispatch event through real $phpbb_dispatcher for native PM (88801)
		// Should retain allowed = true because it is NOT migration-owned
		$event_native = $phpbb_dispatcher->trigger_event('core.modify_pm_attach_download_auth', [
			'allowed' => true,
			'msg_id'  => 88801,
			'user_id' => 999, // Unrelated user
		]);
		if ($event_native['allowed'] !== true)
		{
			throw new \Exception("Existing native non-migration PM attachment authorization must NOT be restricted by Migration Center listener!");
		}

		// Dispatch event through real $phpbb_dispatcher for migrated PM (88802) with unauthorized user
		// Should be flipped to allowed = false because user 999 has no privmsgs_to row!
		$event_migrated = $phpbb_dispatcher->trigger_event('core.modify_pm_attach_download_auth', [
			'allowed' => true,
			'msg_id'  => 88802,
			'user_id' => 999,
		]);
		if ($event_migrated['allowed'] !== false)
		{
			throw new \Exception("Migration-owned PM attachment must be strictly DENIED for unauthorized user!");
		}

		// A3: Founder/Admin non-participant denial
		// Admin User ID 2 is founder, but for a migration PM where User 2 has no row (or deleted row), User 2 must be DENIED!
		$event_founder = $phpbb_dispatcher->trigger_event('core.modify_pm_attach_download_auth', [
			'allowed' => true,
			'msg_id'  => 88802,
			'user_id' => 2, // Admin/Founder
		]);
		if ($event_founder['allowed'] !== false)
		{
			throw new \Exception("Founder/Admin without active privmsgs_to participant row must be DENIED PM attachment access!");
		}

		// A4: Equal-filetime deterministic ordering test
		// Create 3 PM attachments for msg 88802 all having source filetime = 1500000000
		$tmp_att_dir = sys_get_temp_dir() . '/equal_time_test_' . time();
		@mkdir($tmp_att_dir, 0777, true);

		$p1 = $tmp_att_dir . '/p1.png';
		$p2 = $tmp_att_dir . '/p2.png';
		$p3 = $tmp_att_dir . '/p3.png';
		file_put_contents($p1, 'png1');
		file_put_contents($p2, 'png2');
		file_put_contents($p3, 'png3');

		$att_eq1 = new attachment_dto();
		$att_eq1->source_id = 9911;
		$att_eq1->content_type = 'conversation_message';
		$att_eq1->post_source_id = 77702;
		$att_eq1->real_filename = 'file1.png';
		$att_eq1->source_physical_path = $p1;
		$att_eq1->filesize = filesize($p1);
		$att_eq1->filetime = 1500000000;

		$att_eq2 = new attachment_dto();
		$att_eq2->source_id = 9912;
		$att_eq2->content_type = 'conversation_message';
		$att_eq2->post_source_id = 77702;
		$att_eq2->real_filename = 'file2.png';
		$att_eq2->source_physical_path = $p2;
		$att_eq2->filesize = filesize($p2);
		$att_eq2->filetime = 1500000000;

		$att_eq3 = new attachment_dto();
		$att_eq3->source_id = 9913;
		$att_eq3->content_type = 'conversation_message';
		$att_eq3->post_source_id = 77702;
		$att_eq3->real_filename = 'file3.png';
		$att_eq3->source_physical_path = $p3;
		$att_eq3->filesize = filesize($p3);
		$att_eq3->filetime = 1500000000;

		$res_eq = $writer->write_attachments([$att_eq1, $att_eq2, $att_eq3], [
			'run_id'        => $run_id,
			'source_system' => $source_system,
		]);

		$tid1 = (int)$res_eq[9911]['target_id'];
		$tid2 = (int)$res_eq[9912]['target_id'];
		$tid3 = (int)$res_eq[9913]['target_id'];

		$sql = "SELECT attach_id, filetime FROM {$table_prefix}attachments WHERE attach_id IN ({$tid1}, {$tid2}, {$tid3}) ORDER BY filetime DESC";
		$res = $db->sql_query($sql);
		$ordered_times = [];
		while ($r = $db->sql_fetchrow($res))
		{
			$ordered_times[] = (int)$r['filetime'];
		}
		$db->sql_freeresult($res);

		// Assert that filetimes are strictly decreasing and unique (no ties)
		if ($ordered_times[0] <= $ordered_times[1] || $ordered_times[1] <= $ordered_times[2])
		{
			throw new \Exception("Equal filetime collision adjustment failed: " . json_encode($ordered_times));
		}

		// Cleanup Part A test records
		$db->sql_query("DELETE FROM {$table_prefix}attachments WHERE attach_id IN ({$tid1}, {$tid2}, {$tid3})");
		$db->sql_query("DELETE FROM {$table_prefix}privmsgs WHERE msg_id IN (88801, 88802)");
		@unlink($p1);
		@unlink($p2);
		@unlink($p3);
		@rmdir($tmp_att_dir);

		// =========================================================================
		// PART B: PHASE 5E XENFORO THREAD POLLS TO PHPBB TOPIC POLLS
		// =========================================================================

		// Setup Test Users: Voter 1 (9501), Voter 2 (9502), Voter 3 (9503)
		$u1 = new user_dto();
		$u1->source_id = 9501;
		$u1->username = 'PollVoterOne';
		$u1->email = 'voter1@invalid.local';

		$u2 = new user_dto();
		$u2->source_id = 9502;
		$u2->username = 'PollVoterTwo';
		$u2->email = 'voter2@invalid.local';

		$u3 = new user_dto();
		$u3->source_id = 9503;
		$u3->username = 'PollVoterThree';
		$u3->email = 'voter3@invalid.local';

		$u_res = $writer->write_users([$u1, $u2, $u3], ['run_id' => $run_id, 'source_system' => $source_system]);
		$target_u1 = (int)$u_res[9501]['target_id'];
		$target_u2 = (int)$u_res[9502]['target_id'];
		$target_u3 = (int)$u_res[9503]['target_id'];

		// Ensure forum 1 is mapped to target forum 2
		$id_mapper->set($run_id, $source_system, 'forum', 1, 2);

		// Setup Target Topics: Topic 1 (Source 8501), Topic 2 (Source 8502), Topic 3 (Source 8503)
		$t1 = new topic_dto();
		$t1->source_id = 8501;
		$t1->forum_source_id = 1;
		$t1->user_source_id = 9501;
		$t1->title = 'Single Choice Permanent Poll Topic';

		$t2 = new topic_dto();
		$t2->source_id = 8502;
		$t2->forum_source_id = 1;
		$t2->user_source_id = 9502;
		$t2->title = 'Multiple Choice Timed Poll Topic';

		$t3 = new topic_dto();
		$t3->source_id = 8503;
		$t3->forum_source_id = 1;
		$t3->user_source_id = 9503;
		$t3->title = 'Closed Persian Emoji Poll Topic';

		$t_res = $writer->write_topics([$t1, $t2, $t3], ['run_id' => $run_id, 'source_system' => $source_system]);
		$target_t1 = (int)$t_res[8501]['target_id'];
		$target_t2 = (int)$t_res[8502]['target_id'];
		$target_t3 = (int)$t_res[8503]['target_id'];

		// Poll 1: Single-Choice Permanent Poll (Source Poll 501 on Thread 8501)
		// Options: Opt 1 (Resp 101), Opt 2 (Resp 102)
		// Votes: User 9501 -> Resp 101, User 9502 -> Resp 101, User 9503 -> Resp 102
		$poll1 = new poll_dto();
		$poll1->source_id = 501;
		$poll1->content_type = 'thread';
		$poll1->thread_source_id = 8501;
		$poll1->question = 'Which PHP version do you prefer?';
		$poll1->max_votes = 1;
		$poll1->close_date = 0; // Permanent
		$poll1->change_vote = true;
		$poll1->voter_count = 3;

		$opt1_1 = new poll_option_dto();
		$opt1_1->source_id = 101;
		$opt1_1->poll_source_id = 501;
		$opt1_1->option_text = 'PHP 8.2';
		$opt1_1->option_order = 1;

		$opt1_2 = new poll_option_dto();
		$opt1_2->source_id = 102;
		$opt1_2->poll_source_id = 501;
		$opt1_2->option_text = 'PHP 8.3';
		$opt1_2->option_order = 2;

		$poll1->responses = [101 => $opt1_1, 102 => $opt1_2];

		$v1 = new poll_vote_dto();
		$v1->poll_source_id = 501;
		$v1->user_source_id = 9501;
		$v1->response_source_id = 101;
		$v1->vote_date = 1000;

		$v2 = new poll_vote_dto();
		$v2->poll_source_id = 501;
		$v2->user_source_id = 9502;
		$v2->response_source_id = 101;
		$v2->vote_date = 1050;

		$v3 = new poll_vote_dto();
		$v3->poll_source_id = 501;
		$v3->user_source_id = 9503;
		$v3->response_source_id = 102;
		$v3->vote_date = 1100;

		// Missing voter vote (User 999988) -> should be skipped safely
		$v_missing = new poll_vote_dto();
		$v_missing->poll_source_id = 501;
		$v_missing->user_source_id = 999988;
		$v_missing->response_source_id = 101;
		$v_missing->vote_date = 1200;

		$poll1->votes = [$v1, $v2, $v3, $v_missing];

		// Poll 2: Multiple-Choice Timed Poll (Source Poll 502 on Thread 8502)
		// max_votes = 2, close_date in future
		// Options: Opt 1 (Resp 201), Opt 2 (Resp 202), Opt 3 (Resp 203)
		// Duplicate option text: 'Option A' and 'Option A' (should remain distinct option IDs)
		$poll2 = new poll_dto();
		$poll2->source_id = 502;
		$poll2->content_type = 'thread';
		$poll2->thread_source_id = 8502;
		$poll2->question = 'Select your favorite frameworks';
		$poll2->max_votes = 2;
		$poll2->start_date = 1000;
		$poll2->close_date = 1000 + 86400; // 1 day length
		$poll2->change_vote = false;

		$opt2_1 = new poll_option_dto();
		$opt2_1->source_id = 201;
		$opt2_1->poll_source_id = 502;
		$opt2_1->option_text = 'Symfony';
		$opt2_1->option_order = 1;

		$opt2_2 = new poll_option_dto();
		$opt2_2->source_id = 202;
		$opt2_2->poll_source_id = 502;
		$opt2_2->option_text = 'Duplicate Name';
		$opt2_2->option_order = 2;

		$opt2_3 = new poll_option_dto();
		$opt2_3->source_id = 203;
		$opt2_3->poll_source_id = 502;
		$opt2_3->option_text = 'Duplicate Name';
		$opt2_3->option_order = 3;

		$poll2->responses = [201 => $opt2_1, 202 => $opt2_2, 203 => $opt2_3];

		// Voter 1 selects 2 options: 201 and 202
		$v2_1a = new poll_vote_dto();
		$v2_1a->poll_source_id = 502;
		$v2_1a->user_source_id = 9501;
		$v2_1a->response_source_id = 201;

		$v2_1b = new poll_vote_dto();
		$v2_1b->poll_source_id = 502;
		$v2_1b->user_source_id = 9501;
		$v2_1b->response_source_id = 202;

		// Duplicate vote submission by voter 1 for 201 -> should be deduplicated
		$v2_dup = new poll_vote_dto();
		$v2_dup->poll_source_id = 502;
		$v2_dup->user_source_id = 9501;
		$v2_dup->response_source_id = 201;

		$poll2->votes = [$v2_1a, $v2_1b, $v2_dup];

		// Poll 3: Closed Persian & Emoji Poll (Source Poll 503 on Thread 8503)
		// Closed in past, Unicode question & options
		$poll3 = new poll_dto();
		$poll3->source_id = 503;
		$poll3->content_type = 'thread';
		$poll3->thread_source_id = 8503;
		$poll3->question = 'Unicode Poll With Emoji 🚀 Test';
		$poll3->max_votes = 1;
		$poll3->start_date = 500;
		$poll3->close_date = 600; // Closed in past

		$opt3_1 = new poll_option_dto();
		$opt3_1->source_id = 301;
		$opt3_1->poll_source_id = 503;
		$opt3_1->option_text = 'Option 1: Excellent ✨';
		$opt3_1->option_order = 1;

		$opt3_2 = new poll_option_dto();
		$opt3_2->source_id = 302;
		$opt3_2->poll_source_id = 503;
		$opt3_2->option_text = 'Option 2: Average ⚡';
		$opt3_2->option_order = 2;

		$poll3->responses = [301 => $opt3_1, 302 => $opt3_2];
		$poll3->votes = [];

		// Execute write_polls
		$res_polls = $writer->write_polls([$poll1, $poll2, $poll3], [
			'run_id'        => $run_id,
			'source_system' => $source_system,
		]);

		// Verify Poll 1 Results
		if ($res_polls[501]['status'] !== 'success')
		{
			throw new \Exception("write_polls failed for Poll 1: " . json_encode($res_polls[501]));
		}
		$t1_row = $db->sql_fetchrow($db->sql_query("SELECT poll_title, poll_start, poll_length, poll_max_options, poll_last_vote, poll_vote_change FROM {$table_prefix}topics WHERE topic_id = {$target_t1}"));
		if ($t1_row['poll_title'] !== 'Which PHP version do you prefer?' || (int)$t1_row['poll_length'] !== 0 || (int)$t1_row['poll_max_options'] !== 1 || (int)$t1_row['poll_vote_change'] !== 1 || (int)$t1_row['poll_last_vote'] !== 1100)
		{
			throw new \Exception("Poll 1 topic fields mismatch: " . json_encode($t1_row));
		}

		// Verify Option Totals Reconciled from Vote Rows for Poll 1
		// Option 1 (PHP 8.2) should have total = 2
		// Option 2 (PHP 8.3) should have total = 1
		$opt1_rows = [];
		$res = $db->sql_query("SELECT poll_option_id, poll_option_text, poll_option_total FROM {$table_prefix}poll_options WHERE topic_id = {$target_t1} ORDER BY poll_option_id ASC");
		while ($r = $db->sql_fetchrow($res))
		{
			$opt1_rows[(int)$r['poll_option_id']] = $r;
		}
		$db->sql_freeresult($res);

		if ((int)$opt1_rows[1]['poll_option_total'] !== 2 || (int)$opt1_rows[2]['poll_option_total'] !== 1)
		{
			throw new \Exception("Poll 1 option totals mismatch. Expected Opt 1 = 2, Opt 2 = 1. Got: " . json_encode($opt1_rows));
		}

		// Verify Poll 2 Multiple Choice & Deduplication
		if ($res_polls[502]['status'] !== 'success')
		{
			throw new \Exception("write_polls failed for Poll 2: " . json_encode($res_polls[502]));
		}
		$t2_row = $db->sql_fetchrow($db->sql_query("SELECT poll_max_options, poll_length FROM {$table_prefix}topics WHERE topic_id = {$target_t2}"));
		if ((int)$t2_row['poll_max_options'] !== 2 || (int)$t2_row['poll_length'] !== 86400)
		{
			throw new \Exception("Poll 2 max_options/length mismatch: " . json_encode($t2_row));
		}

		// Verify 2 votes recorded (deduplicated)
		$v_cnt2 = (int)$db->sql_fetchfield('cnt', 0, $db->sql_query("SELECT COUNT(*) as cnt FROM {$table_prefix}poll_votes WHERE topic_id = {$target_t2}"));
		if ($v_cnt2 !== 2)
		{
			throw new \Exception("Poll 2 expected exactly 2 deduplicated vote rows, got {$v_cnt2}");
		}

		// Verify Poll 3 Unicode & Closed Status
		if ($res_polls[503]['status'] !== 'success')
		{
			throw new \Exception("write_polls failed for Poll 3: " . json_encode($res_polls[503]));
		}
		$t3_row = $db->sql_fetchrow($db->sql_query("SELECT poll_title, poll_start, poll_length FROM {$table_prefix}topics WHERE topic_id = {$target_t3}"));
		if (strpos($t3_row['poll_title'], 'Unicode Poll With Emoji') === false)
		{
			throw new \Exception("Poll 3 Unicode Persian title corrupted: {$t3_row['poll_title']}");
		}
		if ((int)($t3_row['poll_start'] + $t3_row['poll_length']) > time())
		{
			throw new \Exception("Poll 3 closed poll was accidentally reopened!");
		}

		// Cleanup Test Data
		$db->sql_query("DELETE FROM {$table_prefix}poll_votes WHERE topic_id IN ({$target_t1}, {$target_t2}, {$target_t3})");
		$db->sql_query("DELETE FROM {$table_prefix}poll_options WHERE topic_id IN ({$target_t1}, {$target_t2}, {$target_t3})");
		$db->sql_query("DELETE FROM {$table_prefix}topics WHERE topic_id IN ({$target_t1}, {$target_t2}, {$target_t3})");
		$db->sql_query("DELETE FROM {$table_prefix}users WHERE user_id IN ({$target_u1}, {$target_u2}, {$target_u3})");
		$db->sql_query("DELETE FROM {$table_prefix}migration_id_map WHERE run_id = '{$run_id}'");

		return true;
	}
}
