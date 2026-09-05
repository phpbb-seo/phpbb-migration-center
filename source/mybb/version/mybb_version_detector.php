<?php
/**
 * phpBB Migration Center Extension
 *
 * @copyright (c) 2026 phpBB SEO Team
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace phpbbseo\migrationcenter\source\mybb\version;

use phpbbseo\migrationcenter\source\mybb\adapter\mybb_db_adapter;

/**
 * MyBB Version Detector
 */
class mybb_version_detector
{
	/**
	 * Detect MyBB version
	 *
	 * @param mybb_db_adapter $db
	 * @param string|null $source_path
	 * @return array ['version_string' => string, 'version_code' => int]
	 */
	public static function detect(mybb_db_adapter $db, ?string $source_path = null): array
	{
		$version_string = 'Unknown';
		$version_code = 0;

		// 1. Try reading from filesystem: inc/class_core.php
		if (!empty($source_path))
		{
			$clean = rtrim(str_replace('\\', '/', $source_path), '/');
			$core_file = $clean . '/inc/class_core.php';
			if (file_exists($core_file) && is_readable($core_file))
			{
				$content = @file_get_contents($core_file);
				if ($content)
				{
					if (preg_match('/public\s+\$version\s*=\s*["\']([^"\']+)["\']/i', $content, $m))
					{
						$version_string = trim($m[1]);
					}
					if (preg_match('/public\s+\$version_code\s*=\s*([0-9]+)/i', $content, $m))
					{
						$version_code = (int)$m[1];
					}
				}
			}
		}

		// 2. Fallback: query database settings or datacache
		if ($version_string === 'Unknown')
		{
			try
			{
				$tbl_settings = $db->get_table_name('settings');
				$stmt = $db->get_pdo()->prepare("SELECT value FROM {$tbl_settings} WHERE name = 'version' LIMIT 1");
				$stmt->execute();
				$val = $stmt->fetchColumn();
				if ($val)
				{
					$version_string = trim((string)$val);
				}
			}
			catch (\Throwable $e)
			{
				// Ignore
			}
		}

		if ($version_string === 'Unknown')
		{
			try
			{
				$tbl_datacache = $db->get_table_name('datacache');
				$stmt = $db->get_pdo()->prepare("SELECT cache FROM {$tbl_datacache} WHERE title = 'version' LIMIT 1");
				$stmt->execute();
				$cache = $stmt->fetchColumn();
				if ($cache)
				{
					$unserialized = @unserialize($cache);
					if (is_array($unserialized) && !empty($unserialized['version']))
					{
						$version_string = trim((string)$unserialized['version']);
						if (!empty($unserialized['version_code']))
						{
							$version_code = (int)$unserialized['version_code'];
						}
					}
				}
			}
			catch (\Throwable $e)
			{
				// Ignore
			}
		}

		$is_supported = ($version_string !== 'Unknown' || $version_code > 0);
		if ($version_string === 'Unknown')
		{
			$version_string = '1.8.x';
			$is_supported = true;
		}

		return [
			'version_string' => $version_string,
			'version_code'   => $version_code,
			'is_supported'   => $is_supported,
			'confidence'     => ($version_code > 0 ? 'high' : 'medium'),
		];
	}
}
