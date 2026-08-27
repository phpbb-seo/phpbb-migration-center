<?php
/**
 * phpBB Migration Center Extension
 *
 * @copyright (c) 2026 phpBB SEO Team
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace phpbbseo\migrationcenter\source\xenforo\content;

/**
 * XenForo Message Conversion Result DTO
 */
class xf_conversion_result
{
	/** @var string Normalized BBCode message */
	public $normalized_bbcode = '';

	/** @var string Final storage formatted text for phpBB */
	public $storage_text = '';

	/** @var string BBCode UID */
	public $bbcode_uid = '';

	/** @var string BBCode Bitfield */
	public $bbcode_bitfield = '';

	/** @var array Detected source attachment IDs */
	public $detected_attachments = [];

	/** @var array Unsupported tags encountered */
	public $unsupported_tags = [];

	/** @var array Warnings generated during conversion */
	public $warnings = [];

	/** @var string Status ('success', 'warning', 'failed') */
	public $status = 'success';
}
