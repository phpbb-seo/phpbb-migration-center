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
use PDO;

/**
 * Unit Test for vBulletin Database Adapter & Read-Only Enforcement
 */
class vb_db_adapter_test
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

		$cfg = new migration_config_dto();
		$cfg->db_host = '127.0.0.1';
		$cfg->db_port = 3307;
		$cfg->db_name = 'vb3_test';
		$cfg->db_user = 'migration_vb3_readonly';
		$cfg->db_password = $env['VB3_DB_PASSWORD'] ?? 'vb3_lab_secret_pass_2026';

		$db = new vb_db_adapter($cfg);

		// 1. Test SELECT query
		$cnt = (int)$db->fetch_one("SELECT COUNT(*) FROM user");
		$results['select_succeeds'] = ($cnt === 100);

		// 2. Test Parameterized Query
		$username = (string)$db->fetch_one("SELECT username FROM user WHERE userid = :id", [':id' => 1]);
		$results['parameterized_query_succeeds'] = ($username === 'admin');

		// 3. Test Adapter Guard against INSERT
		$insert_blocked = false;
		try {
			$db->query("INSERT INTO user (username) VALUES ('test')");
		} catch (\InvalidArgumentException $e) {
			$insert_blocked = true;
		}
		$results['adapter_guard_insert_blocked'] = $insert_blocked;

		// 4. Test Adapter Guard against UPDATE
		$update_blocked = false;
		try {
			$db->query("UPDATE user SET username = 'hacked' WHERE userid = 1");
		} catch (\InvalidArgumentException $e) {
			$update_blocked = true;
		}
		$results['adapter_guard_update_blocked'] = $update_blocked;

		// 5. Test Adapter Guard against DELETE
		$delete_blocked = false;
		try {
			$db->query("DELETE FROM user WHERE userid = 1");
		} catch (\InvalidArgumentException $e) {
			$delete_blocked = true;
		}
		$results['adapter_guard_delete_blocked'] = $delete_blocked;

		// 6. Test Adapter Guard against DROP
		$drop_blocked = false;
		try {
			$db->query("DROP TABLE user");
		} catch (\InvalidArgumentException $e) {
			$drop_blocked = true;
		}
		$results['adapter_guard_drop_blocked'] = $drop_blocked;

		// 7. MySQL Permission-Level Tests using Raw PDO with migration_vb3_readonly (Bypassing Adapter Guard)
		$raw_pdo = $db->get_pdo();

		// MySQL Level: INSERT fails
		$mysql_insert_blocked = false;
		try {
			$raw_pdo->exec("INSERT INTO user (username) VALUES ('test_bypass')");
		} catch (\PDOException $e) {
			$mysql_insert_blocked = true;
		}
		$results['mysql_level_insert_blocked'] = $mysql_insert_blocked;

		// MySQL Level: UPDATE fails
		$mysql_update_blocked = false;
		try {
			$raw_pdo->exec("UPDATE user SET username = 'test_bypass' WHERE userid = 1");
		} catch (\PDOException $e) {
			$mysql_update_blocked = true;
		}
		$results['mysql_level_update_blocked'] = $mysql_update_blocked;

		// MySQL Level: DELETE fails
		$mysql_delete_blocked = false;
		try {
			$raw_pdo->exec("DELETE FROM user WHERE userid = 1");
		} catch (\PDOException $e) {
			$mysql_delete_blocked = true;
		}
		$results['mysql_level_delete_blocked'] = $mysql_delete_blocked;

		// MySQL Level: DROP fails
		$mysql_drop_blocked = false;
		try {
			$raw_pdo->exec("DROP TABLE user");
		} catch (\PDOException $e) {
			$mysql_drop_blocked = true;
		}
		$results['mysql_level_drop_blocked'] = $mysql_drop_blocked;

		// MySQL Level: CREATE fails
		$mysql_create_blocked = false;
		try {
			$raw_pdo->exec("CREATE TABLE test_table (id INT)");
		} catch (\PDOException $e) {
			$mysql_create_blocked = true;
		}
		$results['mysql_level_create_blocked'] = $mysql_create_blocked;

		// MySQL Level: ALTER fails
		$mysql_alter_blocked = false;
		try {
			$raw_pdo->exec("ALTER TABLE user ADD COLUMN test_col INT");
		} catch (\PDOException $e) {
			$mysql_alter_blocked = true;
		}
		$results['mysql_level_alter_blocked'] = $mysql_alter_blocked;

		// MySQL Level: SELECT INTO OUTFILE fails
		$mysql_outfile_blocked = false;
		try {
			$raw_pdo->exec("SELECT * INTO OUTFILE '/tmp/evil.txt' FROM user");
		} catch (\PDOException $e) {
			$mysql_outfile_blocked = true;
		}
		$results['mysql_level_into_outfile_blocked'] = $mysql_outfile_blocked;

		return $results;
	}
}
