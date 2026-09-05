<?php
/**
 * phpBB Migration Center Extension
 *
 * @copyright (c) 2026 phpBB SEO Team
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace phpbbseo\migrationcenter\source\vbulletin\adapter;

use phpbbseo\migrationcenter\core\dto\migration_config_dto;
use PDO;
use PDOException;

/**
 * vBulletin 3.8/4.2 Read-Only Database Adapter
 */
class vb_db_adapter
{
	/** @var PDO */
	protected $pdo;

	/** @var migration_config_dto */
	protected $config;

	/** @var string */
	protected $prefix;

	/**
	 * Constructor
	 *
	 * @param migration_config_dto $config
	 * @throws PDOException
	 */
	public function __construct(migration_config_dto $config)
	{
		$this->config = $config;
		if ($config->db_prefix === 'xf_' || $config->db_prefix === null)
		{
			$this->prefix = '';
		}
		else
		{
			$this->prefix = (string)$config->db_prefix;
		}

		if (empty($config->db_password))
		{
			$port = (int)($config->db_port ?: 3306);
			$env_key = ($port === 3308 || $config->db_name === 'vb4_test') ? 'VB4_DB_PASSWORD' : 'VB3_DB_PASSWORD';
			$env_pass = getenv($env_key) ?: ($_ENV[$env_key] ?? ($_SERVER[$env_key] ?? ''));

			if (!empty($env_pass))
			{
				$config->db_password = (string)$env_pass;
			}
			else if (file_exists('C:/vb-migration-lab/.env'))
			{
				$env_lines = @file('C:/vb-migration-lab/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
				if ($env_lines)
				{
					foreach ($env_lines as $l)
					{
						if (strpos(trim($l), '#') === 0 || strpos($l, '=') === false) continue;
						list($k, $v) = explode('=', $l, 2);
						if (trim($k) === $env_key && !empty(trim($v)))
						{
							$config->db_password = trim($v);
							break;
						}
					}
				}
			}

			// Precedence rule: source config detection only for missing defaults.
			// Never overwrite explicit user, and only use detected password if db_user is empty or matches detected user.
			if (empty($config->db_password) && !empty($config->source_path))
			{
				$detected = \phpbbseo\migrationcenter\source\vbulletin\config\vb_config_detector::detect_from_path($config->source_path);
				if ($detected)
				{
					if (empty($config->db_user))
					{
						$config->db_user = $detected->db_user;
						if (!empty($detected->db_password))
						{
							$config->db_password = $detected->db_password;
						}
					}
					else if ($config->db_user === $detected->db_user && !empty($detected->db_password))
					{
						$config->db_password = $detected->db_password;
					}
				}
			}
		}

		$host = $config->db_host ?: '127.0.0.1';
		$port = (int)($config->db_port ?: 3306);
		$dbname = $config->db_name;
		$charset = $config->db_charset ?: 'utf8';

		$dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset={$charset}";
		$options = [
			PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
			PDO::ATTR_EMULATE_PREPARES   => false,
			PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES {$charset} COLLATE {$charset}_unicode_ci",
		];

		try
		{
			$this->pdo = new PDO($dsn, $config->db_user, $config->db_password, $options);
		}
		catch (PDOException $e)
		{
			$sanitized = $this->sanitize_error_message($e->getMessage(), $config->db_password);
			throw new PDOException("Database connection error: {$sanitized}", (int)$e->getCode());
		}
	}

	/**
	 * Get actual connected DB username
	 *
	 * @return string
	 */
	public function get_db_user(): string
	{
		return (string)($this->config->db_user ?? '');
	}

	/**
	 * Get raw PDO instance
	 *
	 * @return PDO
	 */
	public function get_pdo(): PDO
	{
		return $this->pdo;
	}

	/**
	 * Get table prefix
	 *
	 * @return string
	 */
	public function get_prefix(): string
	{
		return $this->prefix;
	}

	/**
	 * Table name with prefix
	 *
	 * @param string $table
	 * @return string
	 */
	public function get_table_name(string $table): string
	{
		return $this->prefix . $table;
	}

	/**
	 * Guard against mutating queries
	 *
	 * @param string $sql
	 * @return void
	 * @throws \InvalidArgumentException
	 */
	protected function assert_read_only(string $sql): void
	{
		if (preg_match('/^\s*(?:INSERT|UPDATE|DELETE|DROP|ALTER|CREATE|REPLACE|TRUNCATE|GRANT|REVOKE|FLUSH|LOCK|UNLOCK)\b/i', $sql))
		{
			throw new \InvalidArgumentException('Mutating queries are prohibited in read-only vBulletin adapter: ' . substr(trim($sql), 0, 50));
		}
	}

	/**
	 * Prepare a read-only query
	 *
	 * @param string $sql
	 * @return \PDOStatement
	 */
	public function prepare(string $sql): \PDOStatement
	{
		$this->assert_read_only($sql);
		return $this->pdo->prepare($sql);
	}

	/**
	 * Execute a read-only query and return PDOStatement
	 *
	 * @param string $sql
	 * @param array $params
	 * @return \PDOStatement
	 */
	public function query(string $sql, array $params = []): \PDOStatement
	{
		$this->assert_read_only($sql);
		if (empty($params))
		{
			return $this->pdo->query($sql);
		}

		$stmt = $this->pdo->prepare($sql);
		$stmt->execute($params);
		return $stmt;
	}

	/**
	 * Fetch a single scalar value
	 *
	 * @param string $sql
	 * @param array $params
	 * @return mixed
	 */
	public function fetch_one(string $sql, array $params = [])
	{
		$this->assert_read_only($sql);
		$stmt = $this->pdo->prepare($sql);
		$stmt->execute($params);
		return $stmt->fetchColumn();
	}

	/**
	 * Alias for fetch_one to support fetch_field calls
	 *
	 * @param string $sql
	 * @param array $params
	 * @return mixed
	 */
	public function fetch_field(string $sql, array $params = [])
	{
		return $this->fetch_one($sql, $params);
	}

	/**
	 * Fetch a single row as associative array
	 *
	 * @param string $sql
	 * @param array $params
	 * @return array|null
	 */
	public function fetch_row(string $sql, array $params = []): ?array
	{
		$this->assert_read_only($sql);
		$stmt = $this->pdo->prepare($sql);
		$stmt->execute($params);
		$row = $stmt->fetch();
		return $row !== false ? $row : null;
	}

	/**
	 * Fetch all rows as associative array
	 *
	 * @param string $sql
	 * @param array $params
	 * @return array
	 */
	public function fetch_all(string $sql, array $params = []): array
	{
		$this->assert_read_only($sql);
		$stmt = $this->pdo->prepare($sql);
		$stmt->execute($params);
		return $stmt->fetchAll();
	}

	/**
	 * Fetch all values of a single column
	 *
	 * @param string $sql
	 * @param array $params
	 * @return array
	 */
	public function fetch_column(string $sql, array $params = []): array
	{
		$this->assert_read_only($sql);
		$stmt = $this->pdo->prepare($sql);
		$stmt->execute($params);
		return $stmt->fetchAll(PDO::FETCH_COLUMN);
	}

	/**
	 * Check if a table exists
	 *
	 * @param string $table_name
	 * @return bool
	 */
	public function table_exists(string $table_name): bool
	{
		$full_name = $this->get_table_name($table_name);
		$clean_name = addslashes($full_name);
		$res = $this->pdo->query("SHOW TABLES LIKE '{$clean_name}'")->fetchAll(PDO::FETCH_COLUMN);
		return !empty($res);
	}

	/**
	 * Get list of column names for a table
	 *
	 * @param string $table_name
	 * @return array
	 */
	public function get_column_names(string $table_name): array
	{
		$full_name = $this->get_table_name($table_name);
		if (!$this->table_exists($table_name))
		{
			return [];
		}

		$sql = "DESCRIBE `{$full_name}`";
		return $this->fetch_column($sql);
	}

	/**
	 * Sanitize MySQL error messages to remove sensitive information
	 *
	 * @param string $message
	 * @param string|null $password
	 * @return string
	 */
	protected function sanitize_error_message(string $message, ?string $password = null): string
	{
		if ($password !== null && $password !== '')
		{
			$message = str_replace($password, '********', $message);
		}
		// Redact password in connection strings
		$message = preg_replace('/password=[^;\s&]+/i', 'password=********', $message);
		return $message;
	}
}
