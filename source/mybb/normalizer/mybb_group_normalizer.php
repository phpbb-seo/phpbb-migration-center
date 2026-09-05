<?php
/**
 * phpBB Migration Center Extension
 *
 * @copyright (c) 2026 phpBB SEO Team
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace phpbbseo\migrationcenter\source\mybb\normalizer;

use phpbbseo\migrationcenter\core\dto\group_dto;

/**
 * MyBB Usergroup Normalizer
 */
class mybb_group_normalizer
{
	/**
	 * Normalize raw MyBB usergroup record into group_dto
	 *
	 * @param array $row
	 * @return group_dto
	 */
	public function normalize(array $row): group_dto
	{
		$dto = new group_dto();
		$dto->source_id = (int)($row['gid'] ?? 0);
		$dto->group_name = trim((string)($row['title'] ?? ''));
		$dto->group_desc = trim((string)($row['description'] ?? ''));

		// Extract color from namestyle, e.g. <span style="color: #9c27b0; ...">{username}</span>
		$style = (string)($row['namestyle'] ?? '');
		if (preg_match('/color:\s*#([a-f0-9]{6}|[a-f0-9]{3})/i', $style, $m))
		{
			$dto->group_colour = strtoupper($m[1]);
		}

		// Group type mapping
		$gid = $dto->source_id;
		if ($gid === 4)
		{
			$dto->group_type = 3; // Administrators (special)
			$dto->is_system_group = true;
		}
		else if ($gid === 3)
		{
			$dto->group_type = 3; // Super Moderators
			$dto->is_system_group = true;
		}
		else if ($gid === 6)
		{
			$dto->group_type = 3; // Moderators
			$dto->is_system_group = true;
		}
		else if ($gid === 1)
		{
			$dto->group_type = 3; // Guests
			$dto->is_system_group = true;
		}
		else if ($gid === 5)
		{
			$dto->group_type = 3; // Awaiting activation
			$dto->is_system_group = true;
		}
		else if ($gid === 7)
		{
			$dto->group_type = 3; // Banned
			$dto->is_system_group = true;
		}
		else if ($gid === 2)
		{
			$dto->group_type = 3; // Registered
			$dto->is_system_group = true;
		}
		else
		{
			$dto->group_type = 0; // Standard open custom group
			$dto->is_system_group = false;
		}

		return $dto;
	}
}
