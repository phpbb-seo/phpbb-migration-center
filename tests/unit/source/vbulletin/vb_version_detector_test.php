<?php
/**
 * phpBB Migration Center Extension
 *
 * @copyright (c) 2026 phpBB SEO Team
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace phpbbseo\migrationcenter\tests\unit\source\vbulletin;

use phpbbseo\migrationcenter\core\dto\migration_config_dto;
use phpbbseo\migrationcenter\source\vbulletin\adapter\vb_db_adapter;
use phpbbseo\migrationcenter\source\vbulletin\version\vb_version_detector;

/**
 * Unit Test for vBulletin Version Detector
 */
class vb_version_detector_test
{
	public function run(): array
	{
		$results = [];

		$env_lines = file('C:/vb-migration-lab/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
		$env = [];
		foreach ($env_lines as $l) {
			if (strpos(trim($l), '#') === 0 || strpos($l, '=') === false) continue;
			list($k, $v) = explode('=', $l, 2);
			$env[trim($k)] = trim($v);
		}

		// 1. Test real vB3.8.11 detection
		$cfg3 = new migration_config_dto();
		$cfg3->db_host = '127.0.0.1';
		$cfg3->db_port = 3307;
		$cfg3->db_name = 'vb3_test';
		$cfg3->db_user = 'migration_vb3_readonly';
		$cfg3->db_password = $env['VB3_DB_PASSWORD'] ?? 'vb3_lab_secret_pass_2026';
		$cfg3->source_path = 'C:/vb-migration-lab/vb3';

		$db3 = new vb_db_adapter($cfg3);
		$v3 = vb_version_detector::detect($db3, $cfg3->source_path);

		$results['vb3_version_exact'] = ($v3['version_string'] === '3.8.11');
		$results['vb3_major_version'] = ($v3['major_version'] === 3);
		$results['vb3_variant_name']  = ($v3['variant'] === 'vbulletin_3');
		$results['vb3_is_supported']  = ($v3['is_supported'] === true);

		// 2. Test real vB4.2.5 detection
		$cfg4 = new migration_config_dto();
		$cfg4->db_host = '127.0.0.1';
		$cfg4->db_port = 3308;
		$cfg4->db_name = 'vb4_test';
		$cfg4->db_user = 'migration_vb4_readonly';
		$cfg4->db_password = $env['VB4_DB_PASSWORD'] ?? 'vb4_lab_secret_pass_2026';
		$cfg4->source_path = 'C:/vb-migration-lab/vb4';

		$db4 = new vb_db_adapter($cfg4);
		$v4 = vb_version_detector::detect($db4, $cfg4->source_path);

		$results['vb4_version_exact'] = ($v4['version_string'] === '4.2.5');
		$results['vb4_major_version'] = ($v4['major_version'] === 4);
		$results['vb4_variant_name']  = ($v4['variant'] === 'vbulletin_4');
		$results['vb4_is_supported']  = ($v4['is_supported'] === true);

		return $results;
	}
}
