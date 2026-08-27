<?php
/**
 * phpBB Migration Center Extension
 *
 * @copyright (c) 2026 phpBB SEO Team
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace phpbbseo\migrationcenter\source\xenforo\storage;

/**
 * XenForo Physical Attachment Path Resolver with Strict Storage Root Containment
 */
class xf_attachment_path_resolver
{
	/**
	 * Resolve source absolute attachment file path
	 *
	 * @param string $source_root Absolute path to XenForo installation root
	 * @param int $data_id Attachment data ID
	 * @param string $file_key File key (XF 2.1+)
	 * @param string $file_hash File hash (XF 2.0)
	 * @param string $file_path Custom file path template if present
	 * @param string|null $custom_storage_root Optional verified custom storage root
	 * @return string|null Returns null if file not found or path traversal detected
	 */
	public static function resolve_path(
		string $source_root,
		int $data_id,
		string $file_key = '',
		string $file_hash = '',
		string $file_path = '',
		?string $custom_storage_root = null
	): ?string {
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

		// Exact configured attachment storage root (Issue 1)
		$storage_root = $custom_storage_root ?: ($canonical_root . '/internal_data/attachments');
		$real_storage_root = realpath($storage_root);
		if (!$real_storage_root || !is_dir($real_storage_root))
		{
			return null;
		}

		// Separator-aware prefix for containment check (prevents prefix confusion like attachments_evil)
		$canonical_storage_root = rtrim(str_replace('\\', '/', $real_storage_root), '/') . '/';

		$group = (int)floor($data_id / 1000);
		$hash_to_use = $file_key ?: $file_hash;

		$candidate_paths = [];

		// 1. If custom file_path template is specified in xf_attachment_data
		if (!empty($file_path))
		{
			$placeholders = [
				'%INTERNAL%' => 'internal_data/',
				'%DATA%'     => 'data/',
				'%DATA_ID%'  => $data_id,
				'%FLOOR%'    => $group,
				'%HASH%'     => $hash_to_use,
			];
			$custom = strtr($file_path, $placeholders);
			$custom = str_replace(['internal-data://', 'data://'], ['internal_data/', 'data/'], $custom);
			$candidate_paths[] = $canonical_root . '/' . ltrim($custom, '/');
		}

		// 2. Standard XenForo 2.1+ path: internal_data/attachments/{group}/{data_id}-{file_key}.data
		if (!empty($file_key))
		{
			$candidate_paths[] = sprintf(
				'%s%d/%d-%s.data',
				$canonical_storage_root,
				$group,
				$data_id,
				$file_key
			);
		}

		// 3. Standard XenForo 2.0 path: internal_data/attachments/{group}/{data_id}-{file_hash}.data
		if (!empty($file_hash))
		{
			$candidate_paths[] = sprintf(
				'%s%d/%d-%s.data',
				$canonical_storage_root,
				$group,
				$data_id,
				$file_hash
			);
		}

		// 4. Fallback without hash: internal_data/attachments/{group}/{data_id}.data
		$candidate_paths[] = sprintf(
			'%s%d/%d.data',
			$canonical_storage_root,
			$group,
			$data_id
		);

		foreach ($candidate_paths as $cand)
		{
			$cand_norm = str_replace('\\', '/', $cand);
			if (file_exists($cand_norm) && is_file($cand_norm) && is_readable($cand_norm))
			{
				$real_cand = realpath($cand_norm);
				if ($real_cand)
				{
					$canon_cand = rtrim(str_replace('\\', '/', $real_cand), '/');
					$canon_storage_check = rtrim($canonical_storage_root, '/');

					// Case-insensitive containment check for Windows and case-sensitive for Unix
					$is_windows = (DIRECTORY_SEPARATOR === '\\');
					$matches_root = $is_windows 
						? (stripos($canon_cand, $canon_storage_check . '/') === 0 || strcasecmp($canon_cand, $canon_storage_check) === 0)
						: (strpos($canon_cand, $canon_storage_check . '/') === 0 || strcmp($canon_cand, $canon_storage_check) === 0);

					if ($matches_root)
					{
						return $canon_cand;
					}
				}
			}
		}

		return null;
	}
}
