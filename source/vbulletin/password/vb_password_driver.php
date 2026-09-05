<?php
/**
 * phpBB Migration Center Extension
 *
 * @copyright (c) 2026 phpBB SEO Team
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace phpbbseo\migrationcenter\source\vbulletin\password;

use phpbb\passwords\driver\base;

/**
 * phpBB Password Driver for Legacy vBulletin 3.8 / 4.2 Hashes
 *
 * Storage format:
 * $mcvb$1$[32_char_hex_md5]$[base64_encoded_salt]
 */
class vb_password_driver extends base
{
	const PREFIX = '$mcvb$';

	/**
	 * {@inheritdoc}
	 */
	public function get_prefix()
	{
		return self::PREFIX;
	}

	/**
	 * {@inheritdoc}
	 */
	public function is_legacy()
	{
		return true;
	}

	/**
	 * {@inheritdoc}
	 */
	public function is_supported()
	{
		return true;
	}

	/**
	 * {@inheritdoc}
	 */
	public function hash($password, $user_row = '')
	{
		// Legacy driver does not generate new hashes
		return false;
	}

	/**
	 * {@inheritdoc}
	 */
	public function check($password, $hash, $user_row = array())
	{
		if (empty($password) || empty($hash) || strlen($password) > 4096)
		{
			return false;
		}

		// Ensure prefix matches
		if (strpos($hash, self::PREFIX) !== 0)
		{
			return false;
		}

		// Expected format: $mcvb$1$[32-hex-hash]$[base64-salt]
		$parts = explode('$', $hash);
		if (count($parts) < 5 || $parts[1] !== 'mcvb')
		{
			return false;
		}

		$version = $parts[2];
		$stored_hash = strtolower(trim($parts[3]));
		$b64_salt = trim($parts[4]);

		if ($version !== '1' || strlen($stored_hash) !== 32 || !ctype_xdigit($stored_hash))
		{
			return false;
		}

		$salt = base64_decode($b64_salt, true);
		if ($salt === false || strlen($salt) === 0 || strlen($salt) > 255)
		{
			return false;
		}

		$calculated_hash = md5(md5((string)$password) . $salt);

		return hash_equals($stored_hash, $calculated_hash);
	}
}
