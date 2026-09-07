<?php
/**
 * phpBB Migration Center Extension
 *
 * @copyright (c) 2026 phpBB SEO Team
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace phpbbseo\migrationcenter\source\smf\password;

use phpbbseo\migrationcenter\core\contract\password_handler_interface;

/**
 * SMF Password Handler
 */
class smf_password_handler implements password_handler_interface
{
	/**
	 * Detect whether the given source authentication scheme and data is directly supported
	 *
	 * @param string $scheme_class
	 * @param string|array $auth_data
	 * @return bool
	 */
	public function is_supported(string $scheme_class, $auth_data): bool
	{
		$parsed = $this->parse_auth_data($auth_data);
		$hash = $parsed['hash'] ?? '';

		if (empty($hash))
		{
			return true;
		}

		// SMF 2.1 bcrypt ($2y$, $2a$, $2b$) or SMF 2.0 sha1 (40 hex chars)
		if (preg_match('/^\$2[ayb]\$\d{2}\$/', $hash))
		{
			return true;
		}

		if (strlen($hash) === 40 && ctype_xdigit($hash))
		{
			return true;
		}

		return false;
	}

	/**
	 * Encode legacy password with prefix, version, base64 username, and hash
	 *
	 * @param string $hash
	 * @param string $username
	 * @param int $version
	 * @return string
	 */
	public static function encode_legacy_password(string $hash, string $username, int $version = 2): string
	{
		return smf_password_driver::PREFIX . $version . '$' . base64_encode($username) . '$' . $hash;
	}

	/**
	 * Convert source password hash data to phpBB compatible format
	 *
	 * @param string $scheme_class
	 * @param string|array $auth_data
	 * @return array ['hash' => string, 'type' => string, 'requires_reset' => bool]
	 */
	public function convert_password(string $scheme_class, $auth_data): array
	{
		$parsed = $this->parse_auth_data($auth_data);
		$hash = $parsed['hash'] ?? '';
		$username = $parsed['username'] ?? '';

		if (empty($hash))
		{
			return [
				'hash'           => '',
				'type'           => 'none',
				'requires_reset' => false,
			];
		}

		// SMF 2.1 Bcrypt
		if (preg_match('/^\$2[ayb]\$\d{2}\$/', $hash))
		{
			return [
				'hash'           => self::encode_legacy_password($hash, $username, 2),
				'type'           => 'smf',
				'requires_reset' => false,
			];
		}

		// SMF 2.0 SHA1
		if (strlen($hash) === 40 && ctype_xdigit($hash))
		{
			return [
				'hash'           => self::encode_legacy_password(strtolower($hash), $username, 1),
				'type'           => 'smf',
				'requires_reset' => false,
			];
		}

		// Unsupported hash format
		return [
			'hash'           => '',
			'type'           => 'unsupported',
			'requires_reset' => true,
		];
	}

	/**
	 * Helper to parse raw auth data array or string
	 *
	 * @param mixed $auth_data
	 * @return array
	 */
	protected function parse_auth_data($auth_data): array
	{
		if (is_array($auth_data))
		{
			return [
				'hash'     => trim((string)($auth_data['passwd'] ?? $auth_data['hash'] ?? '')),
				'username' => trim((string)($auth_data['member_name'] ?? $auth_data['username'] ?? '')),
			];
		}

		return [
			'hash'     => trim((string)$auth_data),
			'username' => '',
		];
	}
}
