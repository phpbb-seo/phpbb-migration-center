<?php
/**
 * phpBB Migration Center Extension
 *
 * @copyright (c) 2026 phpBB SEO Team
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace phpbbseo\migrationcenter\core\dto;

/**
 * Migration Run State DTO
 */
class run_state_dto
{
	/** @var string */
	public $run_id = '';

	/** @var string */
	public $source_system = '';

	/** @var string */
	public $source_version = '';

	/** @var string */
	public $status = 'pending'; // pending, running, paused, completed, failed

	/** @var string */
	public $current_step = '';

	/** @var array */
	public $options = [];

	/** @var array */
	public $stats = [];

	/** @var int */
	public $started_at = 0;

	/** @var int */
	public $paused_at = 0;

	/** @var int */
	public $completed_at = 0;

	/** @var int */
	public $created_at = 0;

	/** @var int */
	public $updated_at = 0;

	/**
	 * Create instance from DB row array
	 *
	 * @param array $data
	 * @return self
	 */
	public static function from_row(array $data): self
	{
		$dto = new self();
		$dto->run_id = (string)($data['run_id'] ?? '');
		$dto->source_system = (string)($data['source_system'] ?? '');
		$dto->source_version = (string)($data['source_version'] ?? '');
		$dto->status = (string)($data['status'] ?? 'pending');
		$dto->current_step = (string)($data['current_step'] ?? '');
		$dto->options = !empty($data['options_json']) ? (json_decode($data['options_json'], true) ?: []) : [];
		$dto->stats = !empty($data['stats_json']) ? (json_decode($data['stats_json'], true) ?: []) : [];
		$dto->started_at = (int)($data['started_at'] ?? 0);
		$dto->paused_at = (int)($data['paused_at'] ?? 0);
		$dto->completed_at = (int)($data['completed_at'] ?? 0);
		$dto->created_at = (int)($data['created_at'] ?? 0);
		$dto->updated_at = (int)($data['updated_at'] ?? 0);
		return $dto;
	}
}
