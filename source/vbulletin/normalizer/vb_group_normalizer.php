<?php
/**
 * phpBB Migration Center Extension
 *
 * @copyright (c) 2026 phpBB SEO Team
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace phpbbseo\migrationcenter\source\vbulletin\normalizer;

use phpbbseo\migrationcenter\core\dto\group_dto;

/**
 * vBulletin 3.8 / 4.2 Group Normalizer
 */
class vb_group_normalizer
{
	/**
	 * Canonical vBulletin 3.8/4.2 default system group IDs
	 */
	protected const CANONICAL_VB_GROUPS = [
		1 => 'GUESTS',             // Unregistered / Not Logged In
		2 => 'REGISTERED',         // Registered Users
		3 => 'REGISTERED',         // Users Awaiting Email Confirmation (mapped to REGISTERED group identity)
		4 => 'REGISTERED',         // (COPPA) Users Awaiting Moderation (mapped to REGISTERED group identity)
		5 => 'GLOBAL_MODERATORS',  // Super Moderators (Global Moderation)
		6 => 'ADMINISTRATORS',     // Administrators
	];

	/**
	 * Normalize vBulletin usergroup row into generic group_dto
	 *
	 * @param array $row
	 * @return group_dto
	 */
	public static function normalize(array $row): group_dto
	{
		$dto = new group_dto();
		$group_id = (int)($row['usergroupid'] ?? 0);
		$dto->source_id = $group_id;

		// 1. Group Title & Unicode Sanitization
		$title = trim((string)($row['title'] ?? ''));
		$dto->group_name = self::sanitize_text($title);
		$dto->group_name_clean = mb_strtolower($dto->group_name, 'UTF-8');

		// 2. Group Description
		$desc = trim((string)($row['description'] ?? ''));
		$dto->group_desc = self::sanitize_text($desc);

		// 3. User Title
		$dto->user_title = self::sanitize_text(trim((string)($row['usertitle'] ?? '')));

		// 4. Safe Group Color Extraction from opentag
		$opentag = (string)($row['opentag'] ?? '');
		$dto->group_colour = self::extract_hex_colour($opentag);

		// 5. Group Type (0 = OPEN, 1 = CLOSED, 2 = HIDDEN)
		$is_public = (int)($row['ispublicgroup'] ?? 0);
		$dto->group_type = ($is_public === 1) ? 0 : 1;

		// 6. Display Style Priority
		$dto->display_style_priority = (int)($row['displayorder'] ?? 0);

		// 7. Canonical Mapping Policy
		if (isset(self::CANONICAL_VB_GROUPS[$group_id]))
		{
			$dto->is_builtin = true;
			$dto->canonical_name = self::CANONICAL_VB_GROUPS[$group_id];
		}
		else
		{
			// Group 7 (Moderators) -> Custom group in phpBB (forum-scoped permissions in Phase D)
			// Group 8 (Banned Users) -> Custom group in phpBB (login bans in Phase K)
			// Group > 8 -> Custom groups
			$dto->is_builtin = false;
			$dto->canonical_name = '';
		}

		$dto->raw_source_data = $row;
		return $dto;
	}

	/**
	 * Extract a valid 6-digit hex colour from opentag HTML
	 *
	 * @param string $opentag
	 * @return string (6 hex characters without #, or empty string)
	 */
	public static function extract_hex_colour(string $opentag): string
	{
		if (empty($opentag))
		{
			return '';
		}

		// Look for hex colors (#FFFFFF or #FFF) in style="color:..." or color="..."
		if (preg_match('/(?:color\s*[:=]\s*["\']?\s*#?([0-9a-fA-F]{6})\b)/i', $opentag, $m))
		{
			return strtoupper($m[1]);
		}

		if (preg_match('/(?:color\s*[:=]\s*["\']?\s*#?([0-9a-fA-F]{3})\b)/i', $opentag, $m))
		{
			$r = $m[1][0] . $m[1][0];
			$g = $m[1][1] . $m[1][1];
			$b = $m[1][2] . $m[1][2];
			return strtoupper($r . $g . $b);
		}

		// Named colors mapping
		$named_colors = [
			'red'    => 'FF0000',
			'green'  => '008000',
			'blue'   => '0000FF',
			'orange' => 'FFA500',
			'yellow' => 'FFFF00',
			'purple' => '800080',
			'black'  => '000000',
			'gray'   => '808080',
			'grey'   => '808080',
			'navy'   => '000080',
			'teal'   => '008080',
			'maroon' => '800000',
			'cyan'   => '00FFFF',
		];

		if (preg_match('/(?:color\s*[:=]\s*["\']?([a-zA-Z]+)["\'\s;>])/i', $opentag, $m))
		{
			$name = strtolower($m[1]);
			if (isset($named_colors[$name]))
			{
				return $named_colors[$name];
			}
		}

		return '';
	}

	/**
	 * Sanitize text and strip raw HTML tags
	 *
	 * @param string $text
	 * @return string
	 */
	public static function sanitize_text(string $text): string
	{
		if ($text === '')
		{
			return '';
		}

		// Strip tags
		$clean = strip_tags($text);

		// Remove control characters except standard whitespace
		$clean = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $clean);

		return trim($clean);
	}
}
