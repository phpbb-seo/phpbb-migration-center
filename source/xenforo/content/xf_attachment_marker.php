<?php
/**
 * phpBB Migration Center Extension
 *
 * @copyright (c) 2026 phpBB SEO Team
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace phpbbseo\migrationcenter\source\xenforo\content;

/**
 * Deferred Attachment Marker Handler
 */
class xf_attachment_marker
{
	const MARKER_PREFIX = '[[MC_ATTACH:';
	const MARKER_SUFFIX = ']]';
	const MARKER_REGEX  = '/\[\[MC_ATTACH:(\d+)\]\]/';

	/**
	 * Create deferred marker for a source attachment ID
	 *
	 * @param int $source_attachment_id
	 * @return string
	 */
	public static function create_marker(int $source_attachment_id): string
	{
		return self::MARKER_PREFIX . (int)$source_attachment_id . self::MARKER_SUFFIX;
	}

	/**
	 * Check if text contains attachment markers
	 *
	 * @param string $text
	 * @return bool
	 */
	public static function has_markers(string $text): bool
	{
		return (strpos($text, self::MARKER_PREFIX) !== false);
	}

	/**
	 * Extract all source attachment IDs present in text markers
	 *
	 * @param string $text
	 * @return array List of unique integer source attachment IDs
	 */
	public static function extract_attachment_ids(string $text): array
	{
		if (!self::has_markers($text))
		{
			return [];
		}

		if (preg_match_all(self::MARKER_REGEX, $text, $matches))
		{
			return array_values(array_unique(array_map('intval', $matches[1])));
		}

		return [];
	}

	/**
	 * Replace attachment markers with replacement values via callback
	 *
	 * @param string $text
	 * @param callable $callback function(int $source_attachment_id): string
	 * @return string
	 */
	public static function replace_markers(string $text, callable $callback): string
	{
		if (!self::has_markers($text))
		{
			return $text;
		}

		return preg_replace_callback(self::MARKER_REGEX, function ($m) use ($callback) {
			$src_id = (int)$m[1];
			return $callback($src_id);
		}, $text);
	}
}
