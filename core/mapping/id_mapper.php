<?php
/**
 * phpBB Migration Center Extension
 *
 * @copyright (c) 2026 phpBB SEO Team
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace phpbbseo\migrationcenter\core\mapping;

use phpbb\db\driver\driver_interface;
use phpbbseo\migrationcenter\core\contract\id_mapper_interface;

/**
 * High-Performance ID Mapper with In-Memory Caching & Bulk Lookup
 */
class id_mapper implements id_mapper_interface
{
	/** @var driver_interface */
	protected $db;

	/** @var string */
	protected $table_name;

	/** @var array In-memory cache: [source_system][content_type][source_id] => target_id */
	protected $cache_by_source = [];

	/** @var array In-memory cache: [source_system][content_type][target_id] => source_id */
	protected $cache_by_target = [];

	/**
	 * Constructor
	 *
	 * @param driver_interface $db
	 * @param string $table_prefix
	 */
	public function __construct(driver_interface $db, string $table_prefix)
	{
		$this->db = $db;
		$this->table_name = $table_prefix . 'migration_id_map';
	}

	/**
	 * Map a single source ID to target ID
	 *
	 * @param string $run_id
	 * @param string $source_system
	 * @param string $content_type
	 * @param string|int $source_id
	 * @param string|int $target_id
	 * @param string $status
	 * @param string $checksum
	 * @param array $metadata
	 * @return bool
	 */
	public function set(string $run_id, string $source_system, string $content_type, $source_id, $target_id, string $status = 'mapped', string $checksum = '', array $metadata = []): bool
	{
		$source_id_str = (string)$source_id;
		$target_id_str = (string)$target_id;

		$data = [
			'run_id'        => $run_id,
			'source_system' => $source_system,
			'content_type'  => $content_type,
			'source_id'     => $source_id_str,
			'target_id'     => $target_id_str,
			'status'        => $status,
			'checksum'      => $checksum,
			'metadata_json' => !empty($metadata) ? json_encode($metadata) : '',
			'created_at'    => time(),
			'updated_at'    => time(),
		];

		// Check if mapping already exists for this source system + content_type + source_id
		$sql = 'SELECT id FROM ' . $this->table_name . '
			WHERE source_system = ' . "'" . $this->db->sql_escape($source_system) . "'" . '
				AND content_type = ' . "'" . $this->db->sql_escape($content_type) . "'" . '
				AND source_id = ' . "'" . $this->db->sql_escape($source_id_str) . "'";
		$result = $this->db->sql_query($sql);
		$existing_id = (int)$this->db->sql_fetchfield('id');
		$this->db->sql_freeresult($result);

		if ($existing_id > 0)
		{
			unset($data['created_at']);
			$sql = 'UPDATE ' . $this->table_name . '
				SET ' . $this->db->sql_build_array('UPDATE', $data) . '
				WHERE id = ' . $existing_id;
			$this->db->sql_query($sql);
		}
		else
		{
			$sql = 'INSERT INTO ' . $this->table_name . ' ' . $this->db->sql_build_array('INSERT', $data);
			$this->db->sql_query($sql);
		}

		$this->cache_by_source[$source_system][$content_type][$source_id_str] = $target_id_str;
		$this->cache_by_target[$source_system][$content_type][$target_id_str] = $source_id_str;

		return true;
	}

	/**
	 * Map a batch of IDs efficiently
	 *
	 * @param string $run_id
	 * @param string $source_system
	 * @param string $content_type
	 * @param array $mappings
	 * @return int
	 */
	public function set_batch(string $run_id, string $source_system, string $content_type, array $mappings): int
	{
		if (empty($mappings))
		{
			return 0;
		}

		$now = time();
		$insert_rows = [];

		foreach ($mappings as $map)
		{
			$source_id_str = (string)($map['source_id'] ?? '');
			$target_id_str = (string)($map['target_id'] ?? '');

			if ($source_id_str === '' || $target_id_str === '')
			{
				continue;
			}

			$status = (string)($map['status'] ?? 'mapped');
			$checksum = (string)($map['checksum'] ?? '');
			$metadata = !empty($map['metadata']) ? json_encode($map['metadata']) : '';

			$insert_rows[] = [
				'run_id'        => $run_id,
				'source_system' => $source_system,
				'content_type'  => $content_type,
				'source_id'     => $source_id_str,
				'target_id'     => $target_id_str,
				'status'        => $status,
				'checksum'      => $checksum,
				'metadata_json' => $metadata,
				'created_at'    => $now,
				'updated_at'    => $now,
			];

			$this->cache_by_source[$source_system][$content_type][$source_id_str] = $target_id_str;
			$this->cache_by_target[$source_system][$content_type][$target_id_str] = $source_id_str;
		}

		if (!empty($insert_rows))
		{
			$this->db->sql_multi_insert($this->table_name, $insert_rows);
		}

		return count($insert_rows);
	}

	/**
	 * Get target ID for a source ID
	 *
	 * @param string $source_system
	 * @param string $content_type
	 * @param string|int $source_id
	 * @return string|int|null
	 */
	public function get_target_id(string $source_system, string $content_type, $source_id)
	{
		$source_id_str = (string)$source_id;

		if (isset($this->cache_by_source[$source_system][$content_type][$source_id_str]))
		{
			return $this->cache_by_source[$source_system][$content_type][$source_id_str];
		}

		$sql = 'SELECT target_id FROM ' . $this->table_name . '
			WHERE source_system = ' . "'" . $this->db->sql_escape($source_system) . "'" . '
				AND content_type = ' . "'" . $this->db->sql_escape($content_type) . "'" . '
				AND source_id = ' . "'" . $this->db->sql_escape($source_id_str) . "'";
		$result = $this->db->sql_query($sql);
		$target_id = $this->db->sql_fetchfield('target_id');
		$this->db->sql_freeresult($result);

		if ($target_id !== false && $target_id !== null)
		{
			$this->cache_by_source[$source_system][$content_type][$source_id_str] = (string)$target_id;
			return $target_id;
		}

		return null;
	}

	/**
	 * Get source ID for a target ID
	 *
	 * @param string $source_system
	 * @param string $content_type
	 * @param string|int $target_id
	 * @return string|int|null
	 */
	public function get_source_id(string $source_system, string $content_type, $target_id)
	{
		$target_id_str = (string)$target_id;

		if (isset($this->cache_by_target[$source_system][$content_type][$target_id_str]))
		{
			return $this->cache_by_target[$source_system][$content_type][$target_id_str];
		}

		$sql = 'SELECT source_id FROM ' . $this->table_name . '
			WHERE source_system = ' . "'" . $this->db->sql_escape($source_system) . "'" . '
				AND content_type = ' . "'" . $this->db->sql_escape($content_type) . "'" . '
				AND target_id = ' . "'" . $this->db->sql_escape($target_id_str) . "'";
		$result = $this->db->sql_query($sql);
		$source_id = $this->db->sql_fetchfield('source_id');
		$this->db->sql_freeresult($result);

		if ($source_id !== false && $source_id !== null)
		{
			$this->cache_by_target[$source_system][$content_type][$target_id_str] = (string)$source_id;
			return $source_id;
		}

		return null;
	}

	/**
	 * Bulk lookup target IDs for a list of source IDs
	 *
	 * @param string $source_system
	 * @param string $content_type
	 * @param array $source_ids
	 * @return array
	 */
	public function get_target_ids(string $source_system, string $content_type, array $source_ids): array
	{
		$results = [];
		$missing_ids = [];

		foreach ($source_ids as $source_id)
		{
			$id_str = (string)$source_id;
			if (isset($this->cache_by_source[$source_system][$content_type][$id_str]))
			{
				$results[$id_str] = $this->cache_by_source[$source_system][$content_type][$id_str];
			}
			else
			{
				$missing_ids[] = $id_str;
			}
		}

		if (!empty($missing_ids))
		{
			// Batch query missing IDs
			$chunk_size = 500;
			$chunks = array_chunk(array_unique($missing_ids), $chunk_size);

			foreach ($chunks as $chunk)
			{
				$sql = 'SELECT source_id, target_id FROM ' . $this->table_name . '
					WHERE source_system = ' . "'" . $this->db->sql_escape($source_system) . "'" . '
						AND content_type = ' . "'" . $this->db->sql_escape($content_type) . "'" . '
						AND ' . $this->db->sql_in_set('source_id', $chunk);
				$query_result = $this->db->sql_query($sql);

				while ($row = $this->db->sql_fetchrow($query_result))
				{
					$src = (string)$row['source_id'];
					$tgt = (string)$row['target_id'];
					$this->cache_by_source[$source_system][$content_type][$src] = $tgt;
					$this->cache_by_target[$source_system][$content_type][$tgt] = $src;
					$results[$src] = $tgt;
				}
				$this->db->sql_freeresult($query_result);
			}
		}

		return $results;
	}

	/**
	 * Get structured metadata for a source ID
	 *
	 * @param string $source_system
	 * @param string $content_type
	 * @param string|int $source_id
	 * @return array
	 */
	public function get_metadata(string $source_system, string $content_type, $source_id): array
	{
		$source_id_str = (string)$source_id;

		$sql = 'SELECT metadata_json FROM ' . $this->table_name . '
			WHERE source_system = ' . "'" . $this->db->sql_escape($source_system) . "'" . '
				AND content_type = ' . "'" . $this->db->sql_escape($content_type) . "'" . '
				AND source_id = ' . "'" . $this->db->sql_escape($source_id_str) . "'";
		$result = $this->db->sql_query($sql);
		$json = $this->db->sql_fetchfield('metadata_json');
		$this->db->sql_freeresult($result);

		if (!empty($json))
		{
			$decoded = json_decode($json, true);
			return is_array($decoded) ? $decoded : [];
		}

		return [];
	}

	/**
	 * Clear local memory cache
	 */
	public function clear_cache(): void
	{
		$this->cache_by_source = [];
		$this->cache_by_target = [];
	}
}
