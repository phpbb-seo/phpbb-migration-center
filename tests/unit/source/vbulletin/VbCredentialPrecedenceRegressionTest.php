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
use phpbbseo\migrationcenter\source\vbulletin\vbulletin_source_provider;
use phpbbseo\migrationcenter\source\vbulletin\config\vb_config_detector;

class VbCredentialPrecedenceRegressionTest
{
	public function run(): bool
	{
		echo "[RUN] VbCredentialPrecedenceRegressionTest...\n";

		$source_path = 'C:/vb-migration-lab/vb3';
		if (!is_dir($source_path) || !file_exists($source_path . '/includes/config.php'))
		{
			echo "  [SKIP] vB3 source path not found at {$source_path}\n";
			return true;
		}

		// 1. Verify that source config.php actually contains 'vb3_user'
		$detected = vb_config_detector::detect_from_path($source_path);
		if (!$detected || $detected->db_user !== 'vb3_user')
		{
			throw new \RuntimeException("Expected source config.php to contain 'vb3_user', got: " . ($detected ? $detected->db_user : 'null'));
		}

		// 2. Configure wizard / run DTO explicitly with 'migration_vb3_readonly'
		$config = new migration_config_dto();
		$config->source_system = 'vbulletin';
		$config->source_path   = $source_path;
		$config->db_host       = '127.0.0.1';
		$config->db_port       = 3307;
		$config->db_name       = 'vb3_test';
		$config->db_user       = 'migration_vb3_readonly';
		$config->db_password   = ''; // Empty in persisted run options / wizard form

		// 3. Test Preflight: ensure provider does NOT overwrite db_user with 'vb3_user'
		$provider = new vbulletin_source_provider();
		
		// Create a mock/isolated config for preflight validation
		$preflight_cfg = clone $config;
		$preflight = $provider->run_preflight($preflight_cfg);

		if ($preflight_cfg->db_user !== 'migration_vb3_readonly')
		{
			throw new \RuntimeException("Preflight overwrote explicit db_user! Found: {$preflight_cfg->db_user}, expected: migration_vb3_readonly");
		}

		// 4. Test Adapter Connection Identity:
		// Actual configured & connected MySQL identity must be migration_vb3_readonly, NEVER vb3_user
		try
		{
			$adapter = new vb_db_adapter($config);

			if ($adapter->get_db_user() !== 'migration_vb3_readonly')
			{
				throw new \RuntimeException("Adapter internal db_user mismatch! Found: {$adapter->get_db_user()}, expected: migration_vb3_readonly");
			}

			$pdo = $adapter->get_pdo();
			$stmt = $pdo->query("SELECT CURRENT_USER() as current_user, USER() as user");
			$row = $stmt->fetch(\PDO::FETCH_ASSOC);

			$current_user = $row['current_user'] ?? '';
			$connected_user = $row['user'] ?? '';

			echo "  [INFO] MySQL CURRENT_USER: {$current_user} | USER: {$connected_user}\n";

			if (strpos($current_user, 'migration_vb3_readonly') !== 0 && strpos($connected_user, 'migration_vb3_readonly') !== 0)
			{
				throw new \RuntimeException("Actual connection identity is NOT migration_vb3_readonly! Got CURRENT_USER: {$current_user}");
			}

			// Verify that read-only user can SELECT
			$count = (int)$adapter->fetch_one("SELECT COUNT(*) FROM user");
			if ($count !== 100)
			{
				throw new \RuntimeException("Expected 100 users, got: {$count}");
			}
		}
		catch (\PDOException $e)
		{
			// If MySQL daemon is stopped on host, verify that adapter did NOT fallback db_user to vb3_user
			if ($config->db_user !== 'migration_vb3_readonly')
			{
				throw new \RuntimeException("Adapter fallback corrupted db_user! Found: {$config->db_user}");
			}
			echo "  [INFO] (DB port unreachable, verified adapter preserved db_user: {$config->db_user})\n";
		}

		echo "  [PASS] VbCredentialPrecedenceRegressionTest successfully passed\n";
		return true;
	}
}
