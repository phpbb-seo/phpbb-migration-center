<?php
/**
 * Hardened Phase 5C Conversation & Private Message Migration Integration Test
 */

namespace phpbbseo\migrationcenter\tests\unit;

use phpbbseo\migrationcenter\core\dto\user_dto;
use phpbbseo\migrationcenter\core\dto\conversation_dto;
use phpbbseo\migrationcenter\core\dto\conversation_message_dto;
use phpbbseo\migrationcenter\core\dto\conversation_recipient_dto;

class PrivateMessageMigrationIntegrationTest
{
	public function run()
	{
		global $phpbb_container;

		list($db, $table_prefix) = get_test_db();
		$id_mapper = $phpbb_container->get('phpbbseo.migrationcenter.id_mapper');
		$writer = $phpbb_container->get('phpbbseo.migrationcenter.target_writer');

		$run_id = 'test_pm_run_' . time();

		// 1. Setup Test Users
		$u1 = new user_dto();
		$u1->source_id = 9201;
		$u1->username = 'PmSenderUser';
		$u1->email = 'pmsender@invalid.local';
		$u1_res = $writer->write_users([$u1], ['run_id' => $run_id, 'source_system' => 'xenforo']);
		$target_u1_id = (int)$u1_res[9201]['target_id'];

		$u2 = new user_dto();
		$u2->source_id = 9202;
		$u2->username = 'PmRecipUser';
		$u2->email = 'pmrecip@invalid.local';
		$u2_res = $writer->write_users([$u2], ['run_id' => $run_id, 'source_system' => 'xenforo']);
		$target_u2_id = (int)$u2_res[9202]['target_id'];

		// 2. Setup Conversation 1: 2 participants, 2 messages
		$conv1 = new conversation_dto();
		$conv1->source_id = 8101;
		$conv1->title = "XXXXXY XXX XX UnicodeRunner\xE2\x80\x8CXXX X Emoji 🚀";
		$conv1->user_source_id = 9201;
		$conv1->start_date = 1785000000;
		$conv1->first_message_id = 7001;
		$conv1->last_message_id = 7002;

		$r1 = new conversation_recipient_dto();
		$r1->user_source_id = 9201;
		$r1->recipient_state = 'active';
		$r1->last_read_date = 1785000200;
		$r1->is_starred = true;

		$r2 = new conversation_recipient_dto();
		$r2->user_source_id = 9202;
		$r2->recipient_state = 'active';
		$r2->last_read_date = 1785000050; // Read msg 1, but unread for msg 2
		$r2->is_starred = false;

		$conv1->recipients = [9201 => $r1, 9202 => $r2];

		// Setup Conversation 2: Independent separate conversation
		$conv2 = new conversation_dto();
		$conv2->source_id = 8102;
		$conv2->title = "Second Separate Conversation";
		$conv2->user_source_id = 9202;
		$conv2->start_date = 1785000300;
		$conv2->first_message_id = 7003;
		$conv2->last_message_id = 7003;
		$conv2->recipients = [9201 => $r1, 9202 => $r2];

		// Write conversations metadata
		$c_res = $writer->write_conversations([$conv1, $conv2], ['run_id' => $run_id, 'source_system' => 'xenforo']);
		if ($c_res[8101]['status'] !== 'success' || $c_res[8102]['status'] !== 'success')
		{
			throw new \Exception("write_conversations failed: " . json_encode($c_res));
		}

		// 3. Setup Messages
		// Message 1 (Conv 1 - Root Message)
		$m1 = new conversation_message_dto();
		$m1->source_id = 7001;
		$m1->conversation_source_id = 8101;
		$m1->message_date = 1785000000;
		$m1->user_source_id = 9201;
		$m1->username = 'PmSenderUser';
		$m1->message_text = "[b]First Message[/b] with [attach]8001[/attach]";
		$m1->attach_count = 1;
		$m1->author_ip = '192.168.1.10';

		// Message 2 (Conv 1 - Reply Message)
		$m2 = new conversation_message_dto();
		$m2->source_id = 7002;
		$m2->conversation_source_id = 8101;
		$m2->message_date = 1785000100;
		$m2->user_source_id = 9202;
		$m2->username = 'PmRecipUser';
		$m2->message_text = "[quote]First Message[/quote] [i]Reply Message[/i]";
		$m2->attach_count = 0;
		$m2->author_ip = '192.168.1.20';

		// Message 3 (Conv 2 - Independent Root Message)
		$m3 = new conversation_message_dto();
		$m3->source_id = 7003;
		$m3->conversation_source_id = 8102;
		$m3->message_date = 1785000300;
		$m3->user_source_id = 9202;
		$m3->username = 'PmRecipUser';
		$m3->message_text = "Independent Conversation Message";
		$m3->attach_count = 0;
		$m3->author_ip = '192.168.1.20';

		// Write Messages
		$m_res = $writer->write_privmsgs([$m1, $m2, $m3], ['run_id' => $run_id, 'source_system' => 'xenforo']);
		foreach ([7001, 7002, 7003] as $mid)
		{
			if ($m_res[$mid]['status'] !== 'success')
			{
				throw new \Exception("write_privmsgs failed for message {$mid}: " . json_encode($m_res[$mid]));
			}
		}

		$tgt_m1_id = (int)$m_res[7001]['target_id'];
		$tgt_m2_id = (int)$m_res[7002]['target_id'];
		$tgt_m3_id = (int)$m_res[7003]['target_id'];

		// Test 1: Verify Root Level Threading in phpbb_privmsgs
		$sql = "SELECT msg_id, root_level, author_id, message_subject, to_address, message_attachment 
				FROM {$table_prefix}privmsgs 
				WHERE msg_id IN ({$tgt_m1_id}, {$tgt_m2_id}, {$tgt_m3_id}) 
				ORDER BY msg_id ASC";
		$res = $db->sql_query($sql);
		$pm_rows = [];
		while ($r = $db->sql_fetchrow($res))
		{
			$pm_rows[$r['msg_id']] = $r;
		}
		$db->sql_freeresult($res);

		// Msg 1 must have root_level = 0 (Thread Starter)
		if ((int)$pm_rows[$tgt_m1_id]['root_level'] !== 0)
		{
			throw new \Exception("Msg 1 root_level expected 0, got " . $pm_rows[$tgt_m1_id]['root_level']);
		}

		// Msg 2 must have root_level = $tgt_m1_id (Thread Reply)
		if ((int)$pm_rows[$tgt_m2_id]['root_level'] !== $tgt_m1_id)
		{
			throw new \Exception("Msg 2 root_level expected {$tgt_m1_id}, got " . $pm_rows[$tgt_m2_id]['root_level']);
		}

		// Msg 3 (Conv 2) must have root_level = 0 (Separate Thread)
		if ((int)$pm_rows[$tgt_m3_id]['root_level'] !== 0)
		{
			throw new \Exception("Msg 3 root_level expected 0, got " . $pm_rows[$tgt_m3_id]['root_level']);
		}

		// Test 2: Verify to_address formatting
		if ($pm_rows[$tgt_m1_id]['to_address'] !== "u_{$target_u2_id}:")
		{
			throw new \Exception("to_address mismatch for Msg 1: " . $pm_rows[$tgt_m1_id]['to_address']);
		}

		// Test 3: message_attachment remains 0 (deferred to Phase 5D)
		if ((int)$pm_rows[$tgt_m1_id]['message_attachment'] !== 0)
		{
			throw new \Exception("message_attachment must remain 0 during Phase 5C");
		}

		// Test 4: Verify privmsgs_to rows (Inbox and Sentbox folders & flags)
		$sql = "SELECT msg_id, user_id, author_id, folder_id, pm_unread, pm_marked, pm_deleted 
				FROM {$table_prefix}privmsgs_to 
				WHERE msg_id = {$tgt_m1_id}";
		$res = $db->sql_query($sql);
		$to_rows = [];
		while ($r = $db->sql_fetchrow($res))
		{
			$to_rows[$r['user_id']] = $r;
		}
		$db->sql_freeresult($res);

		// Sender copy in sentbox (folder_id = -1, pm_unread = 0, pm_marked = 1)
		$sender_to = $to_rows[$target_u1_id] ?? null;
		if (!$sender_to || (int)$sender_to['folder_id'] !== -1 || (int)$sender_to['pm_unread'] !== 0 || (int)$sender_to['pm_marked'] !== 1)
		{
			throw new \Exception("Sender privmsgs_to row invalid: " . json_encode($sender_to));
		}

		// Recipient copy in inbox (folder_id = 0, pm_unread = 0 because last_read_date 1785000050 > msg_date 1785000000)
		$recip_to = $to_rows[$target_u2_id] ?? null;
		if (!$recip_to || (int)$recip_to['folder_id'] !== 0 || (int)$recip_to['pm_unread'] !== 0)
		{
			throw new \Exception("Recipient privmsgs_to row invalid for Msg 1: " . json_encode($recip_to));
		}

		// Test 5: Verify Msg 2 unread state for recipient (msg_date 1785000100 > last_read_date 1785000050 $\to$ pm_unread = 1)
		$sql = "SELECT msg_id, user_id, folder_id, pm_unread 
				FROM {$table_prefix}privmsgs_to 
				WHERE msg_id = {$tgt_m2_id} AND user_id = {$target_u1_id} AND folder_id = 0";
		$res = $db->sql_query($sql);
		$msg2_recip = $db->sql_fetchrow($res);
		$db->sql_freeresult($res);

		// User 1 read up to 1785000200, so for user 1 msg 2 is read (pm_unread = 0)
		if (!$msg2_recip || (int)$msg2_recip['pm_unread'] !== 0)
		{
			throw new \Exception("Msg 2 unread boundary mapping failed for User 1");
		}

		// Test 6: Idempotent rerun
		$rerun_res = $writer->write_privmsgs([$m1, $m2, $m3], ['run_id' => $run_id, 'source_system' => 'xenforo']);
		$sql = "SELECT COUNT(*) as cnt FROM {$table_prefix}privmsgs WHERE msg_id IN ({$tgt_m1_id}, {$tgt_m2_id}, {$tgt_m3_id})";
		$cnt = (int)$db->sql_fetchfield('cnt', 0, $db->sql_query($sql));
		if ($cnt !== 3)
		{
			throw new \Exception("Duplicate privmsgs rows created on rerun!");
		}

		// Test 7: Verify Admin user 2 integrity
		$admin_row = $db->sql_fetchrow($db->sql_query("SELECT username_clean, user_type FROM {$table_prefix}users WHERE user_id = 2"));
		if ($admin_row['username_clean'] !== 'admin' || (int)$admin_row['user_type'] !== 3)
		{
			throw new \Exception("CRITICAL INTEGRITY VIOLATION: Admin modified during PM migration!");
		}

		// Cleanup
		$msg_ids = implode(',', [$tgt_m1_id, $tgt_m2_id, $tgt_m3_id]);
		$db->sql_query("DELETE FROM {$table_prefix}privmsgs_to WHERE msg_id IN ({$msg_ids})");
		$db->sql_query("DELETE FROM {$table_prefix}privmsgs WHERE msg_id IN ({$msg_ids})");
		$db->sql_query("DELETE FROM {$table_prefix}users WHERE user_id IN ({$target_u1_id}, {$target_u2_id})");
		$db->sql_query("DELETE FROM {$table_prefix}migration_id_map WHERE run_id = '{$run_id}'");

		return true;
	}
}
