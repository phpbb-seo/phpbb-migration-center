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
		$source_path = 'C:/xampp/htdocs/xen';

		$config = xf_config_detector::detect_from_path($source_path);
		if (!$config)
		{
			throw new \Exception("Failed to detect XenForo config from: {$source_path}");
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

		// Non-existent path returns null
		$null_config = xf_config_detector::detect_from_path('C:/invalid/path/that/does/not/exist');
		if ($null_config !== null)
		{
			throw new \Exception("Expected null for non-existent source path");
		}

		return true;
	}
}
