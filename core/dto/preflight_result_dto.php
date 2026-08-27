<?php
/**
 * phpBB Migration Center Extension
 *
 * @copyright (c) 2026 phpBB SEO Team
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace phpbbseo\migrationcenter\core\dto;

/**
 * Preflight Check Item
 */
class preflight_item_dto
{
	public $id = '';
	public $label = '';
	public $status = 'success'; // success, warning, failure
	public $message = '';
	public $detail = '';
}

/**
 * Preflight Result DTO
 */
class preflight_result_dto
{
	/** @var bool */
	public $passed = true;

	/** @var preflight_item_dto[] */
	public $items = [];

	/** @var array */
	public $detected_meta = [];

	/**
	 * Add check item
	 *
	 * @param string $id
	 * @param string $label
	 * @param string $status
	 * @param string $message
	 * @param string $detail
	 */
	public function add_item(string $id, string $label, string $status, string $message, string $detail = ''): void
	{
		$item = new preflight_item_dto();
		$item->id = $id;
		$item->label = $label;
		$item->status = $status;
		$item->message = $message;
		$item->detail = $detail;

		$this->items[] = $item;

		if ($status === 'failure')
		{
			$this->passed = false;
		}
	}
}
