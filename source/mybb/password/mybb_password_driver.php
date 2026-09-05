<?php
/**
 * phpBB Migration Center Extension
 *
 * @copyright (c) 2026 phpBB SEO Team
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace phpbbseo\migrationcenter\source\mybb\password;

use phpbb\passwords\driver\base;

/**
 * phpBB Password Driver for MyBB 1.8 Hashes
 *
 * Storage format:
 * $mcmybb$1$[32_char_hex_md5]$[base64_encoded_salt]
 *
 * Algorithm:
 * md5(md5($salt) . md5($password))
 */
class mybb_password_driver extends base
{
	const PREFIX = '$mcmybb$';

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

		if (strpos($hash, self::PREFIX) !== 0)
		{
			return false;
		}

		// Expected format: $mcmybb$1$[32-hex-hash]$[base64-salt]
		$parts = explode('$', $hash);
		if (count($parts) < 5 || $parts[1] !== 'mcmybb')
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

		// MyBB 1.8 Password hashing algorithm: md5(md5($salt) . md5($password))
		$calculated_hash = md5(md5($salt) . md5((string)$password));

		return hash_equals($stored_hash, $calculated_hash);
	}
}
