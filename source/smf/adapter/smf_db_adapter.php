<?php
/**
 * phpBB Migration Center Extension
 *
 * @copyright (c) 2026 phpBB SEO Team
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace phpbbseo\migrationcenter\source\smf\adapter;

use phpbbseo\migrationcenter\core\dto\migration_config_dto;
use PDO;
use PDOException;

/**
 * SMF 2.x Read-Only Database Adapter
 */
class smf_db_adapter
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
		if ($config->db_prefix === null || $config->db_prefix === '' || $config->db_prefix === 'xf_')
		{
			$this->prefix = 'smf_';
			$config->db_prefix = 'smf_';
		}
		else
		{
			$this->prefix = (string)$config->db_prefix;
		}

		// Source config auto-detection for missing credentials
		if (empty($config->db_name) || empty($config->db_user) || empty($config->db_host))
		{
			if (!empty($config->source_path))
			{
				$detected = \phpbbseo\migrationcenter\source\smf\config\smf_config_detector::detect_from_path($config->source_path);
				if ($detected)
				{
					if (empty($config->db_host)) $config->db_host = $detected->db_host;
					if (empty($config->db_port)) $config->db_port = $detected->db_port;
					if (empty($config->db_name)) $config->db_name = $detected->db_name;
					if (empty($config->db_user)) $config->db_user = $detected->db_user;
					if (empty($config->db_password) && !empty($detected->db_password)) $config->db_password = $detected->db_password;
					if (empty($config->db_prefix) || $config->db_prefix === 'xf_')
					{
						$this->prefix = $detected->db_prefix ?: 'smf_';
						$config->db_prefix = $this->prefix;
					}
				}
			}
		}

		$host = $config->db_host ?: '127.0.0.1';
		$port = (int)($config->db_port ?: 3306);
		$dbname = $config->db_name ?: 'smf';
		$user = $config->db_user ?: 'root';
		$pass = $config->db_password !== null ? (string)$config->db_password : '';

		$dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4";
		$options = [
			PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
			PDO::ATTR_EMULATE_PREPARES   => false,
		];

		try
		{
			$this->pdo = new PDO($dsn, $user, $pass, $options);
		}
		catch (PDOException $e)
		{
			// Fallback to utf8 if utf8mb4 fails on very old MySQL
			$dsn_utf8 = "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8";
			$this->pdo = new PDO($dsn_utf8, $user, $pass, $options);
		}
	}

	/**
	 * Get active PDO instance
	 *
	 * @return PDO
	 */
	public function get_pdo(): PDO
	{
		return $this->pdo;
	}

	/**
	 * Get prefixed table name
	 *
	 * @param string $table
	 * @return string
	 */
	public function get_table_name(string $table): string
	{
		return $this->prefix . $table;
	}

	/**
	 * Execute query and fetch all rows
	 *
	 * @param string $sql
	 * @param array $params
	 * @return array
	 */
	public function fetch_all(string $sql, array $params = []): array
	{
		$stmt = $this->pdo->prepare($sql);
		$stmt->execute($params);
		return $stmt->fetchAll();
	}

	/**
	 * Execute query and fetch single scalar value
	 *
	 * @param string $sql
	 * @param array $params
	 * @return mixed
	 */
	public function fetch_one(string $sql, array $params = [])
	{
		$stmt = $this->pdo->prepare($sql);
		$stmt->execute($params);
		return $stmt->fetchColumn();
	}

	/**
	 * Execute query and fetch single row
	 *
	 * @param string $sql
	 * @param array $params
	 * @return array|null
	 */
	public function fetch_row(string $sql, array $params = []): ?array
	{
		$stmt = $this->pdo->prepare($sql);
		$stmt->execute($params);
		$res = $stmt->fetch();
		return $res ?: null;
	}

	/**
	 * Execute query with parameters
	 *
	 * @param string $sql
	 * @param array $params
	 * @return bool
	 */
	public function execute(string $sql, array $params = []): bool
	{
		$stmt = $this->pdo->prepare($sql);
		return $stmt->execute($params);
	}

	/**
	 * Check if table exists in source database
	 *
	 * @param string $table
	 * @return bool
	 */
	public function table_exists(string $table): bool
	{
		$full_name = $this->get_table_name($table);
		try
		{
			$clean = addslashes($full_name);
			$res = $this->pdo->query("SHOW TABLES LIKE '{$clean}'")->fetchAll(PDO::FETCH_COLUMN);
			return !empty($res);
		}
		catch (\Throwable $e)
		{
			return false;
		}
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
		try
		{
			$clean = addslashes($full_name);
			$rows = $this->pdo->query("DESCRIBE `{$clean}`")->fetchAll();
			return array_column($rows, 'Field');
		}
		catch (\Throwable $e)
		{
			return [];
		}
	}

	/**
	 * Get total count from a table
	 *
	 * @param string $table
	 * @param string $where
	 * @param array $params
	 * @return int
	 */
	public function get_count(string $table, string $where = '', array $params = []): int
	{
		$full_name = $this->get_table_name($table);
		$sql = "SELECT COUNT(*) FROM `{$full_name}`";
		if (!empty($where))
		{
			$sql .= " WHERE {$where}";
		}

		$stmt = $this->pdo->prepare($sql);
		$stmt->execute($params);
		return (int)$stmt->fetchColumn();
	}

	/**
	 * Fetch batch of records with cursor pagination
	 *
	 * @param string $table
	 * @param string $id_col
	 * @param int $cursor
	 * @param int $limit
	 * @param string $extra_where
	 * @param array $params
	 * @return array
	 */
	public function fetch_batch(string $table, string $id_col, int $cursor, int $limit, string $extra_where = '', array $params = []): array
	{
		$full_name = $this->get_table_name($table);
		$sql = "SELECT * FROM `{$full_name}` WHERE `{$id_col}` > :cursor";
		if (!empty($extra_where))
		{
			$sql .= " AND ({$extra_where})";
		}
		$sql .= " ORDER BY `{$id_col}` ASC LIMIT :limit";

		$stmt = $this->pdo->prepare($sql);
		$stmt->bindValue(':cursor', $cursor, PDO::PARAM_INT);
		$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
		foreach ($params as $k => $v)
		{
			$stmt->bindValue($k, $v);
		}
		$stmt->execute();
		return $stmt->fetchAll();
	}

	/**
	 * Read SMF setting from smf_settings table
	 *
	 * @param string $variable
	 * @return string|null
	 */
	public function get_setting(string $variable): ?string
	{
		try
		{
			$tbl = $this->get_table_name('settings');
			$stmt = $this->pdo->prepare("SELECT value FROM `{$tbl}` WHERE variable = ? LIMIT 1");
			$stmt->execute([$variable]);
			$val = $stmt->fetchColumn();
			return ($val !== false) ? (string)$val : null;
		}
		catch (\Throwable $e)
		{
			return null;
		}
	}
}
