<?php
/**
 * phpBB Migration Center Extension
 *
 * @copyright (c) 2026 phpBB SEO Team
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace phpbbseo\migrationcenter\source\smf\version;

use phpbbseo\migrationcenter\source\smf\adapter\smf_db_adapter;

/**
 * SMF Version Detector
 */
class smf_version_detector
{
	/**
	 * Detect SMF version
	 *
	 * @param smf_db_adapter $db
	 * @param string|null $source_path
	 * @return array ['version_string' => string, 'version_code' => int]
	 */
	public static function detect(smf_db_adapter $db, ?string $source_path = null): array
	{
		$version_string = 'Unknown';
		$version_code = 0;

		// 1. Try reading from filesystem: Settings.php or index.php
		if (!empty($source_path))
		{
			$clean = rtrim(str_replace('\\', '/', $source_path), '/');
			$settings_file = $clean . '/Settings.php';
			if (file_exists($settings_file) && is_readable($settings_file))
			{
				$content = @file_get_contents($settings_file);
				if ($content && preg_match('/@version\s+([0-9\.]+(?:-[a-z0-9\.]+| RC\d+| Beta\d+)?)/i', $content, $m))
				{
					$version_string = trim($m[1]);
				}
			}

			if ($version_string === 'Unknown')
			{
				$index_file = $clean . '/index.php';
				if (file_exists($index_file) && is_readable($index_file))
				{
					$content = @file_get_contents($index_file);
					if ($content && preg_match('/define\(\'SMF_VERSION\',\s*\'([^\']+)\'\)/i', $content, $m))
					{
						$version_string = trim($m[1]);
					}
				}
			}
		}

		// 2. Fallback: query database smf_settings
		if ($version_string === 'Unknown')
		{
			try
			{
				$tbl_settings = $db->get_table_name('settings');
				$stmt = $db->get_pdo()->prepare("SELECT value FROM {$tbl_settings} WHERE variable = 'smfVersion' LIMIT 1");
				$stmt->execute();
				$val = $stmt->fetchColumn();
				if ($val)
				{
					$version_string = trim($val);
				}
			}
			catch (\Throwable $e)
			{
				// Ignore and fallback
			}
		}

		$major = 0;
		if (preg_match('/^([0-9]+)\.([0-9]+)(?:\.([0-9]+))?/', $version_string, $matches))
		{
			$major = (int)$matches[1];
			$minor = (int)$matches[2];
			$patch = isset($matches[3]) ? (int)$matches[3] : 0;
			$version_code = ($major * 10000) + ($minor * 100) + $patch;
		}

		$is_supported = ($major === 2);

		return [
			'version_string' => $version_string,
			'version_code'   => $version_code,
			'major_version'  => $major,
			'is_supported'   => $is_supported,
			'confidence'     => ($version_string !== 'Unknown') ? 'high' : 'none',
			'error'          => $is_supported ? null : "SMF version '{$version_string}' is not supported. Supported versions: SMF 2.0.x - 2.1.x.",
		];
	}
}
