<?php
/**
 * phpBB Migration Center Extension
 *
 * @copyright (c) 2026 phpBB SEO Team
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace phpbbseo\migrationcenter\source\vbulletin\version;

use phpbbseo\migrationcenter\source\vbulletin\adapter\vb_db_adapter;

/**
 * vBulletin Version Detector
 */
class vb_version_detector
{
	/**
	 * Detect exact vBulletin version and variant
	 *
	 * @param vb_db_adapter $db
	 * @param string $source_path
	 * @return array
	 */
	public static function detect(vb_db_adapter $db, string $source_path = ''): array
	{
		$version_string = null;
		$confidence = 'none';

		// 1. Check for vB5 / vB6 tables first (vB5 introduced node table architecture)
		if ($db->table_exists('node'))
		{
			$vb_version = null;
			if ($db->table_exists('setting'))
			{
				$setting_tbl = $db->get_table_name('setting');
				$vb_version = $db->fetch_one("SELECT value FROM {$setting_tbl} WHERE varname = 'templateversion'");
				if (empty($vb_version))
				{
					$vb_version = $db->fetch_one("SELECT value FROM {$setting_tbl} WHERE varname = 'version'");
				}
			}

			if (empty($vb_version) && !empty($source_path) && is_dir($source_path))
			{
				$candidates = [
					rtrim(str_replace('\\', '/', $source_path), '/') . '/core/includes/version_vbulletin.php',
					rtrim(str_replace('\\', '/', $source_path), '/') . '/includes/version_vbulletin.php',
				];
				foreach ($candidates as $cand)
				{
					if (file_exists($cand) && is_readable($cand))
					{
						$c = file_get_contents($cand);
						if (preg_match('/vBulletin\s+([0-9]+\.[0-9]+(?:\.[0-9]+)?)/i', $c, $m))
						{
							$vb_version = trim($m[1]);
							break;
						}
					}
				}
			}

			$version_str = trim($vb_version ?: '6.0.0');
			$major = (int)substr($version_str, 0, 1);
			if ($major < 5)
			{
				$major = 6;
			}

			return [
				'version_string' => $version_str,
				'major_version'  => $major,
				'variant'        => ($major === 6) ? 'vbulletin_6' : 'vbulletin_5',
				'confidence'     => 'node_schema_detected',
				'is_supported'   => true,
				'error'          => null,
			];
		}

		// 2. Query database templateversion setting
		if ($db->table_exists('setting'))
		{
			try
			{
				$setting_tbl = $db->get_table_name('setting');
				$val = $db->fetch_one("SELECT value FROM {$setting_tbl} WHERE varname = 'templateversion'");
				if (!empty($val))
				{
					$version_string = trim($val);
					$confidence = 'database_setting';
				}
			}
			catch (\Throwable $e)
			{
				// Ignore and proceed to fallback
			}
		}

		// 3. Fallback: Check file system version constant if path provided
		if (empty($version_string) && !empty($source_path) && is_dir($source_path))
		{
			$vf = rtrim(str_replace('\\', '/', $source_path), '/') . '/includes/version_vbulletin.php';
			if (file_exists($vf) && is_readable($vf))
			{
				$c = file_get_contents($vf);
				if (preg_match('/define\s*\(\s*[\'"]FILE_VERSION_VBULLETIN[\'"]\s*,\s*[\'"]([^\'"]+)[\'"]\s*\)/i', $c, $m))
				{
					$version_string = trim($m[1]);
					$confidence = 'file_constant';
				}
			}
		}

		// 4. Fallback: Schema Fingerprint
		$has_filedata = $db->table_exists('filedata');
		$has_prefix = $db->table_exists('prefix');

		if (empty($version_string))
		{
			if ($has_filedata || $has_prefix)
			{
				$version_string = '4.2.x';
				$confidence = 'schema_fingerprint';
			}
			else if ($db->table_exists('attachment') && $db->table_exists('thread'))
			{
				$version_string = '3.8.x';
				$confidence = 'schema_fingerprint';
			}
			else
			{
				return [
					'version_string' => 'Unknown',
					'major_version'  => 0,
					'variant'        => 'unknown',
					'confidence'     => 'none',
					'is_supported'   => false,
					'error'          => 'Could not detect vBulletin version or core tables are missing.',
				];
			}
		}

		// Parse major version
		$major = (int)substr($version_string, 0, 1);

		if ($major === 3)
		{
			// Verify schema consistency
			if ($has_filedata)
			{
				return [
					'version_string' => $version_string,
					'major_version'  => 3,
					'variant'        => 'vbulletin_3',
					'confidence'     => $confidence,
					'is_supported'   => false,
					'error'          => 'Schema mismatch: database reports vBulletin 3.x but contains vBulletin 4.x filedata table.',
				];
			}

			return [
				'version_string' => $version_string,
				'major_version'  => 3,
				'variant'        => 'vbulletin_3',
				'confidence'     => $confidence,
				'is_supported'   => true,
				'error'          => null,
			];
		}
		else if ($major === 4)
		{
			return [
				'version_string' => $version_string,
				'major_version'  => 4,
				'variant'        => 'vbulletin_4',
				'confidence'     => $confidence,
				'is_supported'   => true,
				'error'          => null,
			];
		}
		else if ($major === 5 || $major === 6)
		{
			return [
				'version_string' => $version_string,
				'major_version'  => $major,
				'variant'        => ($major === 6) ? 'vbulletin_6' : 'vbulletin_5',
				'confidence'     => $confidence,
				'is_supported'   => true,
				'error'          => null,
			];
		}

		return [
			'version_string' => $version_string,
			'major_version'  => $major,
			'variant'        => 'unknown',
			'confidence'     => $confidence,
			'is_supported'   => false,
			'error'          => "Unrecognized vBulletin version {$version_string}.",
		];
	}
}
