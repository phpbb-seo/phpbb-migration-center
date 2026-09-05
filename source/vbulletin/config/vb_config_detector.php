<?php
/**
 * phpBB Migration Center Extension
 *
 * @copyright (c) 2026 phpBB SEO Team
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace phpbbseo\migrationcenter\source\vbulletin\config;

use phpbbseo\migrationcenter\core\dto\migration_config_dto;

/**
 * vBulletin 3.8/4.2 Configuration Detector
 */
class vb_config_detector
{
	/**
	 * Detect configuration from vBulletin root path
	 *
	 * @param string $source_path
	 * @return migration_config_dto|null
	 */
	public static function detect_from_path(string $source_path): ?migration_config_dto
	{
		// 1. Security Check: Null-byte & path sanitization
		if (strpos($source_path, "\0") !== false)
		{
			return null;
		}

		$clean_path = trim($source_path, " \t\n\r\0\x0B\"'");
		$normalized_path = str_replace('\\', '/', $clean_path);

		// If path directly points to config.php or includes folder, resolve root
		if (preg_match('#/includes/config\.php$#i', $normalized_path))
		{
			$normalized_path = preg_replace('#/includes/config\.php$#i', '', $normalized_path);
		}
		else if (preg_match('#/config\.php$#i', $normalized_path))
		{
			$normalized_path = preg_replace('#/config\.php$#i', '', $normalized_path);
		}
		else if (preg_match('#/includes/?$#i', $normalized_path))
		{
			$normalized_path = preg_replace('#/includes/?$#i', '', $normalized_path);
		}

		$normalized_path = rtrim($normalized_path, '/');

		if (empty($normalized_path) || !is_dir($normalized_path) || !is_readable($normalized_path))
		{
			return null;
		}

		$config_file = $normalized_path . '/includes/config.php';
		if (!file_exists($config_file) || !is_readable($config_file))
		{
			// Fallback: check config.php in root
			if (file_exists($normalized_path . '/config.php') && is_readable($normalized_path . '/config.php'))
			{
				$config_file = $normalized_path . '/config.php';
			}
			else
			{
				return null;
			}
		}

		// 2. Parse config.php in isolated scope
		$extracted = self::parse_config_file($config_file);
		if (!$extracted)
		{
			return null;
		}

		$dto = new migration_config_dto();
		$dto->source_system = 'vbulletin';
		$dto->source_path = $normalized_path;
		$dto->db_host = $extracted['db_host'] ?? '127.0.0.1';
		$dto->db_port = (int)($extracted['db_port'] ?? 3306);
		$dto->db_name = $extracted['db_name'] ?? '';
		$dto->db_user = $extracted['db_user'] ?? '';
		$dto->db_password = $extracted['db_password'] ?? '';
		$dto->db_prefix = $extracted['db_prefix'] ?? '';
		$dto->db_charset = $extracted['db_charset'] ?? 'utf8';

		return $dto;
	}

	/**
	 * Read and parse vBulletin config.php file safely
	 *
	 * @param string $config_file
	 * @return array|null
	 */
	protected static function parse_config_file(string $config_file): ?array
	{
		try
		{
			$config = [];
			// Isolated include
			$include_func = function($file) {
				$config = [];
				@include($file);
				return $config;
			};

			$config = $include_func($config_file);
			if (!is_array($config) || empty($config))
			{
				// Fallback regex extraction if variables are structured differently
				$content = file_get_contents($config_file);
				return self::extract_by_regex($content);
			}

			$db_host = $config['MasterServer']['servername'] ?? $config['Database']['servername'] ?? '127.0.0.1';
			if ($db_host === 'localhost' || $db_host === 'vb3-db' || $db_host === 'vb4-db')
			{
				$db_host = '127.0.0.1';
			}

			$db_port = (int)($config['MasterServer']['port'] ?? $config['Database']['port'] ?? 3306);
			if ($db_port <= 0)
			{
				$db_port = 3306;
			}

			$db_name = $config['Database']['dbname'] ?? '';
			$db_user = $config['MasterServer']['username'] ?? $config['Database']['username'] ?? '';
			$db_pass = $config['MasterServer']['password'] ?? $config['Database']['password'] ?? '';
			$db_prefix = (string)($config['Database']['tableprefix'] ?? '');
			$db_charset = !empty($config['Mysqli']['charset']) ? $config['Mysqli']['charset'] : 'utf8';

			return [
				'db_host'     => $db_host,
				'db_port'     => $db_port,
				'db_name'     => $db_name,
				'db_user'     => $db_user,
				'db_password' => $db_pass,
				'db_prefix'   => $db_prefix,
				'db_charset'  => $db_charset,
			];
		}
		catch (\Throwable $e)
		{
			return null;
		}
	}

	/**
	 * Regex fallback parser
	 *
	 * @param string $content
	 * @return array|null
	 */
	protected static function extract_by_regex(string $content): ?array
	{
		$res = [];
		if (preg_match('/\$config\[[\'"]Database[\'"]\]\[[\'"]dbname[\'"]\]\s*=\s*[\'"]([^\'"]*)[\'"]/i', $content, $m))
		{
			$res['db_name'] = $m[1];
		}
		if (preg_match('/\$config\[[\'"]Database[\'"]\]\[[\'"]tableprefix[\'"]\]\s*=\s*[\'"]([^\'"]*)[\'"]/i', $content, $m))
		{
			$res['db_prefix'] = $m[1];
		}
		if (preg_match('/\$config\[[\'"]MasterServer[\'"]\]\[[\'"]servername[\'"]\]\s*=\s*[\'"]([^\'"]*)[\'"]/i', $content, $m))
		{
			$res['db_host'] = ($m[1] === 'localhost') ? '127.0.0.1' : $m[1];
		}
		if (preg_match('/\$config\[[\'"]MasterServer[\'"]\]\[[\'"]port[\'"]\]\s*=\s*(\d+)/i', $content, $m))
		{
			$res['db_port'] = (int)$m[1];
		}
		if (preg_match('/\$config\[[\'"]MasterServer[\'"]\]\[[\'"]username[\'"]\]\s*=\s*[\'"]([^\'"]*)[\'"]/i', $content, $m))
		{
			$res['db_user'] = $m[1];
		}
		if (preg_match('/\$config\[[\'"]MasterServer[\'"]\]\[[\'"]password[\'"]\]\s*=\s*[\'"]([^\'"]*)[\'"]/i', $content, $m))
		{
			$res['db_password'] = $m[1];
		}
		if (preg_match('/\$config\[[\'"]Mysqli[\'"]\]\[[\'"]charset[\'"]\]\s*=\s*[\'"]([^\'"]*)[\'"]/i', $content, $m))
		{
			$res['db_charset'] = $m[1];
		}

		return !empty($res['db_name']) ? $res : null;
	}
}
