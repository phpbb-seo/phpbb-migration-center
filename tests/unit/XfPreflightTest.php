<?php
/**
 * XenForo Preflight Check Integration Test
 */

namespace phpbbseo\migrationcenter\tests\unit;

use phpbbseo\migrationcenter\source\xenforo\xenforo_source_provider;
use phpbbseo\migrationcenter\source\xenforo\config\xf_config_detector;

class XfPreflightTest
{
	public function run()
	{
		$source_path = 'C:/xampp/htdocs/xen';
		$config = xf_config_detector::detect_from_path($source_path);
		if (!$config)
		{
			throw new \Exception("Could not load XenForo config for preflight test");
		}

		$provider = new xenforo_source_provider('C:/xampp/htdocs/bb/');

		// Test 1: Version detection
		$version = $provider->detect_version($config);
		if (strpos($version, '2.3') !== 0)
		{
			throw new \Exception("Expected version 2.3.x, got: {$version}");
		}

		// Test 2: Run preflight
		$result = $provider->run_preflight($config);
		if (!$result->passed)
		{
			throw new \Exception("Preflight checks failed unexpectedly for local test installation");
		}

		// Test 3: Total records count check
		$user_count = $provider->get_total_records('users', $config);
		if ($user_count <= 0)
		{
			throw new \Exception("Expected user count > 0, got: {$user_count}");
		}

		$thread_count = $provider->get_total_records('topics', $config);
		if ($thread_count <= 0)
		{
			throw new \Exception("Expected thread count > 0, got: {$thread_count}");
		}

		$post_count = $provider->get_total_records('posts', $config);
		if ($post_count <= 0)
		{
			throw new \Exception("Expected post count > 0, got: {$post_count}");
		}

		// Test 4: Keyset batch read check
		$users_batch = $provider->read_batch('users', 0, 5, $config);
		if (count($users_batch) !== 5)
		{
			throw new \Exception("Expected 5 users in first keyset batch, got: " . count($users_batch));
		}

		return true;
	}
}
