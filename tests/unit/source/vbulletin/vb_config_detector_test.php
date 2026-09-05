<?php
/**
 * phpBB Migration Center Extension
 *
 * @copyright (c) 2026 phpBB SEO Team
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace phpbbseo\migrationcenter\tests\unit\source\vbulletin;

use phpbbseo\migrationcenter\core\dto\migration_config_dto;
use phpbbseo\migrationcenter\source\vbulletin\config\vb_config_detector;
use phpbbseo\migrationcenter\source\vbulletin\adapter\vb_db_adapter;

/**
 * Unit Test for vBulletin Configuration Detector & Credential Containment
 */
class vb_config_detector_test
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

		// 1. Detect vB 3.8.11 real config
		$cfg3 = vb_config_detector::detect_from_path('C:/vb-migration-lab/vb3');
		$results['vb3_config_detected'] = ($cfg3 !== null && $cfg3->db_name === 'vb3_test' && $cfg3->db_port === 3306);

		// 2. Detect vB 4.2.5 real config
		$cfg4 = vb_config_detector::detect_from_path('C:/vb-migration-lab/vb4');
		$results['vb4_config_detected'] = ($cfg4 !== null && $cfg4->db_name === 'vb4_test' && $cfg4->db_port === 3306);

		// 3. Reject null bytes
		$null_cfg = vb_config_detector::detect_from_path("C:/vb-migration-lab/vb3\0/evil");
		$results['reject_null_bytes'] = ($null_cfg === null);

		// 4. Reject invalid path
		$inv_cfg = vb_config_detector::detect_from_path('C:/non_existent_path_xyz_123');
		$results['reject_invalid_path'] = ($inv_cfg === null);

		// 5. Internal Connection Can Authenticate
		$conn_cfg = new migration_config_dto();
		$conn_cfg->db_host = '127.0.0.1';
		$conn_cfg->db_port = 3307;
		$conn_cfg->db_name = 'vb3_test';
		$conn_cfg->db_user = 'migration_vb3_readonly';
		$conn_cfg->db_password = $env['VB3_DB_PASSWORD'] ?? 'vb3_lab_secret_pass_2026';
		$db = new vb_db_adapter($conn_cfg);
		$results['internal_connection_can_authenticate'] = ($db->fetch_one("SELECT 1") == 1);

		// 6. Password Redacted from Public Array Serialization
		$arr = $conn_cfg->to_array(false);
		$results['password_redacted_from_public_array'] = !isset($arr['db_password']);

		// 7. Password Redacted from JSON Encoding
		$json = json_encode($conn_cfg);
		$results['password_redacted_from_json'] = (strpos($json, 'db_password') === false && strpos($json, $conn_cfg->db_password) === false);

		// 8. Password Redacted from Exception Messages
		$bad_cfg = new migration_config_dto();
		$bad_cfg->db_host = '127.0.0.1';
		$bad_cfg->db_port = 3307;
		$bad_cfg->db_name = 'vb3_test';
		$bad_cfg->db_user = 'non_existent_user';
		$bad_cfg->db_password = 'super_secret_forbidden_pass_12345';
		$exc_message = '';
		try {
			new vb_db_adapter($bad_cfg);
		} catch (\Throwable $e) {
			$exc_message = $e->getMessage();
		}
		$results['password_redacted_from_exception'] = (strpos($exc_message, 'super_secret_forbidden_pass_12345') === false);

		// 9. Password Redacted from Debug Info / var_dump
		ob_start();
		var_dump($conn_cfg);
		$dump_out = ob_get_clean();
		$results['password_redacted_from_var_dump'] = (strpos($dump_out, $conn_cfg->db_password) === false);

		return $results;
	}
}
