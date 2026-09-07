<?php
/**
 * phpBB Migration Center Extension
 *
 * @copyright (c) 2026 phpBB SEO Team
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace phpbbseo\migrationcenter\source\smf\normalizer;

use phpbbseo\migrationcenter\core\dto\group_dto;

/**
 * SMF 2.x Usergroup Normalizer
 */
class smf_group_normalizer
{
	/**
	 * Normalize raw SMF membergroup record into group_dto
	 *
	 * @param array $row
	 * @return group_dto
	 */
	public function normalize(array $row): group_dto
	{
		$dto = new group_dto();
		$dto->source_id = (int)($row['id_group'] ?? 0);
		$dto->group_name = trim((string)($row['group_name'] ?? ''));
		$dto->group_desc = trim((string)($row['description'] ?? ''));

		// Online color, e.g. #FF0000 -> FF0000
		$color = trim((string)($row['online_color'] ?? ''));
		if (!empty($color))
		{
			$clean_color = ltrim($color, '#');
			if (preg_match('/^[a-f0-9]{6}$/i', $clean_color))
			{
				$dto->group_colour = strtoupper($clean_color);
			}
		}

		// SMF System Group mapping
		$gid = $dto->source_id;
		if ($gid === 1)
		{
			$dto->group_type = 3; // Administrators (special)
			$dto->is_system_group = true;
			$dto->is_builtin = true;
			$dto->canonical_name = 'ADMINISTRATORS';
		}
		else if ($gid === 2)
		{
			$dto->group_type = 3; // Global Moderators
			$dto->is_system_group = true;
			$dto->is_builtin = true;
			$dto->canonical_name = 'GLOBAL_MODERATORS';
		}
		else if ($gid === 3)
		{
			$dto->group_type = 3; // Moderators
			$dto->is_system_group = true;
			$dto->is_builtin = false;
			$dto->canonical_name = '';
		}
		else if ($gid === 0)
		{
			$dto->group_type = 3; // Regular Members
			$dto->is_system_group = true;
			$dto->is_builtin = true;
			$dto->canonical_name = 'REGISTERED';
		}
		else if ($gid === -1)
		{
			$dto->group_type = 3; // Guests
			$dto->is_system_group = true;
			$dto->is_builtin = true;
			$dto->canonical_name = 'GUESTS';
		}
		else
		{
			$dto->group_type = 0; // Standard open/custom group
			$dto->is_system_group = false;
			$dto->is_builtin = false;
			$dto->canonical_name = '';
		}

		return $dto;
	}
}
