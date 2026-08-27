<?php
/**
 * Attachment Policy, Filename Ownership, Collision & Config Thumbnail Unit Test
 */

namespace phpbbseo\migrationcenter\tests\unit;

use phpbbseo\migrationcenter\core\dto\attachment_dto;
use phpbbseo\migrationcenter\core\dto\forum_dto;
use phpbbseo\migrationcenter\core\dto\user_dto;
use phpbbseo\migrationcenter\core\dto\topic_dto;
use phpbbseo\migrationcenter\core\dto\post_dto;

class AttachmentPolicyAndCollisionTest
{
	public function run()
	{
		global $phpbb_container;

		list($db, $table_prefix) = get_test_db();
		$id_mapper = $phpbb_container->get('phpbbseo.migrationcenter.id_mapper');
		$writer = $phpbb_container->get('phpbbseo.migrationcenter.target_writer');
		$files_dir = defined('PHPBB_ROOT_PATH') ? (PHPBB_ROOT_PATH . 'files') : 'C:/xampp/htdocs/bb/files';
		@mkdir($files_dir, 0777, true);

		$temp_dir = sys_get_temp_dir() . '/attach_collision_test_' . uniqid();
		@mkdir($temp_dir, 0777, true);

		$src_file_a = $temp_dir . '/test_a.png';
		$src_file_b = $temp_dir . '/test_b.png';

		$im_a = imagecreatetruecolor(500, 300);
		imagepng($im_a, $src_file_a);
		imagedestroy($im_a);

		$im_b = imagecreatetruecolor(500, 300);
		imagepng($im_b, $src_file_b);
		imagedestroy($im_b);

		$run1 = 'run_collision_1_' . time();
		$run2 = 'run_collision_2_' . time();

		// Setup target forum, user, topic, post
		$f = new forum_dto();
		$f->source_id = 9981;
		$f->forum_name = 'Collision Test Forum';
		$f_res = $writer->write_forums([$f], ['run_id' => $run1, 'source_system' => 'xenforo_inst1']);
		$target_fid = (int)$f_res[9981]['target_id'];

		$u = new user_dto();
		$u->source_id = 9982;
		$u->username = 'CollisionUser';
		$u->email = 'collision@invalid.local';
		$u_res = $writer->write_users([$u], ['run_id' => $run1, 'source_system' => 'xenforo_inst1']);
		$target_uid = (int)$u_res[9982]['target_id'];

		$t = new topic_dto();
		$t->source_id = 9983;
		$t->forum_source_id = 9981;
		$t->user_source_id = 9982;
		$t->topic_title = 'Collision Topic';
		$t_res = $writer->write_topics([$t], ['run_id' => $run1, 'source_system' => 'xenforo_inst1']);
		$target_tid = (int)$t_res[9983]['target_id'];

		$p = new post_dto();
		$p->source_id = 9984;
		$p->topic_source_id = 9983;
		$p->user_source_id = 9982;
		$p->username = 'CollisionUser';
		$p->post_subject = 'Collision Post';
		$p->post_text = 'Collision Post Text [[MC_ATTACH:9001]]';
		$p_res = $writer->write_posts([$p], ['run_id' => $run1, 'source_system' => 'xenforo_inst1']);
		$target_pid = (int)$p_res[9984]['target_id'];

		// Also map for inst2
		$id_mapper->set($run2, 'xenforo_inst2', 'post', 9984, $target_pid);

		// Instance 1 with attachment ID 9001
		$att1 = new attachment_dto();
		$att1->source_id = 9001;
		$att1->content_type = 'post';
		$att1->post_source_id = 9984;
		$att1->topic_source_id = 9983;
		$att1->user_source_id = 9982;
		$att1->real_filename = "instance1_image.png";
		$att1->source_physical_path = $src_file_a;
		$att1->filesize = filesize($src_file_a);
		$att1->filetime = 1785000100;
		$att1->mimetype = 'image/png';
		$att1->extension = 'png';
		$att1->thumbnail = 1;

		$res1 = $writer->write_attachments([$att1], [
			'run_id'          => $run1,
			'source_system'   => 'xenforo_inst1',
			'force_thumbnail' => true,
		]);

		$tgt_att1_id = (int)$res1[9001]['target_id'];
		$meta1 = $id_mapper->get_metadata('xenforo_inst1', 'attachment', 9001);
		$phys_file1 = $meta1['physical_filename'];

		// Instance 2 with identical attachment ID 9001
		$att2 = new attachment_dto();
		$att2->source_id = 9001;
		$att2->content_type = 'post';
		$att2->post_source_id = 9984;
		$att2->topic_source_id = 9983;
		$att2->user_source_id = 9982;
		$att2->real_filename = "instance2_image.png";
		$att2->source_physical_path = $src_file_b;
		$att2->filesize = filesize($src_file_b);
		$att2->filetime = 1785000200;
		$att2->mimetype = 'image/png';
		$att2->extension = 'png';
		$att2->thumbnail = 1;

		$res2 = $writer->write_attachments([$att2], [
			'run_id'          => $run2,
			'source_system'   => 'xenforo_inst2',
			'force_thumbnail' => true,
		]);

		$tgt_att2_id = (int)$res2[9001]['target_id'];
		$meta2 = $id_mapper->get_metadata('xenforo_inst2', 'attachment', 9001);
		$phys_file2 = $meta2['physical_filename'];

		// Verify that physical filenames are isolated and distinct (No Collision)
		if ($phys_file1 === $phys_file2)
		{
			throw new \Exception("CRITICAL COLLISION: Two different source instances shared the same target physical filename!");
		}

		if (!file_exists($files_dir . '/' . $phys_file1) || !file_exists($files_dir . '/' . $phys_file2))
		{
			throw new \Exception("Target physical files missing after multi-instance write");
		}

		// Clean up instance test files
		@unlink($files_dir . '/' . $phys_file1);
		@unlink($files_dir . '/thumb_' . $phys_file1);
		@unlink($files_dir . '/' . $phys_file2);
		@unlink($files_dir . '/thumb_' . $phys_file2);
		@unlink($src_file_a);
		@unlink($src_file_b);

		$db->sql_query("DELETE FROM {$table_prefix}attachments WHERE attach_id IN ({$tgt_att1_id}, {$tgt_att2_id})");
		$db->sql_query("DELETE FROM {$table_prefix}posts WHERE post_id = {$target_pid}");
		$db->sql_query("DELETE FROM {$table_prefix}topics WHERE topic_id = {$target_tid}");
		$db->sql_query("DELETE FROM {$table_prefix}users WHERE user_id = {$target_uid}");
		$db->sql_query("DELETE FROM {$table_prefix}forums WHERE forum_id = {$target_fid}");
		$db->sql_query("DELETE FROM {$table_prefix}migration_id_map WHERE run_id IN ('{$run1}', '{$run2}')");

		// Test 2: Config-Driven Thumbnail Custom Width (e.g. 250px)
		$src_file_c = $temp_dir . '/test_c.png';
		$im_c = imagecreatetruecolor(800, 600);
		imagepng($im_c, $src_file_c);
		imagedestroy($im_c);

		// Temporarily change phpbb_config img_max_thumb_width to 250
		$db->sql_query("UPDATE {$table_prefix}config SET config_value = '1' WHERE config_name = 'img_create_thumbnail'");
		$db->sql_query("UPDATE {$table_prefix}config SET config_value = '250' WHERE config_name = 'img_max_thumb_width'");
		$db->sql_query("UPDATE {$table_prefix}config SET config_value = '100' WHERE config_name = 'img_min_thumb_filesize'");

		$run3 = 'run_thumb_cfg_' . time();
		$id_mapper->set($run3, 'xenforo', 'post', 9999, 1); // Post ID 1 exists

		$att3 = new attachment_dto();
		$att3->source_id = 9005;
		$att3->content_type = 'post';
		$att3->post_source_id = 9999;
		$att3->topic_source_id = 1;
		$att3->user_source_id = 2;
		$att3->real_filename = "custom_thumb_test.png";
		$att3->source_physical_path = $src_file_c;
		$att3->filesize = filesize($src_file_c);
		$att3->filetime = 1785000300;
		$att3->mimetype = 'image/png';
		$att3->extension = 'png';
		$att3->thumbnail = 1;

		$res3 = $writer->write_attachments([$att3], [
			'run_id'        => $run3,
			'source_system' => 'xenforo',
		]);

		$tgt_att3_id = (int)$res3[9005]['target_id'];
		$meta3 = $id_mapper->get_metadata('xenforo', 'attachment', 9005);
		$phys_file3 = $meta3['physical_filename'];
		$thumb_file3 = $files_dir . '/thumb_' . $phys_file3;

		if (!file_exists($thumb_file3))
		{
			throw new \Exception("Config-driven thumbnail file not created at {$thumb_file3}");
		}

		$thumb_info = getimagesize($thumb_file3);
		if ($thumb_info[0] !== 250) // Max width 250 respected!
		{
			throw new \Exception("Thumbnail max width did not respect phpbb_config value 250. Got: {$thumb_info[0]}");
		}

		// Restore phpbb_config
		$db->sql_query("UPDATE {$table_prefix}config SET config_value = '0' WHERE config_name = 'img_create_thumbnail'");
		$db->sql_query("UPDATE {$table_prefix}config SET config_value = '400' WHERE config_name = 'img_max_thumb_width'");
		$db->sql_query("UPDATE {$table_prefix}config SET config_value = '12000' WHERE config_name = 'img_min_thumb_filesize'");

		@unlink($thumb_file3);
		@unlink($files_dir . '/' . $phys_file3);
		@unlink($src_file_c);
		@rmdir($temp_dir);

		$db->sql_query("DELETE FROM {$table_prefix}attachments WHERE attach_id = {$tgt_att3_id}");
		$db->sql_query("DELETE FROM {$table_prefix}migration_id_map WHERE run_id = '{$run3}'");

		return true;
	}
}
