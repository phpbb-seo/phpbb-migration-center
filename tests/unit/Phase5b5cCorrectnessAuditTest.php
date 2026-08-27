<?php
/**
 * Phase 5B & 5C Correctness, Privacy & Relationship Reconciliation Audit Test
 */

namespace phpbbseo\migrationcenter\tests\unit;

use phpbbseo\migrationcenter\core\dto\user_dto;
use phpbbseo\migrationcenter\core\dto\avatar_dto;
use phpbbseo\migrationcenter\core\dto\conversation_dto;
use phpbbseo\migrationcenter\core\dto\conversation_message_dto;
use phpbbseo\migrationcenter\core\dto\conversation_recipient_dto;
use phpbbseo\migrationcenter\core\dto\migration_config_dto;
use phpbbseo\migrationcenter\source\xenforo\step\conversations_step;
use phpbbseo\migrationcenter\source\xenforo\step\conversation_messages_step;

class Phase5b5cCorrectnessAuditTest
{
	public function run()
	{
		global $phpbb_container;

		list($db, $table_prefix) = get_test_db();
		$id_mapper = $phpbb_container->get('phpbbseo.migrationcenter.id_mapper');
		$writer = $phpbb_container->get('phpbbseo.migrationcenter.target_writer');

		$run_id = 'audit_5bc_run_' . time();

		// =========================================================================
		// 1. AVATAR ZERO-LIMIT & TARGET SEMANTICS TEST
		// =========================================================================
		$test_user = new user_dto();
		$test_user->source_id = 9301;
		$test_user->username = 'AvatarAuditUser';
		$test_user->email = 'avatar_audit@invalid.local';
		$u_res = $writer->write_users([$test_user], ['run_id' => $run_id, 'source_system' => 'xenforo']);
		$target_uid = (int)$u_res[9301]['target_id'];

		// Create a synthetic 200x150 source avatar
		$tmp_av = tempnam(sys_get_temp_dir(), 'av_audit_') . '.png';
		$img = imagecreatetruecolor(200, 150);
		imagepng($img, $tmp_av);
		imagedestroy($img);

		$av_dto = new avatar_dto();
		$av_dto->user_source_id = 9301;
		$av_dto->avatar_type = 'upload';
		$av_dto->source_physical_path = $tmp_av;
		$av_dto->source_width = 200;
		$av_dto->source_height = 150;

		// Test 1.1: Zero max limits (unlimited dimensions)
		$db->sql_query("UPDATE {$table_prefix}config SET config_value = '0' WHERE config_name IN ('avatar_max_width', 'avatar_max_height')");
		$res_av = $writer->write_avatars([$av_dto], ['run_id' => $run_id, 'source_system' => 'xenforo', 'avatar_policy' => 'resize_to_fit', 'existing_avatar_policy' => 'replace_target']);
		if ($res_av[9301]['status'] !== 'success')
		{
			throw new \Exception("Avatar zero-limit upload failed: " . json_encode($res_av[9301]));
		}

		$u_row = $db->sql_fetchrow($db->sql_query("SELECT user_avatar_width, user_avatar_height FROM {$table_prefix}users WHERE user_id = {$target_uid}"));
		if ((int)$u_row['user_avatar_width'] !== 200 || (int)$u_row['user_avatar_height'] !== 150)
		{
			throw new \Exception("Avatar zero-limit should keep original 200x150 dimensions, got {$u_row['user_avatar_width']}x{$u_row['user_avatar_height']}");
		}

		// Test 1.2: Anonymous user protection
		$anon_dto = new avatar_dto();
		$anon_dto->user_source_id = 999999;
		$anon_dto->avatar_type = 'upload';
		$anon_dto->source_physical_path = $tmp_av;
		$id_mapper->set($run_id, 'xenforo', 'user', 999999, 1, 'mapped');
		$res_anon = $writer->write_avatars([$anon_dto], ['run_id' => $run_id, 'source_system' => 'xenforo', 'existing_avatar_policy' => 'replace_target']);
		if ($res_anon[999999]['status'] !== 'skipped')
		{
			throw new \Exception("Anonymous user must never be modified by avatar migration!");
		}

		@unlink($tmp_av);

		// Restore default config
		$db->sql_query("UPDATE {$table_prefix}config SET config_value = '90' WHERE config_name IN ('avatar_max_width', 'avatar_max_height')");

		// =========================================================================
		// 2. PRIVMSGS_TO MATHEMATICAL RECONCILIATION & EXACT ROOT_LEVEL TEST
		// =========================================================================
		// Setup 3 Test Users: User A (9311), User B (9312), User C (9313)
		$uA = new user_dto();
		$uA->source_id = 9311;
		$uA->username = 'UserA_Audit';
		$uA->email = 'userA@invalid.local';

		$uB = new user_dto();
		$uB->source_id = 9312;
		$uB->username = 'UserB_Audit';
		$uB->email = 'userB@invalid.local';

		$uC = new user_dto();
		$uC->source_id = 9313;
		$uC->username = 'UserC_Audit';
		$uC->email = 'userC@invalid.local';

		$uD = new user_dto();
		$uD->source_id = 9314;
		$uD->username = 'UserD_Unrelated';
		$uD->email = 'userD@invalid.local';

		$u_batch = $writer->write_users([$uA, $uB, $uC, $uD], ['run_id' => $run_id, 'source_system' => 'xenforo']);
		$target_uA = (int)$u_batch[9311]['target_id'];
		$target_uB = (int)$u_batch[9312]['target_id'];
		$target_uC = (int)$u_batch[9313]['target_id'];
		$target_uD = (int)$u_batch[9314]['target_id'];

		// Setup Conversation 1 (ID 8201):
		// - User A (Starter)
		// - User B (Present from start at T=1000)
		// - User C (Joins at T=2000)
		$conv1 = new conversation_dto();
		$conv1->source_id = 8201;
		$conv1->title = "Audit Conversation 1";
		$conv1->user_source_id = 9311;
		$conv1->start_date = 1000;
		$conv1->first_message_id = 7101;
		$conv1->last_message_id = 7103;

		$rA = new conversation_recipient_dto();
		$rA->user_source_id = 9311;
		$rA->recipient_state = 'active';
		$rA->last_read_date = 3000;
		$rA->is_starred = true;

		$rB = new conversation_recipient_dto();
		$rB->user_source_id = 9312;
		$rB->recipient_state = 'deleted'; // User B leaves after msg 2
		$rB->last_read_date = 2000;
		$rB->is_starred = false;

		$rC = new conversation_recipient_dto();
		$rC->user_source_id = 9313;
		$rC->recipient_state = 'active';
		$rC->last_read_date = 3000;
		$rC->is_starred = false;

		$conv1->recipients = [
			9311 => $rA,
			9312 => $rB,
			9313 => $rC,
		];

		// Setup Conversation 2 (ID 8202): Independent 3-message conversation between User B & User C
		$conv2 = new conversation_dto();
		$conv2->source_id = 8202;
		$conv2->title = "Audit Conversation 2";
		$conv2->user_source_id = 9312;
		$conv2->start_date = 1500;
		$conv2->first_message_id = 7201;
		$conv2->last_message_id = 7203;

		$conv2->recipients = [
			9312 => $rB,
			9313 => $rC,
		];

		$writer->write_conversations([$conv1, $conv2], ['run_id' => $run_id, 'source_system' => 'xenforo']);

		// Messages for Conv 1:
		// Msg 1 (T=1000): User A -> User B (User C has join_date=2000, so C is excluded from Msg 1)
		// Expected: 1 Sentbox (A) + 1 Inbox (B) = 2 privmsgs_to rows
		$m1 = new conversation_message_dto();
		$m1->source_id = 7101;
		$m1->conversation_source_id = 8201;
		$m1->message_date = 1000;
		$m1->user_source_id = 9311;
		$m1->username = 'UserA_Audit';
		$m1->message_text = "Message 1 before User C joined [[MC_PM_ATTACH:8001]]";
		$m1->attach_count = 1;

		// Msg 2 (T=2000): User B -> User A & User C
		// Expected: 1 Sentbox (B) + 2 Inbox (A, C) = 3 privmsgs_to rows
		$m2 = new conversation_message_dto();
		$m2->source_id = 7102;
		$m2->conversation_source_id = 8201;
		$m2->message_date = 2000;
		$m2->user_source_id = 9312;
		$m2->username = 'UserB_Audit';
		$m2->message_text = "Message 2 with User C present";
		$m2->attach_count = 0;

		// Msg 3 (T=3000): User C -> User A & User B (User B left/deleted, so B's inbox copy is pm_deleted = 1)
		// Expected: 1 Sentbox (C) + 2 Inbox (A, B) = 3 privmsgs_to rows
		$m3 = new conversation_message_dto();
		$m3->source_id = 7103;
		$m3->conversation_source_id = 8201;
		$m3->message_date = 3000;
		$m3->user_source_id = 9313;
		$m3->username = 'UserC_Audit';
		$m3->message_text = "Message 3 after User B left";
		$m3->attach_count = 0;

		// Messages for Conv 2:
		// Msg 4 (T=1500): User B -> User C
		// Msg 5 (T=1600): User C -> User B
		// Msg 6 (T=1700): User B -> User C
		// Each has 1 Sentbox + 1 Inbox = 2 rows -> 6 rows total
		$m4 = new conversation_message_dto();
		$m4->source_id = 7201;
		$m4->conversation_source_id = 8202;
		$m4->message_date = 1500;
		$m4->user_source_id = 9312;
		$m4->username = 'UserB_Audit';
		$m4->message_text = "Conv 2 Msg 1";

		$m5 = new conversation_message_dto();
		$m5->source_id = 7202;
		$m5->conversation_source_id = 8202;
		$m5->message_date = 1600;
		$m5->user_source_id = 9313;
		$m5->username = 'UserC_Audit';
		$m5->message_text = "Conv 2 Msg 2";

		$m6 = new conversation_message_dto();
		$m6->source_id = 7203;
		$m6->conversation_source_id = 8202;
		$m6->message_date = 1700;
		$m6->user_source_id = 9312;
		$m6->username = 'UserB_Audit';
		$m6->message_text = "Conv 2 Msg 3";

		// Record recipient join_date in conversation metadata for User C
		$meta1 = $id_mapper->get_metadata('xenforo', 'conversation', 8201);
		$meta1['recipients'][9313]['join_date'] = 2000;
		$id_mapper->set($run_id, 'xenforo', 'conversation', 8201, null, 'planned', '', $meta1);

		// Write all 6 messages
		$res_msgs = $writer->write_privmsgs([$m1, $m2, $m3, $m4, $m5, $m6], ['run_id' => $run_id, 'source_system' => 'xenforo']);
		foreach ([7101, 7102, 7103, 7201, 7202, 7203] as $mid)
		{
			if ($res_msgs[$mid]['status'] !== 'success')
			{
				throw new \Exception("write_privmsgs failed for {$mid}: " . json_encode($res_msgs[$mid]));
			}
		}

		$tgt_m1 = (int)$res_msgs[7101]['target_id'];
		$tgt_m2 = (int)$res_msgs[7102]['target_id'];
		$tgt_m3 = (int)$res_msgs[7103]['target_id'];
		$tgt_m4 = (int)$res_msgs[7201]['target_id'];
		$tgt_m5 = (int)$res_msgs[7202]['target_id'];
		$tgt_m6 = (int)$res_msgs[7203]['target_id'];

		// Test 2.1: Mathematical Relationship Verification Table
		// Msg 1: 1 sentbox (A) + 1 inbox (B) = 2
		// Msg 2: 1 sentbox (B) + 2 inbox (A, C) = 3
		// Msg 3: 1 sentbox (C) + 2 inbox (A, B) = 3
		// Msg 4: 1 sentbox (B) + 1 inbox (C) = 2
		// Msg 5: 1 sentbox (C) + 1 inbox (B) = 2
		// Msg 6: 1 sentbox (B) + 1 inbox (C) = 2
		// Total expected rows = 2 + 3 + 3 + 2 + 2 + 2 = 14 rows!
		$all_tgt_ids = implode(',', [$tgt_m1, $tgt_m2, $tgt_m3, $tgt_m4, $tgt_m5, $tgt_m6]);
		$sql = "SELECT COUNT(*) as total_rows FROM {$table_prefix}privmsgs_to WHERE msg_id IN ({$all_tgt_ids})";
		$total_to_rows = (int)$db->sql_fetchfield('total_rows', 0, $db->sql_query($sql));
		if ($total_to_rows !== 14)
		{
			throw new \Exception("Expected exactly 14 privmsgs_to rows mathematically, got {$total_to_rows}");
		}

		// Test 2.2: Ensure NO duplicate (msg_id, user_id) relationships exist
		$sql = "SELECT msg_id, user_id, COUNT(*) as cnt 
				FROM {$table_prefix}privmsgs_to 
				WHERE msg_id IN ({$all_tgt_ids}) 
				GROUP BY msg_id, user_id 
				HAVING cnt > 1";
		$dup_rows = $db->sql_fetchrow($db->sql_query($sql));
		if ($dup_rows)
		{
			throw new \Exception("CRITICAL INTEGRITY VIOLATION: Duplicate (msg_id, user_id) row found!");
		}

		// Test 2.3: Root-Level Semantics
		// Conv 1: Msg 1 is root (0), Msg 2 and 3 point to Msg 1
		$pms = [];
		$res = $db->sql_query("SELECT msg_id, root_level, to_address, message_attachment FROM {$table_prefix}privmsgs WHERE msg_id IN ({$all_tgt_ids})");
		while ($r = $db->sql_fetchrow($res))
		{
			$pms[(int)$r['msg_id']] = $r;
		}
		$db->sql_freeresult($res);

		if ((int)$pms[$tgt_m1]['root_level'] !== 0)
		{
			throw new \Exception("Conv 1 root message expected root_level = 0, got " . $pms[$tgt_m1]['root_level']);
		}
		if ((int)$pms[$tgt_m2]['root_level'] !== $tgt_m1 || (int)$pms[$tgt_m3]['root_level'] !== $tgt_m1)
		{
			throw new \Exception("Conv 1 replies expected root_level = {$tgt_m1}");
		}

		// Conv 2: Msg 4 is root (0), Msg 5 and 6 point to Msg 4
		if ((int)$pms[$tgt_m4]['root_level'] !== 0)
		{
			throw new \Exception("Conv 2 root message expected root_level = 0, got " . $pms[$tgt_m4]['root_level']);
		}
		if ((int)$pms[$tgt_m5]['root_level'] !== $tgt_m4 || (int)$pms[$tgt_m6]['root_level'] !== $tgt_m4)
		{
			throw new \Exception("Conv 2 replies expected root_level = {$tgt_m4}");
		}

		// Test 2.4: Participant Privacy Boundary - User C must NOT have access to Msg 1
		$sql = "SELECT COUNT(*) as cnt FROM {$table_prefix}privmsgs_to WHERE msg_id = {$tgt_m1} AND user_id = {$target_uC}";
		$c_has_m1 = (int)$db->sql_fetchfield('cnt', 0, $db->sql_query($sql));
		if ($c_has_m1 !== 0)
		{
			throw new \Exception("PRIVACY VIOLATION: User C has access to Message 1 sent before joining!");
		}

		// Test 2.5: to_address on Msg 1 does not disclose User C
		if (strpos($pms[$tgt_m1]['to_address'], "u_{$target_uC}") !== false)
		{
			throw new \Exception("PRIVACY VIOLATION: to_address on Msg 1 falsely discloses User C!");
		}

		// Test 2.6: Sender Deletion & Participant Leave State
		// User B left conversation -> User B's copy for Msg 3 has pm_deleted = 1
		$sql = "SELECT pm_deleted FROM {$table_prefix}privmsgs_to WHERE msg_id = {$tgt_m3} AND user_id = {$target_uB} AND folder_id = 0";
		$b_m3_del = (int)$db->sql_fetchfield('pm_deleted', 0, $db->sql_query($sql));
		if ($b_m3_del !== 1)
		{
			throw new \Exception("User B copy for Msg 3 should have pm_deleted = 1");
		}

		// User A's copy for Msg 3 is active (pm_deleted = 0)
		$sql = "SELECT pm_deleted FROM {$table_prefix}privmsgs_to WHERE msg_id = {$tgt_m3} AND user_id = {$target_uA} AND folder_id = 0";
		$a_m3_del = (int)$db->sql_fetchfield('pm_deleted', 0, $db->sql_query($sql));
		if ($a_m3_del !== 0)
		{
			throw new \Exception("User A copy for Msg 3 should remain active (pm_deleted = 0)");
		}

		// Test 2.7: Unrelated User D has zero access to any PM
		$sql = "SELECT COUNT(*) as cnt FROM {$table_prefix}privmsgs_to WHERE msg_id IN ({$all_tgt_ids}) AND user_id = {$target_uD}";
		$d_rows = (int)$db->sql_fetchfield('cnt', 0, $db->sql_query($sql));
		if ($d_rows !== 0)
		{
			throw new \Exception("PRIVACY VIOLATION: Unrelated User D received PM rows!");
		}

		// Test 2.8: message_attachment remains 0 for all messages in Phase 5C
		foreach ([$tgt_m1, $tgt_m2, $tgt_m3, $tgt_m4, $tgt_m5, $tgt_m6] as $mid)
		{
			if ((int)$pms[$mid]['message_attachment'] !== 0)
			{
				throw new \Exception("message_attachment must remain 0 during Phase 5C for message {$mid}");
			}
		}

		// Cleanup
		$db->sql_query("DELETE FROM {$table_prefix}privmsgs_to WHERE msg_id IN ({$all_tgt_ids})");
		$db->sql_query("DELETE FROM {$table_prefix}privmsgs WHERE msg_id IN ({$all_tgt_ids})");
		$db->sql_query("DELETE FROM {$table_prefix}users WHERE user_id IN ({$target_uid}, {$target_uA}, {$target_uB}, {$target_uC}, {$target_uD})");
		$db->sql_query("DELETE FROM {$table_prefix}migration_id_map WHERE run_id = '{$run_id}'");

		return true;
	}
}
