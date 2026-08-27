<?php
/**
 * Hardened Phase 5B Avatar Migration, Resizing, Gravatar & Native phpBB Avatar Rendering Integration Test
 */

namespace phpbbseo\migrationcenter\tests\unit;

use phpbbseo\migrationcenter\core\dto\user_dto;
use phpbbseo\migrationcenter\core\dto\avatar_dto;
use phpbbseo\migrationcenter\core\dto\migration_config_dto;
use phpbbseo\migrationcenter\source\xenforo\step\avatars_step;
use phpbbseo\migrationcenter\source\xenforo\normalizer\xf_avatar_normalizer;

class AvatarMigrationIntegrationTest
{
	public function run()
	{
		global $phpbb_container;

		list($db, $table_prefix) = get_test_db();
		$id_mapper = $phpbb_container->get('phpbbseo.migrationcenter.id_mapper');
		$writer = $phpbb_container->get('phpbbseo.migrationcenter.target_writer');

		$run_id = 'test_avatar_' . time();
		$temp_dir = sys_get_temp_dir() . '/xf_avatar_integ_' . uniqid();
		$avatar_o_dir = $temp_dir . '/data/avatars/o/9';
		$avatar_l_dir = $temp_dir . '/data/avatars/l/9';
		@mkdir($avatar_o_dir, 0777, true);
		@mkdir($avatar_l_dir, 0777, true);

		// Get phpBB avatar configuration
		$sql = "SELECT config_name, config_value FROM {$table_prefix}config WHERE config_name IN ('avatar_salt', 'avatar_path', 'avatar_max_width', 'avatar_max_height')";
		$res = $db->sql_query($sql);
		$cfg = [];
		while ($r = $db->sql_fetchrow($res))
		{
			$cfg[$r['config_name']] = $r['config_value'];
		}
		$db->sql_freeresult($res);

		$avatar_salt = (string)($cfg['avatar_salt'] ?? '');
		$avatar_path = (string)($cfg['avatar_path'] ?? 'images/avatars/upload');
		$phpbb_root = defined('PHPBB_ROOT_PATH') ? PHPBB_ROOT_PATH : 'C:/xampp/htdocs/bb/';
		$target_avatar_dir = rtrim($phpbb_root . $avatar_path, '/\\');
		@mkdir($target_avatar_dir, 0777, true);

		// Setup Test Users in phpBB
		$u1 = new user_dto();
		$u1->source_id = 9101;
		$u1->username = 'AvatarUserOne';
		$u1->email = 'avatar1@invalid.local';
		$u1_res = $writer->write_users([$u1], ['run_id' => $run_id, 'source_system' => 'xenforo']);
		$target_u1_id = (int)$u1_res[9101]['target_id'];

		$u2 = new user_dto();
		$u2->source_id = 9102;
		$u2->username = 'AvatarUserTwo';
		$u2->email = 'avatar2@invalid.local';
		$u2_res = $writer->write_users([$u2], ['run_id' => $run_id, 'source_system' => 'xenforo']);
		$target_u2_id = (int)$u2_res[9102]['target_id'];

		$u3 = new user_dto();
		$u3->source_id = 9103;
		$u3->username = 'AvatarUserThree';
		$u3->email = 'avatar3@invalid.local';
		$u3_res = $writer->write_users([$u3], ['run_id' => $run_id, 'source_system' => 'xenforo']);
		$target_u3_id = (int)$u3_res[9103]['target_id'];

		$u4 = new user_dto();
		$u4->source_id = 9104;
		$u4->username = 'AvatarUserFour';
		$u4->email = 'avatar4@invalid.local';
		$u4_res = $writer->write_users([$u4], ['run_id' => $run_id, 'source_system' => 'xenforo']);
		$target_u4_id = (int)$u4_res[9104]['target_id'];

		// User 5: Target user already has an existing avatar
		$u5 = new user_dto();
		$u5->source_id = 9105;
		$u5->username = 'AvatarUserFive';
		$u5->email = 'avatar5@invalid.local';
		$u5_res = $writer->write_users([$u5], ['run_id' => $run_id, 'source_system' => 'xenforo']);
		$target_u5_id = (int)$u5_res[9105]['target_id'];

		// Reset avatar fields for test users
		$db->sql_query("UPDATE {$table_prefix}users SET user_avatar = '', user_avatar_type = '', user_avatar_width = 0, user_avatar_height = 0 WHERE user_id IN ({$target_u1_id}, {$target_u2_id}, {$target_u3_id}, {$target_u4_id})");

		// Pre-populate an existing avatar for User 5
		$db->sql_query("UPDATE {$table_prefix}users SET user_avatar = 'existing_custom_avatar.png', user_avatar_type = 'avatar.driver.upload', user_avatar_width = 80, user_avatar_height = 80 WHERE user_id = {$target_u5_id}");

		// 1. Create File 1: Oversized JPEG (500x500) $\to$ requires resize to 90x90
		$src_file1 = $avatar_o_dir . '/9101.jpg';
		$im1 = imagecreatetruecolor(500, 500);
		imagejpeg($im1, $src_file1, 95);
		imagedestroy($im1);

		// 2. Create File 2: Transparent PNG (64x64)
		$src_file2 = $avatar_o_dir . '/9102.png';
		$im2 = imagecreatetruecolor(64, 64);
		imagealphablending($im2, false);
		imagesavealpha($im2, true);
		imagepng($im2, $src_file2);
		imagedestroy($im2);

		// 3. User 3: Gravatar user
		// 4. Create File 4: Large fallback (original missing, large is 192x192)
		$src_file4 = $avatar_l_dir . '/9104.jpg';
		$im4 = imagecreatetruecolor(192, 192);
		imagejpeg($im4, $src_file4, 90);
		imagedestroy($im4);

		// 5. Create File 5: Uploaded avatar for user 5 (which should be skipped to preserve existing target avatar)
		$src_file5 = $avatar_o_dir . '/9105.jpg';
		$im5 = imagecreatetruecolor(80, 80);
		imagejpeg($im5, $src_file5, 90);
		imagedestroy($im5);

		// Construct DTOs
		$av1 = new avatar_dto();
		$av1->user_source_id = 9101;
		$av1->avatar_type = 'upload';
		$av1->source_physical_path = $src_file1;
		$av1->extension = 'jpg';
		$av1->source_filesize = filesize($src_file1);
		$av1->source_width = 500;
		$av1->source_height = 500;

		$av2 = new avatar_dto();
		$av2->user_source_id = 9102;
		$av2->avatar_type = 'upload';
		$av2->source_physical_path = $src_file2;
		$av2->extension = 'png';
		$av2->source_filesize = filesize($src_file2);
		$av2->source_width = 64;
		$av2->source_height = 64;

		$av3 = new avatar_dto();
		$av3->user_source_id = 9103;
		$av3->avatar_type = 'gravatar';
		$av3->gravatar_email = 'gravatar_user@example.com';

		$av4 = new avatar_dto();
		$av4->user_source_id = 9104;
		$av4->avatar_type = 'upload';
		$av4->source_physical_path = $src_file4;
		$av4->source_size_variant = 'l';
		$av4->extension = 'jpg';
		$av4->source_filesize = filesize($src_file4);
		$av4->source_width = 192;
		$av4->source_height = 192;

		$av5 = new avatar_dto();
		$av5->user_source_id = 9105;
		$av5->avatar_type = 'upload';
		$av5->source_physical_path = $src_file5;
		$av5->extension = 'jpg';
		$av5->source_filesize = filesize($src_file5);
		$av5->source_width = 80;
		$av5->source_height = 80;

		// Execute write_avatars
		$write_res = $writer->write_avatars([$av1, $av2, $av3, $av4, $av5], [
			'run_id'                 => $run_id,
			'source_system'          => 'xenforo',
			'avatar_policy'          => 'resize_to_fit',
			'existing_avatar_policy' => 'replace_only_if_empty',
			'force_avatar'           => true,
			'force_avatar_upload'    => true,
			'force_gravatar'         => true,
		]);

		// Test 1: User 1 Oversized Resizing
		if ($write_res[9101]['status'] !== 'success')
		{
			throw new \Exception("User 1 avatar write failed: " . json_encode($write_res[9101]));
		}
		$u1_row = $db->sql_fetchrow($db->sql_query("SELECT user_avatar, user_avatar_type, user_avatar_width, user_avatar_height FROM {$table_prefix}users WHERE user_id = {$target_u1_id}"));
		if ($u1_row['user_avatar_type'] !== 'avatar.driver.upload' || (int)$u1_row['user_avatar_width'] !== 90 || (int)$u1_row['user_avatar_height'] !== 90)
		{
			throw new \Exception("User 1 avatar was not resized to 90x90. Got: " . json_encode($u1_row));
		}
		$phys_u1 = $avatar_salt !== '' ? "{$avatar_salt}_{$target_u1_id}.jpg" : "{$target_u1_id}.jpg";
		if (!file_exists($target_avatar_dir . '/' . $phys_u1))
		{
			throw new \Exception("User 1 target physical avatar file not found on disk: {$phys_u1}");
		}

		// Test 2: User 2 Transparent PNG
		if ($write_res[9102]['status'] !== 'success')
		{
			throw new \Exception("User 2 avatar write failed: " . json_encode($write_res[9102]));
		}
		$u2_row = $db->sql_fetchrow($db->sql_query("SELECT user_avatar, user_avatar_type, user_avatar_width, user_avatar_height FROM {$table_prefix}users WHERE user_id = {$target_u2_id}"));
		if ($u2_row['user_avatar_type'] !== 'avatar.driver.upload' || (int)$u2_row['user_avatar_width'] !== 64 || (int)$u2_row['user_avatar_height'] !== 64)
		{
			throw new \Exception("User 2 avatar dimension mismatch: " . json_encode($u2_row));
		}
		$phys_u2 = $avatar_salt !== '' ? "{$avatar_salt}_{$target_u2_id}.png" : "{$target_u2_id}.png";
		if (!file_exists($target_avatar_dir . '/' . $phys_u2))
		{
			throw new \Exception("User 2 target physical avatar file not found on disk: {$phys_u2}");
		}

		// Test 3: User 3 Gravatar
		if ($write_res[9103]['status'] !== 'success')
		{
			throw new \Exception("User 3 gravatar write failed: " . json_encode($write_res[9103]));
		}
		$u3_row = $db->sql_fetchrow($db->sql_query("SELECT user_avatar, user_avatar_type FROM {$table_prefix}users WHERE user_id = {$target_u3_id}"));
		if ($u3_row['user_avatar_type'] !== 'avatar.driver.gravatar' || $u3_row['user_avatar'] !== 'gravatar_user@example.com')
		{
			throw new \Exception("User 3 gravatar data mismatch: " . json_encode($u3_row));
		}

		// Test 4: User 4 Large Fallback
		if ($write_res[9104]['status'] !== 'success')
		{
			throw new \Exception("User 4 large fallback write failed: " . json_encode($write_res[9104]));
		}
		$u4_row = $db->sql_fetchrow($db->sql_query("SELECT user_avatar, user_avatar_type, user_avatar_width, user_avatar_height FROM {$table_prefix}users WHERE user_id = {$target_u4_id}"));
		if ((int)$u4_row['user_avatar_width'] !== 90 || (int)$u4_row['user_avatar_height'] !== 90)
		{
			throw new \Exception("User 4 avatar was not resized from large variant: " . json_encode($u4_row));
		}

		// Test 5: User 5 Existing Avatar Preservation
		$u5_row = $db->sql_fetchrow($db->sql_query("SELECT user_avatar FROM {$table_prefix}users WHERE user_id = {$target_u5_id}"));
		if ($u5_row['user_avatar'] !== 'existing_custom_avatar.png')
		{
			throw new \Exception("CRITICAL VIOLATION: Existing target avatar was overwritten!");
		}

		// Test 6: Protected Target Admin User ID 2
		$av_admin = new avatar_dto();
		$av_admin->user_source_id = 9999;
		$id_mapper->set($run_id, 'xenforo', 'user', 9999, 2); // Map to Admin ID 2
		$av_admin->avatar_type = 'upload';
		$av_admin->source_physical_path = $src_file1;
		$av_admin->extension = 'jpg';

		$res_admin = $writer->write_avatars([$av_admin], ['run_id' => $run_id, 'source_system' => 'xenforo']);
		if ($res_admin[9999]['status'] !== 'skipped')
		{
			throw new \Exception("CRITICAL SECURITY VIOLATION: Admin User ID 2 avatar modification was not skipped!");
		}

		// Cleanup
		@unlink($target_avatar_dir . '/' . $phys_u1);
		@unlink($target_avatar_dir . '/' . $phys_u2);
		$phys_u4 = $avatar_salt !== '' ? "{$avatar_salt}_{$target_u4_id}.jpg" : "{$target_u4_id}.jpg";
		@unlink($target_avatar_dir . '/' . $phys_u4);

		@unlink($src_file1);
		@unlink($src_file2);
		@unlink($src_file4);
		@unlink($src_file5);
		@rmdir($avatar_o_dir);
		@rmdir($avatar_l_dir);
		@rmdir($temp_dir . '/data/avatars/o');
		@rmdir($temp_dir . '/data/avatars/l');
		@rmdir($temp_dir . '/data/avatars');
		@rmdir($temp_dir . '/data');
		@rmdir($temp_dir);

		$test_user_ids = implode(',', [$target_u1_id, $target_u2_id, $target_u3_id, $target_u4_id, $target_u5_id]);
		$db->sql_query("DELETE FROM {$table_prefix}users WHERE user_id IN ({$test_user_ids})");
		$db->sql_query("DELETE FROM {$table_prefix}migration_id_map WHERE run_id = '{$run_id}'");

		return true;
	}
}
