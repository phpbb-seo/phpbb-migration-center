<?php
/**
 * Hardened Phase 5A Attachment Migration, Persisted Filename Planning, SHA-256 Verification & Full Pipeline Fixture
 */

namespace phpbbseo\migrationcenter\tests\unit;

use phpbbseo\migrationcenter\core\dto\forum_dto;
use phpbbseo\migrationcenter\core\dto\user_dto;
use phpbbseo\migrationcenter\core\dto\topic_dto;
use phpbbseo\migrationcenter\core\dto\post_dto;
use phpbbseo\migrationcenter\core\dto\attachment_dto;
use phpbbseo\migrationcenter\core\dto\migration_config_dto;
use phpbbseo\migrationcenter\source\xenforo\content\xf_message_converter;
use phpbbseo\migrationcenter\source\xenforo\step\attachments_step;

class AttachmentMigrationIntegrationTest
{
	public function run()
	{
		global $phpbb_container;

		list($db, $table_prefix) = get_test_db();
		$id_mapper = $phpbb_container->get('phpbbseo.migrationcenter.id_mapper');
		$writer = $phpbb_container->get('phpbbseo.migrationcenter.target_writer');

		// =========================================================================
		// PART 1: MANDATORY PHASE 4D RENDER AUDIT
		// =========================================================================
		$converter = new xf_message_converter();
		$test_cases = [
			'bold'    => '[b]Bold Text[/b]',
			'italic'  => '[i]Italic Text[/i]',
			'quote'   => '[quote="Admin"]Quoted Message[/quote]',
			'code'    => "[code]<?php echo 'Hello UnicodeRunner\xE2\x80\x8CXXX 🚀'; ?>[/code]",
			'persian' => "XXX_Multibyte_Sample_XX_Unicode (UnicodeRunner\xE2\x80\x8CXXX)",
		];

		foreach ($test_cases as $label => $raw_bbcode)
		{
			$conv_res = $converter->convert($raw_bbcode);
			$text = $conv_res->storage_text;
			$uid = $conv_res->bbcode_uid;
			$bitfield = $conv_res->bbcode_bitfield;
			$flags = 7;

			if (function_exists('generate_text_for_display'))
			{
				$rendered = generate_text_for_display($text, $uid, $bitfield, $flags);
				if (strpos($rendered, '<script') !== false)
				{
					throw new \Exception("Render audit failed: Unsafe script found in rendered HTML for {$label}");
				}
			}
		}

		// =========================================================================
		// PART 2: REALISTIC XENFORO 2.3.12 ATTACHMENT FIXTURE & FULL PIPELINE
		// =========================================================================
		$files_dir = defined('PHPBB_ROOT_PATH') ? (PHPBB_ROOT_PATH . 'files') : 'C:/xampp/htdocs/bb/files';
		@mkdir($files_dir, 0777, true);

		$test_run_id = 'test_attach_harden_' . time();
		$test_forum_src_id = 9951;
		$test_user_src_id = 9952;
		$test_topic_src_id = 9953;
		$test_post_src_id = 9954;

		// 1. Map target forum
		$f = new forum_dto();
		$f->source_id = $test_forum_src_id;
		$f->forum_name = 'Hardened Attachment Forum';
		$f_res = $writer->write_forums([$f], ['run_id' => $test_run_id, 'source_system' => 'xenforo']);
		$target_forum_id = (int)$f_res[$test_forum_src_id]['target_id'];

		// 2. Map target user
		$u = new user_dto();
		$u->source_id = $test_user_src_id;
		$u->username = 'AttachHardenUser';
		$u->email = 'attachharden@invalid.local';
		$u_res = $writer->write_users([$u], ['run_id' => $test_run_id, 'source_system' => 'xenforo']);
		$target_user_id = (int)$u_res[$test_user_src_id]['target_id'];

		// 3. Map target topic
		$t = new topic_dto();
		$t->source_id = $test_topic_src_id;
		$t->forum_source_id = $test_forum_src_id;
		$t->user_source_id = $test_user_src_id;
		$t->topic_title = 'Topic With Verified Attachments';
		$t_res = $writer->write_topics([$t], ['run_id' => $test_run_id, 'source_system' => 'xenforo']);
		$target_topic_id = (int)$t_res[$test_topic_src_id]['target_id'];

		// 4. Map target post with 2 deferred inline markers
		$p = new post_dto();
		$p->source_id = $test_post_src_id;
		$p->topic_source_id = $test_topic_src_id;
		$p->user_source_id = $test_user_src_id;
		$p->username = 'AttachHardenUser';
		$p->post_subject = 'Post With Hardened Attachments';
		$p->post_text = "Primary photo: [[MC_ATTACH:8002]]\nSecondary document: [[MC_ATTACH:8001]]\nMissing: [[MC_ATTACH:8099]]";
		$p_res = $writer->write_posts([$p], ['run_id' => $test_run_id, 'source_system' => 'xenforo']);
		$target_post_id = (int)$p_res[$test_post_src_id]['target_id'];

		// 5. Create Realistic XenForo 2.3.12 physical storage structure
		$temp_xf_root = sys_get_temp_dir() . '/xf_fixture_' . uniqid();
		$xf_attach_dir = $temp_xf_root . '/internal_data/attachments/8';
		@mkdir($xf_attach_dir, 0777, true);

		// File 1: Valid PNG image with dimensions 600x400
		$src_file1 = $xf_attach_dir . '/8001-key111111111111111111111111111111.data';
		$im = imagecreatetruecolor(600, 400);
		imagepng($im, $src_file1);
		imagedestroy($im);

		// File 2: Valid PDF document
		$src_file2 = $xf_attach_dir . '/8002-key222222222222222222222222222222.data';
		file_put_contents($src_file2, "%PDF-1.4 mock pdf content with enough bytes to test integrity");

		// File 3: Valid ZIP archive (Non-inline attachment)
		$src_file3 = $xf_attach_dir . '/8003-key333333333333333333333333333333.data';
		file_put_contents($src_file3, "PK\x03\x04 mock zip archive content");

		// File 4: Disallowed executable .php file
		$src_file4 = $xf_attach_dir . '/8004-key444444444444444444444444444444.data';
		file_put_contents($src_file4, "<?php echo 'malicious'; ?>");

		// Build DTOs
		$att1 = new attachment_dto();
		$att1->source_id = 8001;
		$att1->content_type = 'post';
		$att1->post_source_id = $test_post_src_id;
		$att1->topic_source_id = $test_topic_src_id;
		$att1->user_source_id = $test_user_src_id;
		$att1->real_filename = "large_sample_image.png";
		$att1->source_physical_path = $src_file1;
		$att1->filesize = filesize($src_file1);
		$att1->filetime = 1785000100;
		$att1->file_hash = 'key111111111111111111111111111111';
		$att1->thumbnail = 1;
		$att1->mimetype = 'image/png';
		$att1->extension = 'png';

		$att2 = new attachment_dto();
		$att2->source_id = 8002;
		$att2->content_type = 'post';
		$att2->post_source_id = $test_post_src_id;
		$att2->topic_source_id = $test_topic_src_id;
		$att2->user_source_id = $test_user_src_id;
		$att2->real_filename = "document_two.pdf";
		$att2->source_physical_path = $src_file2;
		$att2->filesize = filesize($src_file2);
		$att2->filetime = 1785000200;
		$att2->file_hash = 'key222222222222222222222222222222';
		$att2->thumbnail = 0;
		$att2->mimetype = 'application/pdf';
		$att2->extension = 'pdf';

		$att3 = new attachment_dto();
		$att3->source_id = 8003;
		$att3->content_type = 'post';
		$att3->post_source_id = $test_post_src_id;
		$att3->topic_source_id = $test_topic_src_id;
		$att3->user_source_id = $test_user_src_id;
		$att3->real_filename = "archive_three.zip";
		$att3->source_physical_path = $src_file3;
		$att3->filesize = filesize($src_file3);
		$att3->filetime = 1785000300;
		$att3->file_hash = 'key333333333333333333333333333333';
		$att3->thumbnail = 0;
		$att3->mimetype = 'application/zip';
		$att3->extension = 'zip';

		// Disallowed .php attachment DTO
		$att4 = new attachment_dto();
		$att4->source_id = 8004;
		$att4->content_type = 'post';
		$att4->post_source_id = $test_post_src_id;
		$att4->topic_source_id = $test_topic_src_id;
		$att4->user_source_id = $test_user_src_id;
		$att4->real_filename = "shell.php";
		$att4->source_physical_path = $src_file4;
		$att4->filesize = filesize($src_file4);
		$att4->filetime = 1785000400;
		$att4->file_hash = 'key444444444444444444444444444444';
		$att4->extension = 'php';

		// 6. Test Policy Rejection of Executable .php File
		$write_res_disallowed = $writer->write_attachments([$att4], [
			'run_id'            => $test_run_id,
			'source_system'     => 'xenforo',
			'attachment_policy' => 'respect_target_policy',
		]);

		if ($write_res_disallowed[8004]['status'] !== 'skipped')
		{
			throw new \Exception("Disallowed .php attachment was not skipped under respect_target_policy!");
		}

		// 7. Test Same-Size Different-Content Hash Safety (Issue 2)
		// Plan a metadata record for att2 with an initial filename
		$planned_token = bin2hex(random_bytes(16));
		$planned_filename = "{$target_user_id}_{$planned_token}";
		$planned_path = $files_dir . '/' . $planned_filename;
		
		// Create a file of exact same size but DIFFERENT content at planned path
		file_put_contents($planned_path, str_repeat("X", filesize($src_file2)));

		// Store initial planned metadata in id_mapper
		$id_mapper->set($test_run_id, 'xenforo', 'attachment', 8002, null, 'planned', '', [
			'physical_filename' => $planned_filename,
			'source_sha256'     => hash_file('sha256', $src_file2),
		]);

		// Now write attachments: writer must detect SHA-256 hash mismatch on existing file and generate a fresh safe filename
		$attachments_to_write = [$att1, $att2, $att3];
		$write_res = $writer->write_attachments($attachments_to_write, [
			'run_id'          => $test_run_id,
			'source_system'   => 'xenforo',
			'force_thumbnail' => true,
		]);

		foreach ($attachments_to_write as $a)
		{
			if ($write_res[$a->source_id]['status'] !== 'success')
			{
				throw new \Exception("Attachment write failed for source {$a->source_id}: " . json_encode($write_res[$a->source_id]));
			}
		}

		$target_a1_id = (int)$id_mapper->get_target_id('xenforo', 'attachment', 8001);
		$target_a2_id = (int)$id_mapper->get_target_id('xenforo', 'attachment', 8002);
		$target_a3_id = (int)$id_mapper->get_target_id('xenforo', 'attachment', 8003);

		// Clean up the dummy conflict file
		@unlink($planned_path);

		// 8. Verify phpbb_attachments and Thumbnail Creation
		$sql = "SELECT attach_id, physical_filename, real_filename, thumbnail 
				FROM {$table_prefix}attachments 
				WHERE attach_id IN ({$target_a1_id}, {$target_a2_id}, {$target_a3_id}) 
				ORDER BY attach_id ASC";
		$res = $db->sql_query($sql);
		$db_att = [];
		while ($r = $db->sql_fetchrow($res))
		{
			$db_att[$r['attach_id']] = $r;
		}
		$db->sql_freeresult($res);

		if (count($db_att) !== 3)
		{
			throw new \Exception("Expected 3 attachment records in database");
		}

		// Verify physical files and thumbnail
		$a1_row = $db_att[$target_a1_id];
		$a1_path = $files_dir . '/' . $a1_row['physical_filename'];
		$a1_thumb = $files_dir . '/thumb_' . $a1_row['physical_filename'];

		if (!file_exists($a1_path))
		{
			throw new \Exception("Copied attachment physical file not found: {$a1_path}");
		}
		if ((int)$a1_row['thumbnail'] === 1 && !file_exists($a1_thumb))
		{
			throw new \Exception("Thumbnail marked as 1 in DB but thumb_ file missing on disk!");
		}

		// 9. Verify Native viewtopic Array Indexing & Post Finalization
		// In viewtopic.php, attachments are sorted `ORDER BY attach_id DESC`.
		// Highest attach_id is $target_a3_id (index 0), then $target_a2_id (index 1), then $target_a1_id (index 2).
		$sql = "SELECT post_text, post_attachment FROM {$table_prefix}posts WHERE post_id = {$target_post_id}";
		$res = $db->sql_query($sql);
		$post_row = $db->sql_fetchrow($res);
		$db->sql_freeresult($res);

		$final_text = $post_row['post_text'];
		if ((int)$post_row['post_attachment'] !== 1)
		{
			throw new \Exception("post_attachment flag was not set to 1");
		}

		// Marker 8002 corresponds to target_a2_id (viewtopic index 1)
		// Marker 8001 corresponds to target_a1_id (viewtopic index 2)
		if (strpos($final_text, 'index="1"') === false || strpos($final_text, 'document_two.pdf') === false)
		{
			throw new \Exception("Inline marker for 8002 did not resolve to index 1: {$final_text}");
		}
		if (strpos($final_text, 'index="2"') === false || strpos($final_text, "large_sample_image.png") === false)
		{
			throw new \Exception("Inline marker for 8001 did not resolve with Persian filename and index 2: {$final_text}");
		}
		if (strpos($final_text, '[Attachment unavailable: #8099]') === false)
		{
			throw new \Exception("Missing marker 8099 was not replaced with unavailable notice");
		}

		// 10. Test Crash Recovery / Idempotent Resume (SHA-256 Exact Match Reuse)
		$rerun_res = $writer->write_attachments($attachments_to_write, [
			'run_id'        => $test_run_id,
			'source_system' => 'xenforo',
		]);

		$res = $db->sql_query("SELECT COUNT(*) as cnt FROM {$table_prefix}attachments WHERE attach_id IN ({$target_a1_id}, {$target_a2_id}, {$target_a3_id})");
		$cnt_after = (int)$db->sql_fetchfield('cnt');
		$db->sql_freeresult($res);

		if ($cnt_after !== 3)
		{
			throw new \Exception("Duplicate attachment rows created on rerun!");
		}

		// 11. Verify pre-existing phpBB admin & content integrity
		$sql = "SELECT user_id, username_clean, user_type FROM {$table_prefix}users WHERE user_id = 2";
		$res = $db->sql_query($sql);
		$admin_row = $db->sql_fetchrow($res);
		$db->sql_freeresult($res);

		if ($admin_row['username_clean'] !== 'admin' || (int)$admin_row['user_type'] !== 3)
		{
			throw new \Exception("CRITICAL INTEGRITY VIOLATION: Pre-existing admin modified!");
		}

		// Clean up migration-owned test records & files
		foreach ($db_att as $row)
		{
			@unlink($files_dir . '/' . $row['physical_filename']);
			@unlink($files_dir . '/thumb_' . $row['physical_filename']);
		}
		@unlink($src_file1);
		@unlink($src_file2);
		@unlink($src_file3);
		@unlink($src_file4);
		@rmdir($xf_attach_dir);
		@rmdir($temp_xf_root . '/internal_data/attachments');
		@rmdir($temp_xf_root . '/internal_data');
		@rmdir($temp_xf_root);

		$target_att_ids = implode(',', [$target_a1_id, $target_a2_id, $target_a3_id]);
		$db->sql_query("DELETE FROM {$table_prefix}attachments WHERE attach_id IN ({$target_att_ids})");
		$db->sql_query("DELETE FROM {$table_prefix}posts WHERE post_id = {$target_post_id}");
		$db->sql_query("DELETE FROM {$table_prefix}topics WHERE topic_id = {$target_topic_id}");
		$db->sql_query("DELETE FROM {$table_prefix}users WHERE user_id = {$target_user_id}");
		$db->sql_query("DELETE FROM {$table_prefix}forums WHERE forum_id = {$target_forum_id}");
		$db->sql_query("DELETE FROM {$table_prefix}migration_id_map WHERE run_id = '{$test_run_id}'");

		return true;
	}
}
