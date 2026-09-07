<?php
/**
 * phpBB Migration Center Extension
 *
 * @copyright (c) 2026 phpBB SEO Team
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace phpbbseo\migrationcenter\source\smf\password;

use phpbb\passwords\driver\base;

/**
 * phpBB Password Driver for SMF 2.x Hashes
 *
 * Storage format:
 * - SMF 2.1 (Bcrypt): $mcsmf$2$[base64_username]$[bcrypt_hash]
 * - SMF 2.0 (SHA1):   $mcsmf$1$[base64_username]$[40_hex_sha1]
 */
class smf_password_driver extends base
{
	const PREFIX = '$mcsmf$';

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

		// Expected format: $mcsmf$[version]$[base64_username]$[hash]
		$parts = explode('$', $hash);
		if (count($parts) < 5 || $parts[1] !== 'mcsmf')
		{
			return false;
		}

		$version = $parts[2];
		$b64_user = $parts[3];
		// In case bcrypt hash has internal $ characters (e.g. $2y$10$...), rejoin remaining parts!
		$stored_hash = implode('$', array_slice($parts, 4));

		$username = base64_decode($b64_user, true);
		if ($username === false || $username === '' || empty($stored_hash))
		{
			return false;
		}

		$clean_user = mb_strtolower($username, 'UTF-8');

		if ($version === '2')
		{
			// SMF 2.1 bcrypt algorithm: password_verify(strtolower($username) . $password, $hash)
			return password_verify($clean_user . (string)$password, $stored_hash);
		}
		else if ($version === '1')
		{
			// SMF 2.0 sha1 algorithm: sha1(strtolower($username) . $password)
			$calculated = sha1($clean_user . (string)$password);
			return hash_equals(strtolower($stored_hash), strtolower($calculated));
		}

		return false;
	}
}
