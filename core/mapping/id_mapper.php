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
		$candidates = $this->get_source_system_candidates($source_system);
		$sql = 'SELECT id FROM ' . $this->table_name . '
			WHERE ' . $this->db->sql_in_set('source_system', $candidates) . '
				AND content_type = ' . "'" . $this->db->sql_escape($content_type) . "'" . '
				AND source_id = ' . "'" . $this->db->sql_escape($source_id_str) . "'";
		$result = $this->db->sql_query_limit($sql, 1);
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
		$candidates = $this->get_source_system_candidates($source_system);

		foreach ($candidates as $candidate)
		{
			if (isset($this->cache_by_source[$candidate][$content_type][$source_id_str]))
			{
				$cached_val = $this->cache_by_source[$candidate][$content_type][$source_id_str];
				if ($candidate !== $source_system)
				{
					$this->cache_by_source[$source_system][$content_type][$source_id_str] = $cached_val;
				}
				return $cached_val;
			}
		}

		$sql = 'SELECT source_system, target_id FROM ' . $this->table_name . '
			WHERE ' . $this->db->sql_in_set('source_system', $candidates) . '
				AND content_type = ' . "'" . $this->db->sql_escape($content_type) . "'" . '
				AND source_id = ' . "'" . $this->db->sql_escape($source_id_str) . "'";
		$result = $this->db->sql_query($sql);
		$rows = $this->db->sql_fetchrowset($result);
		$this->db->sql_freeresult($result);

		if (!empty($rows))
		{
			$chosen_row = null;
			foreach ($rows as $row)
			{
				if (strcasecmp($row['source_system'], $source_system) === 0)
				{
					$chosen_row = $row;
					break;
				}
			}
			if ($chosen_row === null)
			{
				$chosen_row = reset($rows);
			}

			$target_id = $chosen_row['target_id'];
			$this->cache_by_source[$source_system][$content_type][$source_id_str] = (string)$target_id;
			$matched_system = (string)$chosen_row['source_system'];
			$this->cache_by_source[$matched_system][$content_type][$source_id_str] = (string)$target_id;
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
		$candidates = $this->get_source_system_candidates($source_system);

		foreach ($candidates as $candidate)
		{
			if (isset($this->cache_by_target[$candidate][$content_type][$target_id_str]))
			{
				$cached_val = $this->cache_by_target[$candidate][$content_type][$target_id_str];
				if ($candidate !== $source_system)
				{
					$this->cache_by_target[$source_system][$content_type][$target_id_str] = $cached_val;
				}
				return $cached_val;
			}
		}

		$sql = 'SELECT source_system, source_id FROM ' . $this->table_name . '
			WHERE ' . $this->db->sql_in_set('source_system', $candidates) . '
				AND content_type = ' . "'" . $this->db->sql_escape($content_type) . "'" . '
				AND target_id = ' . "'" . $this->db->sql_escape($target_id_str) . "'";
		$result = $this->db->sql_query($sql);
		$rows = $this->db->sql_fetchrowset($result);
		$this->db->sql_freeresult($result);

		if (!empty($rows))
		{
			$chosen_row = null;
			foreach ($rows as $row)
			{
				if (strcasecmp($row['source_system'], $source_system) === 0)
				{
					$chosen_row = $row;
					break;
				}
			}
			if ($chosen_row === null)
			{
				$chosen_row = reset($rows);
			}

			$source_id = $chosen_row['source_id'];
			$this->cache_by_target[$source_system][$content_type][$target_id_str] = (string)$source_id;
			$matched_system = (string)$chosen_row['source_system'];
			$this->cache_by_target[$matched_system][$content_type][$target_id_str] = (string)$source_id;
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
		$candidates = $this->get_source_system_candidates($source_system);

		foreach ($source_ids as $source_id)
		{
			$id_str = (string)$source_id;
			$found = false;
			foreach ($candidates as $candidate)
			{
				if (isset($this->cache_by_source[$candidate][$content_type][$id_str]))
				{
					$results[$id_str] = $this->cache_by_source[$candidate][$content_type][$id_str];
					$found = true;
					break;
				}
			}
			if (!$found)
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
				$sql = 'SELECT source_system, source_id, target_id FROM ' . $this->table_name . '
					WHERE ' . $this->db->sql_in_set('source_system', $candidates) . '
						AND content_type = ' . "'" . $this->db->sql_escape($content_type) . "'" . '
						AND ' . $this->db->sql_in_set('source_id', $chunk);
				$query_result = $this->db->sql_query($sql);

				while ($row = $this->db->sql_fetchrow($query_result))
				{
					$src = (string)$row['source_id'];
					$tgt = (string)$row['target_id'];
					$sys = (string)$row['source_system'];
					$this->cache_by_source[$sys][$content_type][$src] = $tgt;
					$this->cache_by_source[$source_system][$content_type][$src] = $tgt;
					$this->cache_by_target[$sys][$content_type][$tgt] = $src;
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
		$candidates = $this->get_source_system_candidates($source_system);

		$sql = 'SELECT source_system, metadata_json FROM ' . $this->table_name . '
			WHERE ' . $this->db->sql_in_set('source_system', $candidates) . '
				AND content_type = ' . "'" . $this->db->sql_escape($content_type) . "'" . '
				AND source_id = ' . "'" . $this->db->sql_escape($source_id_str) . "'";
		$result = $this->db->sql_query($sql);
		$rows = $this->db->sql_fetchrowset($result);
		$this->db->sql_freeresult($result);

		if (!empty($rows))
		{
			$chosen_row = null;
			foreach ($rows as $row)
			{
				if (strcasecmp($row['source_system'], $source_system) === 0)
				{
					$chosen_row = $row;
					break;
				}
			}
			if ($chosen_row === null)
			{
				$chosen_row = reset($rows);
			}

			$json = $chosen_row['metadata_json'] ?? '';
			if (!empty($json))
			{
				$decoded = json_decode($json, true);
				return is_array($decoded) ? $decoded : [];
			}
		}

		return [];
	}

	/**
	 * Get list of source system candidates/aliases for lookup resilience
	 *
	 * @param string $source_system
	 * @return array
	 */
	public function get_source_system_candidates(string $source_system): array
	{
		$vb_aliases = ['vbulletin', 'vbulletin3', 'vbulletin4', 'vb3', 'vb4'];
		$lower = strtolower($source_system);
		if (in_array($lower, $vb_aliases, true))
		{
			$result = [$source_system];
			foreach ($vb_aliases as $alias)
			{
				if (strcasecmp($alias, $source_system) !== 0)
				{
					$result[] = $alias;
				}
			}
			return $result;
		}

		$xf_aliases = ['xenforo', 'xenforo2', 'xf', 'xf2'];
		if (in_array($lower, $xf_aliases, true))
		{
			$result = [$source_system];
			foreach ($xf_aliases as $alias)
			{
				if (strcasecmp($alias, $source_system) !== 0)
				{
					$result[] = $alias;
				}
			}
			return $result;
		}

		return [$source_system];
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
