<?php
/**
 * phpBB Migration Center Extension
 *
 * @copyright (c) 2026 phpBB SEO Team
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace phpbbseo\migrationcenter\source\smf\config;

use phpbbseo\migrationcenter\core\dto\migration_config_dto;

/**
 * SMF 2.x Configuration Detector
 */
class smf_config_detector
{
	/**
	 * Detect configuration from SMF root path
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

		if (preg_match('#/Settings\.php$#i', $normalized_path))
		{
			$normalized_path = preg_replace('#/Settings\.php$#i', '', $normalized_path);
		}

		$normalized_path = rtrim($normalized_path, '/');

		if (empty($normalized_path) || !is_dir($normalized_path) || !is_readable($normalized_path))
		{
			return null;
		}

		$config_file = $normalized_path . '/Settings.php';
		if (!file_exists($config_file) || !is_readable($config_file))
		{
			return null;
		}

		$extracted = self::parse_config_file($config_file);
		if (!$extracted)
		{
			return null;
		}

		$db_server = (string)($extracted['db_server'] ?? '127.0.0.1');
		$db_port = (int)($extracted['db_port'] ?? 3306) ?: 3306;

		if (strpos($db_server, ':') !== false)
		{
			list($host_part, $port_part) = explode(':', $db_server, 2);
			$db_server = $host_part;
			if ((int)$port_part > 0)
			{
				$db_port = (int)$port_part;
			}
		}

		$dto = new migration_config_dto();
		$dto->source_system = 'smf';
		$dto->source_path   = $normalized_path;
		$dto->db_host       = $db_server;
		$dto->db_port       = $db_port;
		$dto->db_name       = $extracted['db_name'] ?? '';
		$dto->db_user       = $extracted['db_user'] ?? '';
		$dto->db_password   = $extracted['db_passwd'] ?? '';
		$dto->db_prefix     = $extracted['db_prefix'] ?? 'smf_';
		$dto->db_charset    = !empty($extracted['db_mb4']) ? 'utf8mb4' : 'utf8';

		return $dto;
	}

	/**
	 * Safely parse SMF Settings.php file without evaluating arbitrary external code
	 *
	 * @param string $file_path
	 * @return array|null
	 */
	public static function parse_config_file(string $file_path): ?array
	{
		$content = @file_get_contents($file_path);
		if ($content === false || empty($content))
		{
			return null;
		}

		$data = [];
		$vars = [
			'db_server',
			'db_port',
			'db_name',
			'db_user',
			'db_passwd',
			'db_prefix',
			'boarddir',
			'boardurl',
		];

		foreach ($vars as $var)
		{
			// Match $var = 'value'; or $var = "value"; or $var = 123;
			if (preg_match('/\$' . $var . '\s*=\s*(["\'])(.*?)\1\s*;/s', $content, $m))
			{
				$data[$var] = $m[2];
			}
			else if (preg_match('/\$' . $var . '\s*=\s*(\d+)\s*;/s', $content, $m))
			{
				$data[$var] = $m[1];
			}
		}

		if (preg_match('/\$db_mb4\s*=\s*(true|1)\s*;/i', $content))
		{
			$data['db_mb4'] = true;
		}

		if (empty($data['db_name']))
		{
			return null;
		}

		return $data;
	}
}
