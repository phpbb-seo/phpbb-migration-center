<?php
/**
 * phpBB Migration Center Extension
 *
 * @copyright (c) 2026 phpBB SEO Team
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace phpbbseo\migrationcenter\core\contract;

/**
 * ID Mapper Interface
 */
interface id_mapper_interface
{
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
	public function set(string $run_id, string $source_system, string $content_type, $source_id, $target_id, string $status = 'mapped', string $checksum = '', array $metadata = []): bool;

	/**
	 * Map a batch of IDs efficiently
	 *
	 * @param string $run_id
	 * @param string $source_system
	 * @param string $content_type
	 * @param array $mappings Array of [source_id, target_id, status, checksum, metadata]
	 * @return int Number of mappings inserted/updated
	 */
	public function set_batch(string $run_id, string $source_system, string $content_type, array $mappings): int;

	/**
	 * Get target ID for a source ID
	 *
	 * @param string $source_system
	 * @param string $content_type
	 * @param string|int $source_id
	 * @return string|int|null
	 */
	public function get_target_id(string $source_system, string $content_type, $source_id);

	/**
	 * Get source ID for a target ID
	 *
	 * @param string $source_system
	 * @param string $content_type
	 * @param string|int $target_id
	 * @return string|int|null
	 */
	public function get_source_id(string $source_system, string $content_type, $target_id);

	/**
	 * Bulk lookup target IDs for a list of source IDs
	 *
	 * @param string $source_system
	 * @param string $content_type
	 * @param array $source_ids
	 * @return array Map of [source_id => target_id]
	 */
	public function get_target_ids(string $source_system, string $content_type, array $source_ids): array;

	/**
	 * Get structured metadata for a source ID
	 *
	 * @param string $source_system
	 * @param string $content_type
	 * @param string|int $source_id
	 * @return array
	 */
	public function get_metadata(string $source_system, string $content_type, $source_id): array;

	/**
	 * Clear local memory cache
	 */
	public function clear_cache(): void;
}
