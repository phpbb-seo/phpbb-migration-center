<?php
/**
 * phpBB Migration Center Extension
 *
 * @copyright (c) 2026 phpBB SEO Team
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace phpbbseo\migrationcenter\source\mybb\step;

use phpbbseo\migrationcenter\core\contract\step_interface;
use phpbbseo\migrationcenter\core\contract\source_provider_interface;
use phpbbseo\migrationcenter\core\contract\target_writer_interface;
use phpbbseo\migrationcenter\core\dto\migration_config_dto;
use phpbbseo\migrationcenter\core\dto\step_result_dto;

/**
 * MyBB Conversation Attachments Step (Not Applicable in standard core MyBB)
 */
class conversation_attachments_step implements step_interface
{
	public function get_name(): string
	{
		return 'conversation_attachments';
	}

	public function get_label(): string
	{
		return 'PM Attachments';
	}

	public function get_dependencies(): array
	{
		return ['conversation_messages'];
	}

	public function process_batch(
		string $run_id,
		$cursor,
		int $batch_size,
		migration_config_dto $config,
		source_provider_interface $provider,
		target_writer_interface $writer
	): step_result_dto {
		$result = new step_result_dto('conversation_attachments');
		$result->read_count = 0;
		$result->imported_count = 0;
		$result->skipped_count = 0;
		$result->failed_count = 0;
		$result->metrics = [
			'created' => 0,
			'reused'  => 0,
			'updated' => 0,
			'skipped' => 0,
			'failed'  => 0,
		];
		$result->next_cursor = (string)$cursor;
		$result->current_cursor = (string)$cursor;
		$result->is_completed = true;

		return $result;
	}
}
