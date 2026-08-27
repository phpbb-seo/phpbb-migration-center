<?php
/**
 * phpBB Migration Center Extension
 *
 * @copyright (c) 2026 phpBB SEO Team
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace phpbbseo\migrationcenter\source\xenforo\storage;

/**
 * XenForo Avatar Path Resolver with Strict Storage Root Containment & Size Fallback
 */
class xf_avatar_path_resolver
{
	/**
	 * Resolve source absolute avatar file path with size precedence
	 *
	 * @param string $source_root Absolute path to XenForo installation root
	 * @param int $user_id XenForo user ID
	 * @param string|null $custom_storage_root Optional custom storage root
	 * @return array|null Returns ['path' => string, 'size' => string, 'extension' => string] or null
	 */
	public static function resolve_path(
		string $source_root,
		int $user_id,
		?string $custom_storage_root = null
	): ?array {
		$source_root = rtrim(str_replace('\\', '/', $source_root), '/');
		if ($source_root === '' || !is_dir($source_root))
		{
			return null;
		}

		$real_root = realpath($source_root);
		if (!$real_root)
		{
			return null;
		}
		$canonical_root = rtrim(str_replace('\\', '/', $real_root), '/');

		// Restrict strictly to data/avatars/
		$storage_root = $custom_storage_root ?: ($canonical_root . '/data/avatars');
		$real_storage_root = realpath($storage_root);
		if (!$real_storage_root || !is_dir($real_storage_root))
		{
			return null;
		}

		// Separator-aware prefix for containment check
		$canonical_storage_root = rtrim(str_replace('\\', '/', $real_storage_root), '/') . '/';

		$group = (int)floor($user_id / 1000);
		$sizes = ['o', 'l', 'm', 's'];
		$extensions = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

		$is_windows = (DIRECTORY_SEPARATOR === '\\');
		$canon_storage_check = rtrim($canonical_storage_root, '/');

		foreach ($sizes as $size)
		{
			foreach ($extensions as $ext)
			{
				$candidate = sprintf(
					'%s%s/%d/%d.%s',
					$canonical_storage_root,
					$size,
					$group,
					$user_id,
					$ext
				);

				$cand_norm = str_replace('\\', '/', $candidate);
				if (file_exists($cand_norm) && is_file($cand_norm) && is_readable($cand_norm))
				{
					$real_cand = realpath($cand_norm);
					if ($real_cand)
					{
						$canon_cand = rtrim(str_replace('\\', '/', $real_cand), '/');

						$matches_root = $is_windows
							? (stripos($canon_cand, $canon_storage_check . '/') === 0 || strcasecmp($canon_cand, $canon_storage_check) === 0)
							: (strpos($canon_cand, $canon_storage_check . '/') === 0 || strcmp($canon_cand, $canon_storage_check) === 0);

						if ($matches_root)
						{
							return [
								'path'      => $canon_cand,
								'size'      => $size,
								'extension' => $ext,
							];
						}
					}
				}
			}
		}

		return null;
	}
}
