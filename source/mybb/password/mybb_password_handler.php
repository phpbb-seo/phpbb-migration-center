<?php
/**
 * phpBB Migration Center Extension
 *
 * @copyright (c) 2026 phpBB SEO Team
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace phpbbseo\migrationcenter\source\mybb\password;

use phpbbseo\migrationcenter\core\contract\password_handler_interface;

/**
 * MyBB Password Handler
 */
class mybb_password_handler implements password_handler_interface
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

		return (strlen($hash) === 32 && ctype_xdigit($hash));
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
		$salt = $parsed['salt'] ?? '';

		if (empty($hash))
		{
			return [
				'hash'           => '',
				'type'           => 'none',
				'requires_reset' => false,
			];
		}

		if (strlen($hash) === 32 && ctype_xdigit($hash))
		{
			return [
				'hash'           => self::encode_legacy_password($hash, $salt),
				'type'           => 'mybb',
				'requires_reset' => false,
			];
		}

		return [
			'hash'           => '',
			'type'           => 'unsupported',
			'requires_reset' => true,
		];
	}

	/**
	 * Encode legacy MyBB MD5 hash and salt into storage format
	 *
	 * @param string $md5_hash
	 * @param string $salt
	 * @return string
	 */
	public static function encode_legacy_password(string $md5_hash, string $salt): string
	{
		return '$mcmybb$1$' . strtolower(trim($md5_hash)) . '$' . base64_encode($salt);
	}

	/**
	 * Parse authentication data safely
	 *
	 * @param string|array $auth_data
	 * @return array
	 */
	protected function parse_auth_data($auth_data): array
	{
		if (is_array($auth_data))
		{
			return [
				'hash' => (string)($auth_data['password'] ?? $auth_data['hash'] ?? ''),
				'salt' => (string)($auth_data['salt'] ?? ''),
			];
		}

		if (is_string($auth_data))
		{
			return [
				'hash' => trim($auth_data),
				'salt' => '',
			];
		}

		return ['hash' => '', 'salt' => ''];
	}
}
