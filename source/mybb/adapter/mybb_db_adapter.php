<?php
/**
 * phpBB Migration Center Extension
 *
 * @copyright (c) 2026 phpBB SEO Team
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace phpbbseo\migrationcenter\source\mybb\adapter;

use phpbbseo\migrationcenter\core\dto\migration_config_dto;
use PDO;
use PDOException;

/**
 * MyBB 1.8 Read-Only Database Adapter
 */
class mybb_db_adapter
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
			$this->prefix = 'mybb_';
			$config->db_prefix = 'mybb_';
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
				$detected = \phpbbseo\migrationcenter\source\mybb\config\mybb_config_detector::detect_from_path($config->source_path);
				if ($detected)
				{
					if (empty($config->db_host)) $config->db_host = $detected->db_host;
					if (empty($config->db_port)) $config->db_port = $detected->db_port;
					if (empty($config->db_name)) $config->db_name = $detected->db_name;
					if (empty($config->db_user)) $config->db_user = $detected->db_user;
					if (empty($config->db_password) && !empty($detected->db_password)) $config->db_password = $detected->db_password;
					if (empty($config->db_prefix) || $config->db_prefix === 'xf_')
					{
						$this->prefix = $detected->db_prefix ?: 'mybb_';
						$config->db_prefix = $this->prefix;
					}
				}
			}
		}

		$host = $config->db_host ?: '127.0.0.1';
		$port = (int)($config->db_port ?: 3306);
		$dbname = $config->db_name ?: 'mybb';
		$user = $config->db_user ?: 'root';
		$pass = $config->db_password !== null ? (string)$config->db_password : '';

		$dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4";
		$options = [
			PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
			PDO::ATTR_EMULATE_PREPARES   => false,
			PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4"
		];

		$this->pdo = new PDO($dsn, $user, $pass, $options);
		$this->pdo->exec("SET sql_mode = 'NO_ENGINE_SUBSTITUTION'");
	}

	/**
	 * Get underlying PDO instance
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
	 * Execute query and fetch single column
	 *
	 * @param string $sql
	 * @param array $params
	 * @return mixed
	 */
	public function fetch_column(string $sql, array $params = [])
	{
		$stmt = $this->pdo->prepare($sql);
		$stmt->execute($params);
		return $stmt->fetchColumn();
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
		$stmt = $this->pdo->prepare($sql);
		$stmt->execute($params);
		return $stmt->fetchColumn();
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

		$stmt = $this->pdo->query("DESCRIBE `{$full_name}`");
		return $stmt->fetchAll(PDO::FETCH_COLUMN);
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
		$row = $stmt->fetch();
		return $row ?: null;
	}
}
