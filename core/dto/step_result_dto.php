<?php
/**
 * phpBB Migration Center Extension
 *
 * @copyright (c) 2026 phpBB SEO Team
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace phpbbseo\migrationcenter\core\dto;

/**
 * Step Result DTO
 */
class step_result_dto
{
	/** @var string */
	public $step_name = '';

	/** @var string|int */
	public $next_cursor = 0;

	/** @var int */
	public $read_count = 0;

	/** @var int */
	public $imported_count = 0;

	/** @var int */
	public $skipped_count = 0;

	/** @var int */
	public $failed_count = 0;

	/** @var bool */
	public $is_completed = false;

	/** @var array */
	public $errors = [];

	/** @var array */
	public $metrics = [];

	/**
	 * Constructor
	 *
	 * @param string $step_name
	 */
	public function __construct(string $step_name = '')
	{
		$this->step_name = $step_name;
	}

	/**
	 * Add an error or warning to the result
	 *
	 * @param string $code
	 * @param string $message
	 * @param string $severity
	 * @param string|int $source_id
	 * @param array $context
	 */
	public function add_error(string $code, string $message, string $severity = 'error', $source_id = '', array $context = []): void
	{
		$this->errors[] = [
			'code'      => $code,
			'message'   => $message,
			'severity'  => $severity,
			'source_id' => (string)$source_id,
			'context'   => $context,
		];
		if ($severity === 'error' || $severity === 'critical')
		{
			$this->failed_count++;
		}
	}

	/** @var array */
	public $warnings = [];

	public function __get($name)
	{
		switch ($name)
		{
			case 'items_total':
			case 'items_processed':
				return $this->read_count;
			case 'items_imported':
				return $this->imported_count;
			case 'items_skipped':
				return $this->skipped_count;
			case 'items_failed':
				return $this->failed_count;
		}
		return null;
	}

	public function __set($name, $value)
	{
		switch ($name)
		{
			case 'items_total':
			case 'items_processed':
				$this->read_count = (int)$value;
				break;
			case 'items_imported':
				$this->imported_count = (int)$value;
				break;
			case 'items_skipped':
				$this->skipped_count = (int)$value;
				break;
			case 'items_failed':
				$this->failed_count = (int)$value;
				break;
			default:
				$this->$name = $value;
				break;
		}
	}
}
