<?php
/**
 * phpBB Migration Center Extension
 *
 * @copyright (c) 2026 phpBB SEO Team
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace phpbbseo\migrationcenter\core\contract;

use phpbbseo\migrationcenter\core\dto\migration_config_dto;
use phpbbseo\migrationcenter\core\dto\preflight_result_dto;

/**
 * Generic Source Provider Interface
 */
interface source_provider_interface
{
	/**
	 * Get source provider unique system identifier (e.g., 'xenforo', 'vbulletin')
	 *
	 * @return string
	 */
	public function get_system_name(): string;

	/**
	 * Get human-readable source title (e.g., 'XenForo 2.x')
	 *
	 * @return string
	 */
	public function get_title(): string;

	/**
	 * Detect source version from database or filesystem
	 *
	 * @param migration_config_dto $config
	 * @return string
	 */
	public function detect_version(migration_config_dto $config): string;

	/**
	 * Run preflight validation checks
	 *
	 * @param migration_config_dto $config
	 * @return preflight_result_dto
	 */
	public function run_preflight(migration_config_dto $config): preflight_result_dto;

	/**
	 * Get ordered list of supported migration steps
	 *
	 * @return array
	 */
	public function get_supported_steps(): array;

	/**
	 * Get maximum source ID for a given content type/step
	 *
	 * @param string $step_name
	 * @param migration_config_dto $config
	 * @return string|int
	 */
	public function get_max_source_id(string $step_name, migration_config_dto $config);

	/**
	 * Get total record count for a given step
	 *
	 * @param string $step_name
	 * @param migration_config_dto $config
	 * @return int
	 */
	public function get_total_records(string $step_name, migration_config_dto $config): int;

	/**
	 * Read a deterministic batch using keyset pagination
	 *
	 * @param string $step_name
	 * @param string|int $cursor
	 * @param int $batch_size
	 * @param migration_config_dto $config
	 * @return array Normalized DTOs or source records
	 */
	public function read_batch(string $step_name, $cursor, int $batch_size, migration_config_dto $config): array;

	/**
	 * Get unsupported or reduced-fidelity features description
	 *
	 * @return array
	 */
	public function get_feature_compatibility(): array;
}
