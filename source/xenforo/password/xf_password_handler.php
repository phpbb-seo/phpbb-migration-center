<?php
/**
 * phpBB Migration Center Extension
 *
 * @copyright (c) 2026 phpBB SEO Team
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace phpbbseo\migrationcenter\source\xenforo\password;

use phpbbseo\migrationcenter\core\contract\password_handler_interface;

/**
 * XenForo Password Compatibility Handler
 */
class xf_password_handler implements password_handler_interface
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

		switch ($scheme_class)
		{
			case 'XF:Core12':
			case 'XF:Core':
				if (!empty($parsed['hash']))
				{
					$hash = $parsed['hash'];
					// Check for bcrypt ($2y$, $2a$, $2b$) or phpass ($P$, $H$)
					return (bool)preg_match('/^(?:\$2[yab]\$|\$[PH]\$)/', $hash);
				}
				return false;

			case 'XF:NoPassword':
				return true;

			case 'XF:PasswordHash':
				if (!empty($parsed['hash']))
				{
					return (bool)preg_match('/^\$[PH]\$/', $parsed['hash']);
				}
				return false;

			default:
				return false;
		}
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

		switch ($scheme_class)
		{
			case 'XF:Core12':
			case 'XF:Core':
				if (!empty($parsed['hash']))
				{
					$hash = $parsed['hash'];
					// Bcrypt formats ($2y$, $2a$, $2b$)
					if (preg_match('/^\$2[yab]\$/', $hash))
					{
						return [
							'hash'           => $hash,
							'type'           => 'bcrypt',
							'requires_reset' => false,
						];
					}
					// Legacy phpass formats ($P$, $H$)
					else if (preg_match('/^\$[PH]\$/', $hash))
					{
						return [
							'hash'           => $hash,
							'type'           => 'phpass',
							'requires_reset' => false,
						];
					}
				}
				break;

			case 'XF:NoPassword':
				return [
					'hash'           => '',
					'type'           => 'none',
					'requires_reset' => false,
				];

			case 'XF:PasswordHash':
				if (!empty($parsed['hash']) && preg_match('/^\$[PH]\$/', $parsed['hash']))
				{
					return [
						'hash'           => $parsed['hash'],
						'type'           => 'phpass',
						'requires_reset' => false,
					];
				}
				break;
		}

		// Unsupported scheme - require password reset
		return [
			'hash'           => '',
			'type'           => 'unsupported',
			'requires_reset' => true,
		];
	}

	/**
	 * Safely parse serialized authentication data
	 *
	 * @param string|array $auth_data
	 * @return array
	 */
	public function parse_auth_data($auth_data): array
	{
		if (is_array($auth_data))
		{
			return $auth_data;
		}

		if (!is_string($auth_data) || $auth_data === '')
		{
			return [];
		}

		$unserialized = @unserialize($auth_data, ['allowed_classes' => false]);
		if (is_array($unserialized))
		{
			return $unserialized;
		}

		return [];
	}
}
