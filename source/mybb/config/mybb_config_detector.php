<?php
/**
 * phpBB Migration Center Extension
 *
 * @copyright (c) 2026 phpBB SEO Team
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace phpbbseo\migrationcenter\source\mybb\config;

use phpbbseo\migrationcenter\core\dto\migration_config_dto;

/**
 * MyBB 1.8 Configuration Detector
 */
class mybb_config_detector
{
	/**
	 * Detect configuration from MyBB root path
	 *
	 * @param string $source_path
	 * @return migration_config_dto|null
	 */
	public static function detect_from_path(string $source_path): ?migration_config_dto
	{
		if (strpos($source_path, "\0") !== false)
		{
			return null;
		}

		$clean_path = trim($source_path, " \t\n\r\0\x0B\"'");
		$normalized_path = str_replace('\\', '/', $clean_path);

		if (preg_match('#/inc/config\.php$#i', $normalized_path))
		{
			$normalized_path = preg_replace('#/inc/config\.php$#i', '', $normalized_path);
		}
		else if (preg_match('#/inc/?$#i', $normalized_path))
		{
			$normalized_path = preg_replace('#/inc/?$#i', '', $normalized_path);
		}

		$normalized_path = rtrim($normalized_path, '/');

		if (empty($normalized_path) || !is_dir($normalized_path) || !is_readable($normalized_path))
		{
			return null;
		}

		$config_file = $normalized_path . '/inc/config.php';
		if (!file_exists($config_file) || !is_readable($config_file))
		{
			return null;
		}

		$extracted = self::parse_config_file($config_file);
		if (!$extracted)
		{
			return null;
		}

		$dto = new migration_config_dto();
		$dto->source_system = 'mybb';
		$dto->source_path = $normalized_path;
		$dto->db_host = $extracted['db_host'] ?? '127.0.0.1';
		$dto->db_port = (int)($extracted['db_port'] ?? 3306);
		$dto->db_name = $extracted['db_name'] ?? '';
		$dto->db_user = $extracted['db_user'] ?? '';
		$dto->db_password = $extracted['db_password'] ?? '';
		$dto->db_prefix = $extracted['db_prefix'] ?? 'mybb_';
		$dto->db_charset = $extracted['db_charset'] ?? 'utf8mb4';

		return $dto;
	}

	/**
	 * Read and parse MyBB config.php file safely
	 *
	 * @param string $config_file
	 * @return array|null
	 */
	protected static function parse_config_file(string $config_file): ?array
	{
		try
		{
			$config = [];
			include $config_file;

			if (!isset($config['database']))
			{
				return null;
			}

			$db_conf = $config['database'];
			$host = (string)($db_conf['hostname'] ?? '127.0.0.1');
			$port = 3306;

			if (strpos($host, ':') !== false)
			{
				list($host_part, $port_part) = explode(':', $host, 2);
				$host = $host_part;
				$port = (int)$port_part ?: 3306;
			}

			return [
				'db_host'     => $host,
				'db_port'     => $port,
				'db_name'     => (string)($db_conf['database'] ?? ''),
				'db_user'     => (string)($db_conf['username'] ?? ''),
				'db_password' => (string)($db_conf['password'] ?? ''),
				'db_prefix'   => (string)($db_conf['table_prefix'] ?? 'mybb_'),
				'db_charset'  => (string)($db_conf['encoding'] ?? 'utf8mb4'),
			];
		}
		catch (\Throwable $e)
		{
			return null;
		}
	}
}
