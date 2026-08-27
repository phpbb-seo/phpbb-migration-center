<?php
/**
 * phpBB Migration Center Extension
 *
 * @copyright (c) 2026 phpBB SEO Team
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace phpbbseo\migrationcenter\source\xenforo\normalizer;

use phpbbseo\migrationcenter\core\dto\avatar_dto;
use phpbbseo\migrationcenter\core\dto\migration_config_dto;
use phpbbseo\migrationcenter\source\xenforo\storage\xf_avatar_path_resolver;

/**
 * XenForo Avatar Normalizer & Validator
 */
class xf_avatar_normalizer
{
	/**
	 * Normalize a user row into AvatarDto
	 *
	 * @param array $row
	 * @param migration_config_dto $config
	 * @return avatar_dto
	 */
	public function normalize_avatar(array $row, migration_config_dto $config): avatar_dto
	{
		$dto = new avatar_dto();
		$dto->user_source_id = (int)$row['user_id'];
		$dto->avatar_date = (int)($row['avatar_date'] ?? 0);
		$dto->gravatar_email = trim((string)($row['gravatar'] ?? ''));
		$dto->raw_source_data = $row;

		$source_path = $config->source_path ?: '';

		// 1. Check Gravatar
		if (!empty($dto->gravatar_email))
		{
			$dto->avatar_type = 'gravatar';
			$dto->target_width = 80;
			$dto->target_height = 80;
			return $dto;
		}

		// 2. Check Uploaded Custom Avatar
		if ($dto->avatar_date > 0)
		{
			$resolved = xf_avatar_path_resolver::resolve_path($source_path, $dto->user_source_id);
			if ($resolved)
			{
				$dto->avatar_type = 'upload';
				$dto->source_physical_path = $resolved['path'];
				$dto->source_size_variant = $resolved['size'];
				$dto->extension = $resolved['extension'];

				$size_bytes = @filesize($resolved['path']);
				$dto->source_filesize = ($size_bytes !== false) ? $size_bytes : 0;
				$dto->source_sha256 = hash_file('sha256', $resolved['path']) ?: '';

				// Validate Image Dimensions & Real MIME
				$info = @getimagesize($resolved['path']);
				if ($info && !empty($info[0]) && !empty($info[1]))
				{
					$dto->source_width = (int)$info[0];
					$dto->source_height = (int)$info[1];
					$dto->mimetype = (string)($info['mime'] ?? 'image/jpeg');
				}
				else
				{
					$dto->warnings[] = "Avatar file for user {$dto->user_source_id} failed image dimension decoding";
				}
			}
			else
			{
				$dto->avatar_type = 'upload';
				$dto->warnings[] = "Physical avatar file not found for user {$dto->user_source_id}";
			}

			return $dto;
		}

		// 3. Default (No Avatar)
		$dto->avatar_type = 'default';
		return $dto;
	}
}
