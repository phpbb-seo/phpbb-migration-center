<?php
/**
 * phpBB Migration Center Extension
 *
 * @copyright (c) 2026 phpBB SEO Team
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace phpbbseo\migrationcenter\source\xenforo\config;

use phpbbseo\migrationcenter\core\dto\migration_config_dto;

/**
 * Safe XenForo Config Detector
 */
class xf_config_detector
{
	/**
	 * Detect configuration from a XenForo root directory path
	 *
	 * @param string $source_path
	 * @return migration_config_dto|null
	 */
	public static function detect_from_path(string $source_path): ?migration_config_dto
	{
		$clean_path = trim($source_path, " \t\n\r\0\x0B\"'");
		$source_path = str_replace('\\', '/', $clean_path);

		if (preg_match('#/src/config\.php$#i', $source_path))
		{
			$source_path = preg_replace('#/src/config\.php$#i', '', $source_path);
		}
		else if (preg_match('#/src/?$#i', $source_path))
		{
			$source_path = preg_replace('#/src/?$#i', '', $source_path);
		}

		$source_path = rtrim($source_path, '/');
		$config_file = $source_path . '/src/config.php';

		if (empty($source_path) || !file_exists($config_file) || !is_readable($config_file))
		{
			return null;
		}

		// Read and execute inside isolated scope
		$config = [];
		try
		{
			// Sandboxed require
			(function(&$config, $file) {
				require $file;
			})($config, $config_file);
		}
		catch (\Throwable $e)
		{
			return null;
		}

		$db = $config['db'] ?? [];
		if (empty($db['dbname']))
		{
			return null;
		}

		$dto = new migration_config_dto();
		$dto->source_system = 'xenforo';
		$dto->source_path = $source_path;
		$dto->db_host = (string)($db['host'] ?? 'localhost');
		$dto->db_port = (int)($db['port'] ?? 3306);
		$dto->db_name = (string)($db['dbname'] ?? '');
		$dto->db_user = (string)($db['username'] ?? '');
		$dto->db_password = (string)($db['password'] ?? '');
		$dto->db_prefix = !empty($db['tablePrefix']) ? (string)$db['tablePrefix'] : 'xf_';
		$dto->db_charset = 'utf8mb4';

		return $dto;
	}
}
