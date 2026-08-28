<?php
/**
 * XenForo Config Detector Unit Test
 */

namespace phpbbseo\migrationcenter\tests\unit;

use phpbbseo\migrationcenter\source\xenforo\config\xf_config_detector;

class XfConfigDetectorTest
{
	public function run()
	{
		// 1. Test detection with a mock XenForo directory structure
		$temp_root = sys_get_temp_dir() . '/xf_config_test_' . uniqid();
		$src_dir = $temp_root . '/src';
		@mkdir($src_dir, 0777, true);

		$config_php_content = "<?php\n" .
			"\$config['db']['host'] = 'localhost';\n" .
			"\$config['db']['port'] = 3306;\n" .
			"\$config['db']['username'] = 'xf_user';\n" .
			"\$config['db']['password'] = 'secret_pass';\n" .
			"\$config['db']['dbname'] = 'xen';\n" .
			"\$config['db']['prefix'] = 'xf_';\n";

		file_put_contents($src_dir . '/config.php', $config_php_content);

		$config = xf_config_detector::detect_from_path($temp_root);
		if (!$config)
		{
			throw new \Exception("Failed to detect XenForo config from mock path: {$temp_root}");
		}

		if ($config->source_system !== 'xenforo')
		{
			throw new \Exception("Expected source_system 'xenforo', got: {$config->source_system}");
		}

		if ($config->db_name !== 'xen')
		{
			throw new \Exception("Expected db_name 'xen', got: {$config->db_name}");
		}

		// Ensure to_array(false) does not expose password
		$safe_array = $config->to_array(false);
		if (isset($safe_array['db_password']))
		{
			throw new \Exception("Security violation: db_password exposed in safe array representation!");
		}

		// Clean up mock directory
		@unlink($src_dir . '/config.php');
		@rmdir($src_dir);
		@rmdir($temp_root);

		// Non-existent path returns null
		$null_config = xf_config_detector::detect_from_path('/invalid/path/that/does/not/exist_' . uniqid());
		if ($null_config !== null)
		{
			throw new \Exception("Expected null for non-existent source path");
		}

		return true;
	}
}
