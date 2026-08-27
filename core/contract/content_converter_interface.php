<?php
/**
 * phpBB Migration Center Extension
 *
 * @copyright (c) 2026 phpBB SEO Team
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace phpbbseo\migrationcenter\core\contract;

/**
 * Content Converter Interface (XenForo / Source BBCode -> phpBB BBCode)
 */
interface content_converter_interface
{
	/**
	 * Convert source message/post content to phpBB formatted message
	 *
	 * @param string $content Raw source text/BBCode
	 * @param array $context Additional context (e.g. author_id, post_id, attachments map)
	 * @return string Converted phpBB text
	 */
	public function convert(string $content, array $context = []): string;
}
