<?php
/**
 * phpBB Migration Center Extension
 *
 * @copyright (c) 2026 phpBB SEO Team
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace phpbbseo\migrationcenter\source\xenforo\version;

use phpbbseo\migrationcenter\source\xenforo\adapter\xf_db_adapter;

/**
 * XenForo Version Detector
 */
class xf_version_detector
{
	/**
	 * Detect XenForo version string from database and filesystem
	 *
	 * @param xf_db_adapter $db
	 * @param string $source_path
	 * @return array ['version_string' => string, 'version_id' => int, 'adapter_class' => string]
	 */
	public static function detect(xf_db_adapter $db, string $source_path = ''): array
	{
		$version_id = 0;
		$version_string = '';

		// 1. Try xf_option currentVersionId
		try
		{
			$val = $db->fetch_one("SELECT option_value FROM xf_option WHERE option_id = 'currentVersionId'");
			if ($val)
			{
				$version_id = (int)$val;
			}
		}
		catch (\Throwable $e)
		{
			// Table might not exist or connection error
		}

		// 2. Try source path XF.php if available
		if (!empty($source_path))
		{
			$xf_php = rtrim(str_replace('\\', '/', $source_path), '/') . '/src/XF.php';
			if (file_exists($xf_php) && is_readable($xf_php))
			{
				$content = file_get_contents($xf_php, false, null, 0, 4096);
				if (preg_match('/\$version\s*=\s*\'([^\']+)\'/', $content, $m))
				{
					$version_string = $m[1];
				}
				if (preg_match('/\$versionId\s*=\s*([0-9]+)/', $content, $m))
				{
					if (!$version_id)
					{
						$version_id = (int)$m[1];
					}
				}
			}
		}

		// Determine adapter class and formatted version string
		if ($version_id >= 2030000 || strpos($version_string, '2.3') === 0)
		{
			return [
				'version_string' => $version_string ?: '2.3.x',
				'version_id'     => $version_id,
				'major_minor'    => '2.3',
				'adapter_class'  => xf23_adapter::class,
			];
		}
		else if ($version_id >= 2020000 || strpos($version_string, '2.2') === 0)
		{
			return [
				'version_string' => $version_string ?: '2.2.x',
				'version_id'     => $version_id,
				'major_minor'    => '2.2',
				'adapter_class'  => xf22_adapter::class,
			];
		}
		else if ($version_id >= 2010000 || strpos($version_string, '2.1') === 0)
		{
			return [
				'version_string' => $version_string ?: '2.1.x',
				'version_id'     => $version_id,
				'major_minor'    => '2.1',
				'adapter_class'  => xf21_adapter::class,
			];
		}
		else if ($version_id >= 2000000 || strpos($version_string, '2.0') === 0)
		{
			return [
				'version_string' => $version_string ?: '2.0.x',
				'version_id'     => $version_id,
				'major_minor'    => '2.0',
				'adapter_class'  => xf20_adapter::class,
			];
		}

		return [
			'version_string' => $version_string ?: 'Unknown',
			'version_id'     => $version_id,
			'major_minor'    => 'Unknown',
			'adapter_class'  => xf_base_adapter::class,
		];
	}
}
