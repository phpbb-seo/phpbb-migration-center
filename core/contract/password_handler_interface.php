<?php
/**
 * phpBB Migration Center Extension
 *
 * @copyright (c) 2026 phpBB SEO Team
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace phpbbseo\migrationcenter\core\contract;

/**
 * Password Handler Interface
 */
interface password_handler_interface
{
	/**
	 * Detect whether the given source authentication scheme and data is directly supported
	 *
	 * @param string $scheme_class
	 * @param string|array $auth_data
	 * @return bool
	 */
	public function is_supported(string $scheme_class, $auth_data): bool;

	/**
	 * Convert source password hash data to phpBB compatible format
	 *
	 * @param string $scheme_class
	 * @param string|array $auth_data
	 * @return array ['hash' => string, 'type' => string, 'requires_reset' => bool]
	 */
	public function convert_password(string $scheme_class, $auth_data): array;
}
