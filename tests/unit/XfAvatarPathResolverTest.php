<?php
/**
 * XenForo Avatar Path Resolver, Size Fallback & Containment Unit Test
 */

namespace phpbbseo\migrationcenter\tests\unit;

use phpbbseo\migrationcenter\source\xenforo\storage\xf_avatar_path_resolver;
use phpbbseo\migrationcenter\source\xenforo\normalizer\xf_avatar_normalizer;
use phpbbseo\migrationcenter\core\dto\migration_config_dto;

class XfAvatarPathResolverTest
{
	public function run()
	{
		$temp_root = sys_get_temp_dir() . '/xf_avatar_test_' . uniqid();
		$orig_dir = $temp_root . '/data/avatars/o/0';
		$large_dir = $temp_root . '/data/avatars/l/0';
		$medium_dir = $temp_root . '/data/avatars/m/0';
		$small_dir = $temp_root . '/data/avatars/s/0';
		$evil_dir = $temp_root . '/data/avatars_evil/0';
		$src_dir = $temp_root . '/src';

		@mkdir($orig_dir, 0777, true);
		@mkdir($large_dir, 0777, true);
		@mkdir($medium_dir, 0777, true);
		@mkdir($small_dir, 0777, true);
		@mkdir($evil_dir, 0777, true);
		@mkdir($src_dir, 0777, true);

		// Sensitive files under XenForo root
		file_put_contents($src_dir . '/config.php', "<?php \$config['db']['password'] = 'SECRET';");
		file_put_contents($evil_dir . '/99.jpg', "EVIL_OUTSIDE_AVATARS_DATA");

		// Valid size fixtures
		$orig_file = $orig_dir . '/10.jpg';
		file_put_contents($orig_file, "ORIGINAL_AVATAR_BYTES");

		// User 20: Only large size available
		$large_file = $large_dir . '/20.jpg';
		file_put_contents($large_file, "LARGE_AVATAR_BYTES");

		// User 30: Only medium size available
		$med_file = $medium_dir . '/30.jpg';
		file_put_contents($med_file, "MEDIUM_AVATAR_BYTES");

		// User 40: Only small size available
		$small_file = $small_dir . '/40.jpg';
		file_put_contents($small_file, "SMALL_AVATAR_BYTES");

		// User 50: PNG extension
		$png_file = $orig_dir . '/50.png';
		file_put_contents($png_file, "PNG_AVATAR_BYTES");

		// Test 1: Original size selection
		$res1 = xf_avatar_path_resolver::resolve_path($temp_root, 10);
		if (!$res1 || $res1['size'] !== 'o' || $res1['extension'] !== 'jpg')
		{
			throw new \Exception("Original avatar resolution failed. Got: " . var_export($res1, true));
		}

		// Test 2: Large fallback when original missing
		$res2 = xf_avatar_path_resolver::resolve_path($temp_root, 20);
		if (!$res2 || $res2['size'] !== 'l')
		{
			throw new \Exception("Large avatar fallback failed. Got: " . var_export($res2, true));
		}

		// Test 3: Medium fallback
		$res3 = xf_avatar_path_resolver::resolve_path($temp_root, 30);
		if (!$res3 || $res3['size'] !== 'm')
		{
			throw new \Exception("Medium avatar fallback failed. Got: " . var_export($res3, true));
		}

		// Test 4: Small fallback
		$res4 = xf_avatar_path_resolver::resolve_path($temp_root, 40);
		if (!$res4 || $res4['size'] !== 's')
		{
			throw new \Exception("Small avatar fallback failed. Got: " . var_export($res4, true));
		}

		// Test 5: PNG format resolution
		$res5 = xf_avatar_path_resolver::resolve_path($temp_root, 50);
		if (!$res5 || $res5['extension'] !== 'png')
		{
			throw new \Exception("PNG avatar resolution failed. Got: " . var_export($res5, true));
		}

		// Test 6: Sensitive file reading prevention
		$res_leak = xf_avatar_path_resolver::resolve_path($temp_root, 0);
		if ($res_leak !== null)
		{
			throw new \Exception("CRITICAL SECURITY VIOLATION: Non-existent user resolved a file!");
		}

		// Test 7: Prefix confusion rejection
		$res_evil = xf_avatar_path_resolver::resolve_path($temp_root, 99);
		if ($res_evil !== null)
		{
			throw new \Exception("CRITICAL SECURITY VIOLATION: avatars_evil was accepted!");
		}

		// Test 8: Normalizer Gravatar
		$normalizer = new xf_avatar_normalizer();
		$cfg = new migration_config_dto();
		$cfg->source_path = $temp_root;

		$gravatar_row = [
			'user_id'     => 60,
			'avatar_date' => 0,
			'gravatar'    => 'user@example.com',
		];
		$dto_g = $normalizer->normalize_avatar($gravatar_row, $cfg);
		if ($dto_g->avatar_type !== 'gravatar' || $dto_g->gravatar_email !== 'user@example.com')
		{
			throw new \Exception("Normalizer failed for Gravatar user");
		}

		// Test 9: Normalizer Default (No avatar)
		$default_row = [
			'user_id'     => 70,
			'avatar_date' => 0,
			'gravatar'    => '',
		];
		$dto_d = $normalizer->normalize_avatar($default_row, $cfg);
		if ($dto_d->avatar_type !== 'default')
		{
			throw new \Exception("Normalizer failed for default user");
		}

		// Cleanup
		@unlink($orig_file);
		@unlink($large_file);
		@unlink($med_file);
		@unlink($small_file);
		@unlink($png_file);
		@unlink($evil_dir . '/99.jpg');
		@unlink($src_dir . '/config.php');
		@rmdir($orig_dir);
		@rmdir($large_dir);
		@rmdir($medium_dir);
		@rmdir($small_dir);
		@rmdir($evil_dir);
		@rmdir($src_dir);
		@rmdir($temp_root . '/data/avatars/o');
		@rmdir($temp_root . '/data/avatars/l');
		@rmdir($temp_root . '/data/avatars/m');
		@rmdir($temp_root . '/data/avatars/s');
		@rmdir($temp_root . '/data/avatars');
		@rmdir($temp_root . '/data/avatars_evil');
		@rmdir($temp_root . '/data');
		@rmdir($temp_root);

		return true;
	}
}
