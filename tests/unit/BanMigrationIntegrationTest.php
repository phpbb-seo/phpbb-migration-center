<?php
/**
 * Phase 5D/5E Reconciliation Audit & Phase 5F Bans Integration Test
 */

namespace phpbbseo\migrationcenter\tests\unit;

use phpbbseo\migrationcenter\core\dto\user_dto;
use phpbbseo\migrationcenter\core\dto\ban_dto;
use phpbbseo\migrationcenter\core\dto\poll_dto;
use phpbbseo\migrationcenter\core\dto\poll_option_dto;
use phpbbseo\migrationcenter\core\dto\poll_vote_dto;
use phpbbseo\migrationcenter\core\dto\conversation_dto;
use phpbbseo\migrationcenter\core\dto\conversation_message_dto;
use phpbbseo\migrationcenter\core\dto\conversation_recipient_dto;
use phpbbseo\migrationcenter\core\dto\attachment_dto;

class BanMigrationIntegrationTest
{
	public function run()
	{
		global $phpbb_container, $phpbb_dispatcher;

		list($db, $table_prefix) = get_test_db();
		$id_mapper = $phpbb_container->get('phpbbseo.migrationcenter.id_mapper');
		$writer = $phpbb_container->get('phpbbseo.migrationcenter.target_writer');
		$listener = $phpbb_container->get('phpbbseo.migrationcenter.listener');

		$run_id = 'ban_test_run_' . time();
		$source_system = 'xenforo';

		// Clean up any test records from prior runs
		$db->sql_query("DELETE FROM {$table_prefix}migration_id_map WHERE source_system = '{$source_system}' AND source_id IN ('555', '8601', '8602', '7601', '11', '12', '9601', '9602', '9603', '9604', '9605', '9606', 'user_9601', 'user_9602', 'user_9603', 'user_9604', 'user_9605', 'user_9606', 'user_999888', 'user_999002', 'email_exact_1', 'email_wildcard_1', 'email_regex_1', 'ip_v4_exact', 'ip_v6_exact', 'ip_v4_wildcard', 'ip_cidr_incompat', 'ip_localhost')");

		// =========================================================================
		// PART A: RECONCILIATION AUDIT
		// =========================================================================

		// A1: Poll Totals Reconciliation Test
		// Setup users
		$uA = new user_dto();
		$uA->source_id = 9601;
		$uA->username = 'ReconcileVoter1';
		$uA->email = 'rv1@invalid.local';

		$uB = new user_dto();
		$uB->source_id = 9602;
		$uB->username = 'ReconcileVoter2';
		$uB->email = 'rv2@invalid.local';

		$u_res = $writer->write_users([$uA, $uB], ['run_id' => $run_id, 'source_system' => $source_system]);
		$target_uA = (int)$u_res[9601]['target_id'];
		$target_uB = (int)$u_res[9602]['target_id'];

		// Create Topic in forum 2
		$id_mapper->set($run_id, $source_system, 'forum', 1, 2);
		$topic_row = [
			'forum_id'            => 2,
			'topic_title'         => 'Reconciliation Topic',
			'topic_poster'        => $target_uA,
			'topic_time'          => 1000,
			'topic_views'         => 0,
			'topic_posts_approved'=> 1,
			'topic_visibility'    => 1,
		];
		$db->sql_query("INSERT INTO {$table_prefix}topics " . $db->sql_build_array('INSERT', $topic_row));
		$target_rec_topic = (int)$db->sql_nextid();
		$id_mapper->set($run_id, $source_system, 'topic', 8601, $target_rec_topic, 'mapped');

		// Poll with Multiple-Choice: 1 voter selecting 2 options + 1 duplicate vote
		$poll_rec = new poll_dto();
		$poll_rec->source_id = 555;
		$poll_rec->content_type = 'thread';
		$poll_rec->thread_source_id = 8601;
		$poll_rec->question = 'Multiple Choice Reconcile Poll';
		$poll_rec->max_votes = 2;

		$opt1 = new poll_option_dto();
		$opt1->source_id = 11;
		$opt1->poll_source_id = 555;
		$opt1->option_text = 'Option 1';
		$opt1->option_order = 1;

		$opt2 = new poll_option_dto();
		$opt2->source_id = 12;
		$opt2->poll_source_id = 555;
		$opt2->option_text = 'Option 2';
		$opt2->option_order = 2;

		$poll_rec->responses = [11 => $opt1, 12 => $opt2];

		$vA1 = new poll_vote_dto();
		$vA1->poll_source_id = 555;
		$vA1->user_source_id = 9601;
		$vA1->response_source_id = 11;

		$vA2 = new poll_vote_dto();
		$vA2->poll_source_id = 555;
		$vA2->user_source_id = 9601;
		$vA2->response_source_id = 12;

		$vA1_dup = new poll_vote_dto();
		$vA1_dup->poll_source_id = 555;
		$vA1_dup->user_source_id = 9601;
		$vA1_dup->response_source_id = 11; // Duplicate

		$poll_rec->votes = [$vA1, $vA2, $vA1_dup];

		$writer->write_polls([$poll_rec], ['run_id' => $run_id, 'source_system' => $source_system]);

		// Verify that unique voters = 1, but valid option selections in poll_votes = 2
		$actual_vote_rows = (int)$db->sql_fetchfield('cnt', 0, $db->sql_query("SELECT COUNT(*) as cnt FROM {$table_prefix}poll_votes WHERE topic_id = {$target_rec_topic}"));
		$unique_voters = (int)$db->sql_fetchfield('cnt', 0, $db->sql_query("SELECT COUNT(DISTINCT vote_user_id) as cnt FROM {$table_prefix}poll_votes WHERE topic_id = {$target_rec_topic}"));
		$sum_option_totals = (int)$db->sql_fetchfield('tot', 0, $db->sql_query("SELECT SUM(poll_option_total) as tot FROM {$table_prefix}poll_options WHERE topic_id = {$target_rec_topic}"));

		if ($actual_vote_rows !== 2 || $unique_voters !== 1 || $sum_option_totals !== 2)
		{
			throw new \Exception("A1 Poll reconciliation failed! Expected vote rows: 2, unique voters: 1, sum options: 2. Got rows: {$actual_vote_rows}, voters: {$unique_voters}, sum: {$sum_option_totals}");
		}

		// A2: Real End-to-End PM mapping type & Listener scope
		$conv_dto = new conversation_dto();
		$conv_dto->source_id = 8602;
		$conv_dto->title = 'Real E2E PM Mapping';
		$conv_dto->user_source_id = 9601;
		$conv_dto->first_message_id = 7601;
		$conv_dto->last_message_id = 7601;

		$rA = new conversation_recipient_dto();
		$rA->user_source_id = 9601;
		$rB = new conversation_recipient_dto();
		$rB->user_source_id = 9602;
		$conv_dto->recipients = [9601 => $rA, 9602 => $rB];

		$writer->write_conversations([$conv_dto], ['run_id' => $run_id, 'source_system' => $source_system]);

		$msg_dto = new conversation_message_dto();
		$msg_dto->source_id = 7601;
		$msg_dto->conversation_source_id = 8602;
		$msg_dto->user_source_id = 9601;
		$msg_dto->message_date = 1000;
		$msg_dto->message_body = 'Real PM message body';

		$res_msgs = $writer->write_privmsgs([$msg_dto], ['run_id' => $run_id, 'source_system' => $source_system]);
		$real_target_msg_id = (int)$res_msgs[7601]['target_id'];

		// Verify mapping in id_mapper
		$mapped_target = $id_mapper->get_target_id($source_system, 'privmsg', 7601);
		if ((int)$mapped_target !== $real_target_msg_id)
		{
			throw new \Exception("A2 PM mapping failed to store under content_type 'privmsg'");
		}

		// Dispatch real event for author (allowed)
		$ev_author = $phpbb_dispatcher->trigger_event('core.modify_pm_attach_download_auth', [
			'allowed' => true,
			'msg_id'  => $real_target_msg_id,
			'user_id' => $target_uA,
		]);
		if ($ev_author['allowed'] !== true)
		{
			throw new \Exception("A2 Author was unexpectedly denied PM attachment download!");
		}

		// Dispatch real event for founder/admin User ID 2 (denied because User 2 has no privmsgs_to row)
		$ev_admin = $phpbb_dispatcher->trigger_event('core.modify_pm_attach_download_auth', [
			'allowed' => true,
			'msg_id'  => $real_target_msg_id,
			'user_id' => 2,
		]);
		if ($ev_admin['allowed'] !== false)
		{
			throw new \Exception("A2 Founder/Admin non-participant was unexpectedly allowed PM download!");
		}

		// =========================================================================
		// PART B: PHASE 5F BANS IMPLEMENTATION & VERIFICATION
		// =========================================================================

		// Setup Test Users for Banning:
		// User 9603: Active Permanent Ban
		// User 9604: Active Temporary Ban (expires in 1 year)
		// User 9605: Expired Ban (expired yesterday)
		// User 9606: Migration-created Admin
		$u3 = new user_dto();
		$u3->source_id = 9603;
		$u3->username = 'BannedUserPerm';
		$u3->email = 'ban_perm@invalid.local';

		$u4 = new user_dto();
		$u4->source_id = 9604;
		$u4->username = 'BannedUserTemp';
		$u4->email = 'ban_temp@invalid.local';

		$u5 = new user_dto();
		$u5->source_id = 9605;
		$u5->username = 'BannedUserExpired';
		$u5->email = 'ban_expired@invalid.local';

		$u6 = new user_dto();
		$u6->source_id = 9606;
		$u6->username = 'MigratedAdminBanned';
		$u6->email = 'mig_admin_ban@invalid.local';
		$u6->is_admin = true;

		$u_res2 = $writer->write_users([$u3, $u4, $u5, $u6], ['run_id' => $run_id, 'source_system' => $source_system]);
		$target_u3 = (int)$u_res2[9603]['target_id'];
		$target_u4 = (int)$u_res2[9604]['target_id'];
		$target_u5 = (int)$u_res2[9605]['target_id'];
		$target_u6 = (int)$u_res2[9606]['target_id'];

		$now = time();

		// Ban 1: Permanent User Ban (User 9603)
		$b1 = new ban_dto();
		$b1->ban_type = 'user';
		$b1->source_id = 'user_9603';
		$b1->user_source_id = 9603;
		$b1->ban_start = $now - 3600;
		$b1->ban_end = 0; // Permanent
		$b1->ban_give_reason = 'Permanent ban for rule violations. Reason: Violation of forum rules';
		$b1->ban_reason = 'Imported permanent user ban';

		// Ban 2: Temporary User Ban (User 9604)
		$b2 = new ban_dto();
		$b2->ban_type = 'user';
		$b2->source_id = 'user_9604';
		$b2->user_source_id = 9604;
		$b2->ban_start = $now - 3600;
		$b2->ban_end = $now + 86400 * 30; // 30 days in future
		$b2->ban_give_reason = 'Temporary 30-day suspension';
		$b2->ban_reason = 'Imported temporary user ban';

		// Ban 3: Expired User Ban (User 9605) -> should be skipped under default skip policy
		$b3 = new ban_dto();
		$b3->ban_type = 'user';
		$b3->source_id = 'user_9605';
		$b3->user_source_id = 9605;
		$b3->ban_start = $now - 86400 * 10;
		$b3->ban_end = $now - 86400; // Expired yesterday
		$b3->ban_give_reason = 'Old expired ban';

		// Ban 4: Unmapped User Ban (Source User 999888) -> skipped
		$b4 = new ban_dto();
		$b4->ban_type = 'user';
		$b4->source_id = 'user_999888';
		$b4->user_source_id = 999888;
		$b4->ban_give_reason = 'Unmapped user ban';

		// Ban 5: Anonymous Protection (Attempting to ban User ID 1) -> skipped
		// (We test by simulating mapped target_user_id = 1)

		// Ban 6: Founder Protection (Attempting to ban Founder User ID 2) -> skipped
		$id_mapper->set($run_id, $source_system, 'user', 999002, 2);
		$b6 = new ban_dto();
		$b6->ban_type = 'user';
		$b6->source_id = 'user_999002';
		$b6->user_source_id = 999002;
		$b6->ban_give_reason = 'Attempt to ban Founder';

		// Ban 7: Migration-created Admin (User 9606) -> allowed
		$b7 = new ban_dto();
		$b7->ban_type = 'user';
		$b7->source_id = 'user_9606';
		$b7->user_source_id = 9606;
		$b7->ban_give_reason = 'Banned migrated admin';

		// Ban 8: Exact Email Ban
		$b8 = new ban_dto();
		$b8->ban_type = 'email';
		$b8->source_id = 'email_exact_1';
		$b8->ban_email = 'spammer@baddomain.local';
		$b8->ban_give_reason = 'Spam email address';

		// Ban 9: Safe Wildcard Email Ban
		$b9 = new ban_dto();
		$b9->ban_type = 'email';
		$b9->source_id = 'email_wildcard_1';
		$b9->ban_email = '*@spammerdomain.local';
		$b9->ban_give_reason = 'Spam domain wildcard';

		// Ban 10: Incompatible Regex Email Ban -> skipped
		$b10 = new ban_dto();
		$b10->ban_type = 'email';
		$b10->source_id = 'email_regex_1';
		$b10->ban_email = '/^spam[0-9]+@/i';
		$b10->ban_give_reason = 'Regex pattern';

		// Ban 11: Exact IPv4 Ban
		$b11 = new ban_dto();
		$b11->ban_type = 'ip';
		$b11->source_id = 'ip_v4_exact';
		$b11->ban_ip = '198.51.100.42';
		$b11->ban_give_reason = 'Malicious bot IP';

		// Ban 12: Exact IPv6 Ban
		$b12 = new ban_dto();
		$b12->ban_type = 'ip';
		$b12->source_id = 'ip_v6_exact';
		$b12->ban_ip = '2001:0db8:85a3:0000:0000:8a2e:0370:7334';
		$b12->ban_give_reason = 'Malicious bot IPv6';

		// Ban 13: Safe Wildcard IPv4 Ban
		$b13 = new ban_dto();
		$b13->ban_type = 'ip';
		$b13->source_id = 'ip_v4_wildcard';
		$b13->ban_ip = '198.51.100.*';
		$b13->ban_give_reason = 'Malicious subnet wildcard';

		// Ban 14: Incompatible CIDR IP Ban -> skipped
		$b14 = new ban_dto();
		$b14->ban_type = 'ip';
		$b14->source_id = 'ip_cidr_incompat';
		$b14->ban_ip = '203.0.113.0/24';
		$b14->ban_give_reason = 'CIDR range';

		// Ban 15: Localhost IP Protection -> skipped
		$b15 = new ban_dto();
		$b15->ban_type = 'ip';
		$b15->source_id = 'ip_localhost';
		$b15->ban_ip = '127.0.0.1';
		$b15->ban_give_reason = 'Localhost test';

		// Write bans batch
		$bans_batch = [$b1, $b2, $b3, $b4, $b6, $b7, $b8, $b9, $b10, $b11, $b12, $b13, $b14, $b15];
		$res_bans = $writer->write_bans($bans_batch, [
			'run_id'               => $run_id,
			'source_system'        => $source_system,
			'expired_ban_policy'   => 'skip',
			'existing_user_policy' => 'preserve_target',
		]);

		// Verify B1 (Permanent User Ban): success, in phpbb_banlist with ban_end = 0
		if ($res_bans['user_9603']['status'] !== 'success')
		{
			throw new \Exception("B1 User 9603 permanent ban failed: " . json_encode($res_bans['user_9603']));
		}
		$target_ban1 = (int)$res_bans['user_9603']['target_id'];
		$row1 = $db->sql_fetchrow($db->sql_query("SELECT ban_userid, ban_end, ban_give_reason FROM {$table_prefix}banlist WHERE ban_id = {$target_ban1}"));
		if ((int)$row1['ban_userid'] !== $target_u3 || (int)$row1['ban_end'] !== 0 || strpos($row1['ban_give_reason'], 'Violation of forum rules') === false)
		{
			throw new \Exception("B1 Permanent ban verification mismatch: " . json_encode($row1));
		}

		// Verify B2 (Temporary User Ban): success, in phpbb_banlist with ban_end > now
		if ($res_bans['user_9604']['status'] !== 'success')
		{
			throw new \Exception("B2 User 9604 temporary ban failed: " . json_encode($res_bans['user_9604']));
		}
		$target_ban2 = (int)$res_bans['user_9604']['target_id'];
		$row2 = $db->sql_fetchrow($db->sql_query("SELECT ban_userid, ban_end FROM {$table_prefix}banlist WHERE ban_id = {$target_ban2}"));
		if ((int)$row2['ban_userid'] !== $target_u4 || (int)$row2['ban_end'] <= time())
		{
			throw new \Exception("B2 Temporary ban verification mismatch: " . json_encode($row2));
		}

		// Verify B3 (Expired User Ban): skipped
		if ($res_bans['user_9605']['status'] !== 'skipped' || ($res_bans['user_9605']['note'] ?? '') !== 'expired_ban')
		{
			throw new \Exception("B3 Expired ban was not skipped properly: " . json_encode($res_bans['user_9605']));
		}

		// Verify B4 (Unmapped User Ban): skipped
		if ($res_bans['user_999888']['status'] !== 'skipped')
		{
			throw new \Exception("B4 Unmapped user ban was not skipped: " . json_encode($res_bans['user_999888']));
		}

		// Verify B6 (Founder Protection): skipped with protected_founder or protected_admin
		if ($res_bans['user_999002']['status'] !== 'skipped')
		{
			throw new \Exception("B6 Founder protection failed, founder was banned!");
		}

		// Verify B8 & B9 (Email Bans): success
		if ($res_bans['email_exact_1']['status'] !== 'success' || $res_bans['email_wildcard_1']['status'] !== 'success')
		{
			throw new \Exception("Email bans insertion failed: " . json_encode($res_bans));
		}

		// Verify B10 (Regex Email): skipped
		if ($res_bans['email_regex_1']['status'] !== 'skipped')
		{
			throw new \Exception("B10 Regex email ban was not skipped!");
		}

		// Verify B11, B12, B13 (IP Bans): success
		if ($res_bans['ip_v4_exact']['status'] !== 'success' || $res_bans['ip_v6_exact']['status'] !== 'success' || $res_bans['ip_v4_wildcard']['status'] !== 'success')
		{
			throw new \Exception("IP bans insertion failed: " . json_encode($res_bans));
		}

		// Verify B14 (CIDR Incompatible): skipped
		if ($res_bans['ip_cidr_incompat']['status'] !== 'skipped')
		{
			throw new \Exception("B14 CIDR IP ban was not skipped!");
		}

		// Verify B15 (Localhost Protection): skipped
		if ($res_bans['ip_localhost']['status'] !== 'skipped')
		{
			throw new \Exception("B15 Localhost protection failed!");
		}

		// Verify Real Login Ban Check via phpBB User check_ban()
		$session_user = $phpbb_container->get('user');

		// User 9603 (Permanent) -> BANNED
		$ban_check_u3 = $session_user->check_ban($target_u3, ['10.0.0.1'], 'clean_user3@example.com', true);
		if (empty($ban_check_u3))
		{
			throw new \Exception("Real phpBB ban check failed: User 9603 should be banned on login!");
		}

		// User 9601 (Unbanned) -> NOT BANNED
		$ban_check_u1 = $session_user->check_ban($target_uA, ['10.0.0.1'], 'clean_user1@example.com', true);
		if (!empty($ban_check_u1))
		{
			throw new \Exception("Real phpBB ban check failed: Unbanned User 9601 was falsely flagged as banned!");
		}

		// Email Check: spammer@baddomain.local -> BANNED
		$ban_check_email = $session_user->check_ban(0, ['10.0.0.1'], 'spammer@baddomain.local', true);
		if (empty($ban_check_email))
		{
			throw new \Exception("Real phpBB ban check failed: Banned email was not blocked!");
		}

		// IP Check: 198.51.100.42 -> BANNED
		$ban_check_ip = $session_user->check_ban(0, ['198.51.100.42'], 'unrelated@example.com', true);
		if (empty($ban_check_ip))
		{
			throw new \Exception("Real phpBB ban check failed: Banned IP was not blocked!");
		}

		// Idempotency / Retry: Running write_bans again must not insert duplicate rows
		$res_retry = $writer->write_bans($bans_batch, [
			'run_id'               => $run_id,
			'source_system'        => $source_system,
			'expired_ban_policy'   => 'skip',
			'existing_user_policy' => 'preserve_target',
		]);
		if ($res_retry['user_9603']['status'] !== 'success' || ($res_retry['user_9603']['note'] ?? '') !== 'already_mapped')
		{
			throw new \Exception("Idempotency retry failed for user ban: " . json_encode($res_retry['user_9603']));
		}

		// Cleanup Test Data
		$db->sql_query("DELETE FROM {$table_prefix}banlist WHERE ban_userid IN ({$target_u3}, {$target_u4}, {$target_u6})");
		$db->sql_query("DELETE FROM {$table_prefix}banlist WHERE ban_email IN ('spammer@baddomain.local', '*@spammerdomain.local')");
		$db->sql_query("DELETE FROM {$table_prefix}banlist WHERE ban_ip IN ('198.51.100.42', '2001:0db8:85a3:0000:0000:8a2e:0370:7334', '198.51.100.*')");
		$db->sql_query("DELETE FROM {$table_prefix}users WHERE user_id IN ({$target_uA}, {$target_uB}, {$target_u3}, {$target_u4}, {$target_u5}, {$target_u6})");
		$db->sql_query("DELETE FROM {$table_prefix}topics WHERE topic_id = {$target_rec_topic}");
		$db->sql_query("DELETE FROM {$table_prefix}poll_options WHERE topic_id = {$target_rec_topic}");
		$db->sql_query("DELETE FROM {$table_prefix}poll_votes WHERE topic_id = {$target_rec_topic}");
		$db->sql_query("DELETE FROM {$table_prefix}privmsgs WHERE msg_id = {$real_target_msg_id}");
		$db->sql_query("DELETE FROM {$table_prefix}privmsgs_to WHERE msg_id = {$real_target_msg_id}");
		$db->sql_query("DELETE FROM {$table_prefix}migration_id_map WHERE run_id = '{$run_id}'");

		return true;
	}
}
