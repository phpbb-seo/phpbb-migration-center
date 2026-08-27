<?php
/**
 * phpBB Migration Center Extension
 *
 * @copyright (c) 2026 phpBB SEO Team
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace phpbbseo\migrationcenter\core\contract;

use phpbbseo\migrationcenter\core\dto\migration_config_dto;
use phpbbseo\migrationcenter\core\dto\step_result_dto;

/**
 * Migration Step Interface
 */
interface step_interface
{
	/**
	 * Unique step identifier (e.g. 'users', 'groups', 'forums', 'topics', 'posts')
	 *
	 * @return string
	 */
	public function get_name(): string;

	/**
	 * Human-readable step label
	 *
	 * @return string
	 */
	public function get_label(): string;

	/**
	 * Dependent step identifiers that must execute before this step
	 *
	 * @return array
	 */
	public function get_dependencies(): array;

	/**
	 * Execute a single batch for this step
	 *
	 * @param string $run_id
	 * @param string|int $cursor
	 * @param int $batch_size
	 * @param migration_config_dto $config
	 * @param source_provider_interface $provider
	 * @param target_writer_interface $writer
	 * @return step_result_dto
	 */
	public function process_batch(
		string $run_id,
		$cursor,
		int $batch_size,
		migration_config_dto $config,
		source_provider_interface $provider,
		target_writer_interface $writer
	): step_result_dto;
}
