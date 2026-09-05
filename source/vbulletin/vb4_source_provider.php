<?php
/**
 * phpBB Migration Center Extension
 *
 * @copyright (c) 2026 phpBB SEO Team
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace phpbbseo\migrationcenter\source\vbulletin;

use phpbbseo\migrationcenter\core\dto\migration_config_dto;
use phpbbseo\migrationcenter\core\dto\preflight_result_dto;
use phpbbseo\migrationcenter\source\vbulletin\adapter\vb_db_adapter;
use phpbbseo\migrationcenter\source\vbulletin\version\vb_version_detector;

/**
 * vBulletin 4.2 Dedicated Source Provider
 */
class vb4_source_provider extends vbulletin_source_provider
{
	/**
	 * Get system identifier
	 *
	 * @return string
	 */
	public function get_system_name(): string
	{
		return 'vbulletin4';
	}

	/**
	 * Get human-readable source title
	 *
	 * @return string
	 */
	public function get_title(): string
	{
		return 'vBulletin 4.2.x';
	}

	/**
	 * Run preflight checks with vB4 specific validation
	 *
	 * @param migration_config_dto $config
	 * @return preflight_result_dto
	 */
	public function run_preflight(migration_config_dto $config): preflight_result_dto
	{
		$result = parent::run_preflight($config);
		if (!$result->passed)
		{
			return $result;
		}

		try
		{
			$db = new vb_db_adapter($config);
			$info = vb_version_detector::detect($db, $config->source_path);
			if ((int)($info['major_version'] ?? 0) === 3)
			{
				$result->add_item('vb_version_mismatch', 'vBulletin Target Version', 'warning', "Selected platform is vBulletin 4.2, but detected database version is {$info['version_string']} (vBulletin 3.x). Migration Center will adapt to vBulletin 3 schema automatically.");
			}
		}
		catch (\Throwable $e)
		{
		}

		return $result;
	}
}
