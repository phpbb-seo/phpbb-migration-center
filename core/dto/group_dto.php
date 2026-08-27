<?php
/**
 * phpBB Migration Center Extension
 *
 * @copyright (c) 2026 phpBB SEO Team
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace phpbbseo\migrationcenter\core\dto;

/**
 * Normalized Group DTO
 */
class group_dto
{
	/** @var string|int Source group ID */
	public $source_id;

	/** @var string Group title / name */
	public $group_name = '';

	/** @var string Clean name for comparison */
	public $group_name_clean = '';

	/** @var string Description */
	public $group_desc = '';

	/** @var int phpBB group_type: 0 = OPEN, 1 = CLOSED, 2 = HIDDEN, 3 = SPECIAL, 4 = FREE */
	public $group_type = 0;

	/** @var string Hex color code (e.g. 'AA0000') without '#' */
	public $group_colour = '';

	/** @var int Rank ID */
	public $group_rank = 0;

	/** @var int Display style priority */
	public $display_style_priority = 0;

	/** @var string Custom user title associated with group */
	public $user_title = '';

	/** @var bool Whether this is a standard/built-in group mapped to a phpBB system group */
	public $is_builtin = false;

	/** @var string Canonical name in phpBB (e.g. 'REGISTERED', 'ADMINISTRATORS', 'GLOBAL_MODERATORS', 'GUESTS') */
	public $canonical_name = '';

	/** @var array Raw source data for traceability */
	public $raw_source_data = [];
}
