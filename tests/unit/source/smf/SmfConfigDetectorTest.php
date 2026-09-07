<?php
/**
 * phpBB Migration Center Extension
 *
 * @copyright (c) 2026 phpBB SEO Team
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace phpbbseo\migrationcenter\tests\unit\source\smf;

use phpbbseo\migrationcenter\source\smf\config\smf_config_detector;

/**
 * Unit Test for SMF Configuration Detector
 */
class SmfConfigDetectorTest
{
	public function run(): array
	{
		$results = [];

		// Create a temporary mock SMF directory structure
		$temp_dir = sys_get_temp_dir() . '/smf_test_' . uniqid();
		mkdir($temp_dir, 0777, true);

		$sample_settings = '<?php
$db_type = \'mysql\';
$db_server = \'127.0.0.1:3308\';
$db_name = \'test_smf_db\';
$db_user = \'smf_user\';
$db_passwd = \'secret_pass\';
$db_prefix = \'custom_smf_\';
$db_mb4 = true;
$boardurl = \'http://localhost/smf\';
';

		file_put_contents($temp_dir . '/Settings.php', $sample_settings);

		$dto = smf_config_detector::detect_from_path($temp_dir);

		$results['dto_not_null'] = ($dto !== null);
		if ($dto !== null)
		{
			$results['db_name_parsed'] = ($dto->db_name === 'test_smf_db');
			$results['db_user_parsed'] = ($dto->db_user === 'smf_user');
			$results['db_password_parsed'] = ($dto->db_password === 'secret_pass');
			$results['db_prefix_parsed'] = ($dto->db_prefix === 'custom_smf_');
			$results['db_port_parsed'] = ($dto->db_port === 3308);
			$results['db_charset_parsed'] = ($dto->db_charset === 'utf8mb4');
			$results['source_system_is_smf'] = ($dto->source_system === 'smf');
		}

		// Cleanup
		unlink($temp_dir . '/Settings.php');
		rmdir($temp_dir);

		// Non-existent directory returns null
		$results['non_existent_returns_null'] = (smf_config_detector::detect_from_path('/non/existent/path') === null);

		return $results;
	}
}
