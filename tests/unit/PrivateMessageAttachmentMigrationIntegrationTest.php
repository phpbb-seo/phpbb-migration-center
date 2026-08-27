<?php
/**
 * Phase 5D Conversation Attachments & PM Privacy Integration Test
 */

namespace phpbbseo\migrationcenter\tests\unit;

use phpbbseo\migrationcenter\core\dto\user_dto;
use phpbbseo\migrationcenter\core\dto\conversation_dto;
use phpbbseo\migrationcenter\core\dto\conversation_recipient_dto;
use phpbbseo\migrationcenter\core\dto\conversation_message_dto;
use phpbbseo\migrationcenter\core\dto\attachment_dto;
use phpbbseo\migrationcenter\core\dto\migration_config_dto;

class PrivateMessageAttachmentMigrationIntegrationTest
{
	public function run()
	{
		global $phpbb_container;

		list($db, $table_prefix) = get_test_db();
		$id_mapper = $phpbb_container->get('phpbbseo.migrationcenter.id_mapper');
		$writer = $phpbb_container->get('phpbbseo.migrationcenter.target_writer');
		$listener = $phpbb_container->get('phpbbseo.migrationcenter.listener');

		$run_id = 'pm_att_run_' . time();
		$source_system = 'xenforo';

		// Clean up any existing mapping records from prior partial runs
		$db->sql_query("DELETE FROM {$table_prefix}migration_id_map WHERE source_system = '{$source_system}' AND source_id IN ('8401', '7401', '7402', '6001', '6002', '6003', '6004', '9401', '9402', '9403', '9404')");

		// Setup Test Users: User A (9401), User B (9402), User C (9403 - late joiner), User D (9404 - unrelated)
		$uA = new user_dto();
		$uA->source_id = 9401;
		$uA->username = 'PMAttUserA';
		$uA->email = 'pm_att_a@invalid.local';

		$uB = new user_dto();
		$uB->source_id = 9402;
		$uB->username = 'PMAttUserB';
		$uB->email = 'pm_att_b@invalid.local';

		$uC = new user_dto();
		$uC->source_id = 9403;
		$uC->username = 'PMAttUserC_Late';
		$uC->email = 'pm_att_c@invalid.local';

		$uD = new user_dto();
		$uD->source_id = 9404;
		$uD->username = 'PMAttUserD_Unrelated';
		$uD->email = 'pm_att_d@invalid.local';

		$u_res = $writer->write_users([$uA, $uB, $uC, $uD], ['run_id' => $run_id, 'source_system' => $source_system]);
		$target_uA = (int)$u_res[9401]['target_id'];
		$target_uB = (int)$u_res[9402]['target_id'];
		$target_uC = (int)$u_res[9403]['target_id'];
		$target_uD = (int)$u_res[9404]['target_id'];

		// Setup Conversation 1 (ID 8401)
		$conv = new conversation_dto();
		$conv->source_id = 8401;
		$conv->title = 'PM Attachment Integration Conv';
		$conv->user_source_id = 9401;
		$conv->start_date = 1000;
		$conv->first_message_id = 7401;
		$conv->last_message_id = 7402;

		$rA = new conversation_recipient_dto();
		$rA->user_source_id = 9401;
		$rA->recipient_state = 'active';

		$rB = new conversation_recipient_dto();
		$rB->user_source_id = 9402;
		$rB->recipient_state = 'active';

		$rC = new conversation_recipient_dto();
		$rC->user_source_id = 9403;
		$rC->recipient_state = 'active';
		$rC->raw_data = ['join_date' => 2000];

		$conv->recipients = [
			9401 => $rA,
			9402 => $rB,
			9403 => $rC,
		];

		$writer->write_conversations([$conv], ['run_id' => $run_id, 'source_system' => $source_system]);

		// Setup Message 1 (7401) at T=1000 with 3 attachments (2 inline, 1 non-inline)
		// Setup Message 2 (7402) at T=2000
		$msg1 = new conversation_message_dto();
		$msg1->source_id = 7401;
		$msg1->conversation_source_id = 8401;
		$msg1->message_date = 1000;
		$msg1->user_source_id = 9401;
		$msg1->username = 'PMAttUserA';
		$msg1->message_text = "Hello! Here is image 2: [[MC_PM_ATTACH:6002]] and here is image 1: [[MC_PM_ATTACH:6001]]. Unrelated cross-marker: [[MC_PM_ATTACH:9999]]";
		$msg1->attach_count = 3;

		$msg2 = new conversation_message_dto();
		$msg2->source_id = 7402;
		$msg2->conversation_source_id = 8401;
		$msg2->message_date = 2000;
		$msg2->user_source_id = 9402;
		$msg2->username = 'PMAttUserB';
		$msg2->message_text = "Message 2 text with no attachments";
		$msg2->attach_count = 0;

		$res_msgs = $writer->write_privmsgs([$msg1, $msg2], ['run_id' => $run_id, 'source_system' => $source_system]);
		$tgt_msg1 = (int)$res_msgs[7401]['target_id'];
		$tgt_msg2 = (int)$res_msgs[7402]['target_id'];

		// Create physical test files for PM attachments
		$tmp_dir = sys_get_temp_dir() . '/pm_att_test_' . time();
		@mkdir($tmp_dir, 0777, true);

		// File 1: PNG image (attach_id 6001), filetime 1100 (newer)
		$f1_path = $tmp_dir . '/att1.png';
		$im1 = imagecreatetruecolor(100, 100);
		imagepng($im1, $f1_path);
		imagedestroy($im1);

		// File 2: Persian filename JPG image (attach_id 6002), filetime 1050 (middle)
		$f2_path = $tmp_dir . '/sample_image_file.jpg';
		$im2 = imagecreatetruecolor(80, 80);
		imagejpeg($im2, $f2_path, 90);
		imagedestroy($im2);

		// File 3: PDF Document non-inline (attach_id 6003), filetime 1000 (oldest)
		$f3_path = $tmp_dir . '/document.pdf';
		file_put_contents($f3_path, '%PDF-1.4 Test PDF Content');

		// File 4: Disallowed executable (attach_id 6004)
		$f4_path = $tmp_dir . '/script.php';
		file_put_contents($f4_path, '<?php echo "evil"; ?>');

		// Create Attachment DTOs
		// Notice conflicting order:
		// Inserted order: 6003, 6001, 6002
		// Filetimes: 6001 (T=1100), 6002 (T=1050), 6003 (T=1000)
		// Expected phpBB UCP order (filetime DESC, post_msg_id ASC):
		// Index 0: 6001 (T=1100) -> att1.png
		// Index 1: 6002 (T=1050) -> sample_image_file.jpg
		// Index 2: 6003 (T=1000) -> document.pdf
		$att3 = new attachment_dto();
		$att3->source_id = 6003;
		$att3->data_id = 6003;
		$att3->content_type = 'conversation_message';
		$att3->post_source_id = 7401;
		$att3->user_source_id = 9401;
		$att3->real_filename = 'document.pdf';
		$att3->source_physical_path = $f3_path;
		$att3->filesize = filesize($f3_path);
		$att3->filetime = 1000;
		$att3->mimetype = 'application/pdf';

		$att1 = new attachment_dto();
		$att1->source_id = 6001;
		$att1->data_id = 6001;
		$att1->content_type = 'conversation_message';
		$att1->post_source_id = 7401;
		$att1->user_source_id = 9401;
		$att1->real_filename = 'att1.png';
		$att1->source_physical_path = $f1_path;
		$att1->filesize = filesize($f1_path);
		$att1->filetime = 1100;
		$att1->mimetype = 'image/png';

		$att2 = new attachment_dto();
		$att2->source_id = 6002;
		$att2->data_id = 6002;
		$att2->content_type = 'conversation_message';
		$att2->post_source_id = 7401;
		$att2->user_source_id = 9401;
		$att2->real_filename = 'sample_image_file.jpg';
		$att2->source_physical_path = $f2_path;
		$att2->filesize = filesize($f2_path);
		$att2->filetime = 1050;
		$att2->mimetype = 'image/jpeg';

		$att4_disallowed = new attachment_dto();
		$att4_disallowed->source_id = 6004;
		$att4_disallowed->data_id = 6004;
		$att4_disallowed->content_type = 'conversation_message';
		$att4_disallowed->post_source_id = 7401;
		$att4_disallowed->user_source_id = 9401;
		$att4_disallowed->real_filename = 'script.php';
		$att4_disallowed->source_physical_path = $f4_path;
		$att4_disallowed->filesize = filesize($f4_path);
		$att4_disallowed->filetime = 1200;

		// Execute write_attachments
		$write_res = $writer->write_attachments([$att3, $att1, $att2, $att4_disallowed], [
			'run_id'              => $run_id,
			'source_system'       => $source_system,
			'attachment_policy'   => 'respect_target_policy',
			'missing_file_policy' => 'skip',
		]);

		// =========================================================================
		// TEST 1: Schema & Content Type Semantics
		// =========================================================================
		if ($write_res[6001]['status'] !== 'success' || $write_res[6002]['status'] !== 'success' || $write_res[6003]['status'] !== 'success')
		{
			throw new \Exception("write_attachments failed: " . json_encode($write_res));
		}
		if ($write_res[6004]['status'] !== 'skipped')
		{
			throw new \Exception("Disallowed .php attachment was not skipped!");
		}

		$tgt_att1 = (int)$write_res[6001]['target_id'];
		$tgt_att2 = (int)$write_res[6002]['target_id'];
		$tgt_att3 = (int)$write_res[6003]['target_id'];

		$sql = "SELECT attach_id, post_msg_id, topic_id, in_message, poster_id, real_filename, extension 
				FROM {$table_prefix}attachments WHERE attach_id IN ({$tgt_att1}, {$tgt_att2}, {$tgt_att3})";
		$res = $db->sql_query($sql);
		$rows = [];
		while ($r = $db->sql_fetchrow($res))
		{
			$rows[(int)$r['attach_id']] = $r;
		}
		$db->sql_freeresult($res);

		foreach ([$tgt_att1, $tgt_att2, $tgt_att3] as $tid)
		{
			$r = $rows[$tid];
			if ((int)$r['in_message'] !== 1)
			{
				throw new \Exception("PM attachment expected in_message = 1, got {$r['in_message']}");
			}
			if ((int)$r['post_msg_id'] !== $tgt_msg1)
			{
				throw new \Exception("PM attachment expected post_msg_id = {$tgt_msg1}, got {$r['post_msg_id']}");
			}
			if ((int)$r['topic_id'] !== 0)
			{
				throw new \Exception("PM attachment expected topic_id = 0, got {$r['topic_id']}");
			}
			if ((int)$r['poster_id'] !== $target_uA)
			{
				throw new \Exception("PM attachment expected poster_id = {$target_uA}, got {$r['poster_id']}");
			}
		}

		// Verify distinct mapping type: pm_attachment
		$mapped_target = $id_mapper->get_target_id($source_system, 'pm_attachment', 6001);
		if ((int)$mapped_target !== $tgt_att1)
		{
			throw new \Exception("ID mapping for pm_attachment not found or mismatch!");
		}
		$post_mapped = $id_mapper->get_target_id($source_system, 'attachment', 6001);
		if ($post_mapped !== null)
		{
			throw new \Exception("Post attachment mapping must not overlap with pm_attachment mapping!");
		}

		// =========================================================================
		// TEST 2: Native UCP Ordering & Inline Marker Replacement
		// =========================================================================
		// Query finalized PM text
		$sql = "SELECT message_text, message_attachment FROM {$table_prefix}privmsgs WHERE msg_id = {$tgt_msg1}";
		$pm_final = $db->sql_fetchrow($db->sql_query($sql));

		if ((int)$pm_final['message_attachment'] !== 1)
		{
			throw new \Exception("message_attachment flag expected 1, got " . $pm_final['message_attachment']);
		}

		// Message 2 should have message_attachment = 0
		$sql = "SELECT message_attachment FROM {$table_prefix}privmsgs WHERE msg_id = {$tgt_msg2}";
		$pm2_final = $db->sql_fetchrow($db->sql_query($sql));
		if ((int)$pm2_final['message_attachment'] !== 0)
		{
			throw new \Exception("Message 2 message_attachment expected 0, got " . $pm2_final['message_attachment']);
		}

		// In native UCP query (ORDER BY filetime DESC, post_msg_id ASC):
		// 6001 (T=1100) is index 0 -> att1.png
		// 6002 (T=1050) is index 1 -> sample_image_file.jpg
		// In PM text:
		// [[MC_PM_ATTACH:6002]] must be replaced by [attachment=1]sample_image_file.jpg[/attachment]
		// [[MC_PM_ATTACH:6001]] must be replaced by [attachment=0]att1.png[/attachment]
		// [[MC_PM_ATTACH:9999]] must be replaced by [Attachment unavailable: #9999]
		$text = $pm_final['message_text'];

		if (strpos($text, 'index="1"') === false || strpos($text, 'sample_image_file.jpg') === false)
		{
			throw new \Exception("Marker [[MC_PM_ATTACH:6002]] was not correctly replaced with index 1 for sample_image_file.jpg. Got text: {$text}");
		}
		if (strpos($text, 'index="0"') === false || strpos($text, 'att1.png') === false)
		{
			throw new \Exception("Marker [[MC_PM_ATTACH:6001]] was not correctly replaced with index 0 for att1.png. Got text: {$text}");
		}
		if (strpos($text, '[Attachment unavailable: #9999]') === false)
		{
			throw new \Exception("Missing marker fallback expected [Attachment unavailable: #9999]. Got text: {$text}");
		}

		// =========================================================================
		// TEST 3: Download Authorization & Privacy Guard
		// =========================================================================
		// User A (Author, active in sentbox): ALLOWED
		$event_A = new \phpbb\event\data([
			'allowed' => true,
			'msg_id'  => $tgt_msg1,
			'user_id' => $target_uA,
		]);
		$listener->modify_pm_attach_download_auth($event_A);
		if ($event_A['allowed'] !== true)
		{
			throw new \Exception("Author User A should be allowed to download PM attachment!");
		}

		// User B (Recipient, active in inbox): ALLOWED
		$event_B = new \phpbb\event\data([
			'allowed' => true,
			'msg_id'  => $tgt_msg1,
			'user_id' => $target_uB,
		]);
		$listener->modify_pm_attach_download_auth($event_B);
		if ($event_B['allowed'] !== true)
		{
			throw new \Exception("Active recipient User B should be allowed to download PM attachment!");
		}

		// User B marks message deleted (pm_deleted = 1): DENIED
		$db->sql_query("UPDATE {$table_prefix}privmsgs_to SET pm_deleted = 1 WHERE msg_id = {$tgt_msg1} AND user_id = {$target_uB}");
		$event_B_del = new \phpbb\event\data([
			'allowed' => true,
			'msg_id'  => $tgt_msg1,
			'user_id' => $target_uB,
		]);
		$listener->modify_pm_attach_download_auth($event_B_del);
		if ($event_B_del['allowed'] !== false)
		{
			throw new \Exception("Deleted recipient User B must be DENIED download access!");
		}

		// User C (Late joiner, joined at T=2000, has NO row for Msg 1): DENIED
		$event_C = new \phpbb\event\data([
			'allowed' => true,
			'msg_id'  => $tgt_msg1,
			'user_id' => $target_uC,
		]);
		$listener->modify_pm_attach_download_auth($event_C);
		if ($event_C['allowed'] !== false)
		{
			throw new \Exception("Late joiner User C must be DENIED download access to Msg 1!");
		}

		// User D (Unrelated user): DENIED
		$event_D = new \phpbb\event\data([
			'allowed' => true,
			'msg_id'  => $tgt_msg1,
			'user_id' => $target_uD,
		]);
		$listener->modify_pm_attach_download_auth($event_D);
		if ($event_D['allowed'] !== false)
		{
			throw new \Exception("Unrelated User D must be DENIED download access!");
		}

		// Anonymous User (ID 1): DENIED
		$event_Anon = new \phpbb\event\data([
			'allowed' => true,
			'msg_id'  => $tgt_msg1,
			'user_id' => 1,
		]);
		$listener->modify_pm_attach_download_auth($event_Anon);
		if ($event_Anon['allowed'] !== false)
		{
			throw new \Exception("Anonymous user must be DENIED download access!");
		}

		// Cleanup test data
		$all_att_ids = implode(',', [$tgt_att1, $tgt_att2, $tgt_att3]);
		$db->sql_query("DELETE FROM {$table_prefix}attachments WHERE attach_id IN ({$all_att_ids})");
		$db->sql_query("DELETE FROM {$table_prefix}privmsgs_to WHERE msg_id IN ({$tgt_msg1}, {$tgt_msg2})");
		$db->sql_query("DELETE FROM {$table_prefix}privmsgs WHERE msg_id IN ({$tgt_msg1}, {$tgt_msg2})");
		$db->sql_query("DELETE FROM {$table_prefix}users WHERE user_id IN ({$target_uA}, {$target_uB}, {$target_uC}, {$target_uD})");
		$db->sql_query("DELETE FROM {$table_prefix}migration_id_map WHERE run_id = '{$run_id}'");

		// Remove temp files
		@unlink($f1_path);
		@unlink($f2_path);
		@unlink($f3_path);
		@unlink($f4_path);
		@rmdir($tmp_dir);

		return true;
	}
}
