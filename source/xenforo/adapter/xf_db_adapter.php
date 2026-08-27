<?php
/**
 * phpBB Migration Center Extension
 *
 * @copyright (c) 2026 phpBB SEO Team
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace phpbbseo\migrationcenter\source\xenforo\adapter;

use phpbbseo\migrationcenter\core\dto\migration_config_dto;
use PDO;
use PDOException;

/**
 * XenForo Read-Only Database Adapter
 */
class xf_db_adapter
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
		$this->prefix = $config->db_prefix ?: 'xf_';

		$host = $config->db_host ?: 'localhost';
		$port = $config->db_port ?: 3306;
		$dbname = $config->db_name;
		$charset = $config->db_charset ?: 'utf8mb4';

		$dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset={$charset}";
		$options = [
			PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
			PDO::ATTR_EMULATE_PREPARES   => false,
			PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES {$charset} COLLATE {$charset}_unicode_ci",
		];

		$this->pdo = new PDO($dsn, $config->db_user, $config->db_password, $options);
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
		$stmt = $this->pdo->prepare($sql);
		$stmt->execute($params);
		$row = $stmt->fetch();
		return $row ?: null;
	}

	/**
	 * Fetch all rows
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
	 * Check if a table exists in XenForo database
	 *
	 * @param string $table_name Table name without or with prefix
	 * @return bool
	 */
	public function table_exists(string $table_name): bool
	{
		$full_table = (strpos($table_name, $this->prefix) === 0) ? $table_name : ($this->prefix . $table_name);
		$sql = "SELECT table_name FROM information_schema.tables WHERE table_schema = ? AND table_name = ?";
		$stmt = $this->pdo->prepare($sql);
		$stmt->execute([$this->config->db_name, $full_table]);
		return (bool)$stmt->fetchColumn();
	}

	/**
	 * Get maximum ID in a table
	 *
	 * @param string $table
	 * @param string $id_col
	 * @param string $where
	 * @param array $params
	 * @return string|int
	 */
	public function get_max_id(string $table, string $id_col, string $where = '', array $params = [])
	{
		$full_table = (strpos($table, $this->prefix) === 0) ? $table : ($this->prefix . $table);
		$sql = "SELECT MAX({$id_col}) FROM `{$full_table}`";
		if ($where !== '')
		{
			$sql .= " WHERE {$where}";
		}
		$val = $this->fetch_one($sql, $params);
		return $val !== null ? $val : 0;
	}

	/**
	 * Get count of records
	 *
	 * @param string $table
	 * @param string $where
	 * @param array $params
	 * @return int
	 */
	public function get_count(string $table, string $where = '', array $params = []): int
	{
		$full_table = (strpos($table, $this->prefix) === 0) ? $table : ($this->prefix . $table);
		$sql = "SELECT COUNT(*) FROM `{$full_table}`";
		if ($where !== '')
		{
			$sql .= " WHERE {$where}";
		}
		return (int)$this->fetch_one($sql, $params);
	}

	/**
	 * Read a deterministic batch using keyset pagination
	 *
	 * @param string $table
	 * @param string $id_col
	 * @param string|int $cursor
	 * @param int $limit
	 * @param string $additional_where
	 * @param array $params
	 * @return array
	 */
	public function fetch_keyset_batch(
		string $table,
		string $id_col,
		$cursor,
		int $limit,
		string $additional_where = '',
		array $params = []
	): array {
		$full_table = (strpos($table, $this->prefix) === 0) ? $table : ($this->prefix . $table);
		$where = "{$id_col} > :cursor";
		if ($additional_where !== '')
		{
			$where .= " AND ({$additional_where})";
		}

		$sql = "SELECT * FROM `{$full_table}` WHERE {$where} ORDER BY `{$id_col}` ASC LIMIT :batch_limit";
		$stmt = $this->pdo->prepare($sql);
		$stmt->bindValue(':cursor', $cursor);
		$stmt->bindValue(':batch_limit', $limit, PDO::PARAM_INT);

		foreach ($params as $k => $v)
		{
			$stmt->bindValue($k, $v);
		}

		$stmt->execute();
		return $stmt->fetchAll();
	}
}
