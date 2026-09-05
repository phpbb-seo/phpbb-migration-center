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

		// 1. Check for unsupported vB5 / vB6 tables first (vB5 introduced node table architecture)
		if ($db->table_exists('node'))
		{
			// vB5 / vB6 uses node model
			$vb5_version = null;
			if ($db->table_exists('setting'))
			{
				$vb5_version = $db->fetch_one("SELECT value FROM " . $db->get_table_name('setting') . " WHERE varname = 'templateversion'");
			}
			return [
				'version_string' => $vb5_version ?: '5.x/6.x',
				'major_version'  => 5,
				'variant'        => 'vbulletin_5_unsupported',
				'confidence'     => 'schema_fingerprint_vb5',
				'is_supported'   => false,
				'error'          => 'Unsupported vBulletin version (vBulletin 5.x/6.x Node architecture is not supported).',
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
		else if ($major >= 5)
		{
			return [
				'version_string' => $version_string,
				'major_version'  => $major,
				'variant'        => 'vbulletin_5_unsupported',
				'confidence'     => $confidence,
				'is_supported'   => false,
				'error'          => "Unsupported vBulletin version {$version_string} (vBulletin 5/6 is not supported).",
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
