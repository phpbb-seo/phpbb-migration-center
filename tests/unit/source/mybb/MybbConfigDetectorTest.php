<?php
/**
 * phpBB Migration Center Extension
 *
 * @copyright (c) 2026 phpBB SEO Team
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace phpbbseo\migrationcenter\tests\unit\source\mybb;

use phpbbseo\migrationcenter\source\mybb\config\mybb_config_detector;

/**
 * Unit Test for MyBB Configuration Detector
 */
class MybbConfigDetectorTest
{
	public function run(): array
	{
		$results = [];

		// Create a temporary mock MyBB directory structure
		$temp_dir = sys_get_temp_dir() . '/mybb_test_' . uniqid();
		mkdir($temp_dir . '/inc', 0777, true);

		$sample_config = '<?php
$config[\'database\'][\'type\'] = \'mysqli\';
$config[\'database\'][\'database\'] = \'test_mybb_db\';
$config[\'database\'][\'table_prefix\'] = \'custom_\';
$config[\'database\'][\'hostname\'] = \'localhost:3307\';
$config[\'database\'][\'username\'] = \'mybb_user\';
$config[\'database\'][\'password\'] = \'secret_pass\';
';

		file_put_contents($temp_dir . '/inc/config.php', $sample_config);

		$dto = mybb_config_detector::detect_from_path($temp_dir);

		$results['dto_not_null'] = ($dto !== null);
		if ($dto !== null) {
			$results['db_name_parsed'] = ($dto->db_name === 'test_mybb_db');
			$results['db_user_parsed'] = ($dto->db_user === 'mybb_user');
			$results['db_password_parsed'] = ($dto->db_password === 'secret_pass');
			$results['db_prefix_parsed'] = ($dto->db_prefix === 'custom_');
			$results['db_port_parsed'] = ($dto->db_port === 3307);
			$results['source_system_is_mybb'] = ($dto->source_system === 'mybb');
		}

		// Cleanup
		unlink($temp_dir . '/inc/config.php');
		rmdir($temp_dir . '/inc');
		rmdir($temp_dir);

		// Non-existent directory returns null
		$results['non_existent_returns_null'] = (mybb_config_detector::detect_from_path('/non/existent/path') === null);

		return $results;
	}
}
