<?php
/**
 * phpBB Migration Center Extension
 *
 * @copyright (c) 2026 phpBB SEO Team
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace phpbbseo\migrationcenter\tests\unit\source\smf;

use phpbbseo\migrationcenter\source\smf\password\smf_password_driver;
use phpbbseo\migrationcenter\source\smf\password\smf_password_handler;

/**
 * Unit Test for SMF 2.x Password Driver & Encoding
 */
class SmfPasswordDriverTest
{
	public function run(): array
	{
		$results = [];
		global $phpbb_container;

		$cfg = $phpbb_container ? $phpbb_container->get('config') : (class_exists(\phpbb\config\config::class) ? new \phpbb\config\config([]) : null);
		$hlp = $phpbb_container ? $phpbb_container->get('passwords.driver_helper') : (class_exists(\phpbb\passwords\driver\helper::class) && $cfg ? new \phpbb\passwords\driver\helper($cfg) : null);
		$driver = new smf_password_driver($cfg ?? new \phpbb\config\config([]), $hlp ?? new \phpbb\passwords\driver\helper($cfg ?? new \phpbb\config\config([])));
		$handler = new smf_password_handler();

		// 1. Prefix and Driver properties
		$results['driver_prefix_is_mcsmf']   = ($driver->get_prefix() === '$mcsmf$');
		$results['driver_is_legacy']          = ($driver->is_legacy() === true);
		$results['driver_hash_returns_false'] = ($driver->hash('any_password') === false);

		// 2. SMF 2.1 Bcrypt Verification (formula: password_verify(strtolower($username) . $password, $hash))
		$user1 = 'Michael_Brown';
		$pass1 = 'Secret123!';
		$raw_bcrypt = password_hash(strtolower($user1) . $pass1, PASSWORD_BCRYPT, ['cost' => 10]);
		$encoded_bcrypt = smf_password_handler::encode_legacy_password($raw_bcrypt, $user1, 2);

		$results['smf21_bcrypt_valid_authenticated'] = ($driver->check($pass1, $encoded_bcrypt) === true);
		$results['smf21_bcrypt_wrong_pw_rejected']    = ($driver->check('WrongPass!', $encoded_bcrypt) === false);

		// 3. SMF 2.0 SHA1 Verification (formula: sha1(strtolower($username) . $password))
		$user2 = 'Reza_Dev';
		$pass2 = '123456';
		$raw_sha1 = sha1(strtolower($user2) . $pass2);
		$encoded_sha1 = smf_password_handler::encode_legacy_password($raw_sha1, $user2, 1);

		$results['smf20_sha1_valid_authenticated'] = ($driver->check($pass2, $encoded_sha1) === true);
		$results['smf20_sha1_wrong_pw_rejected']    = ($driver->check('654321', $encoded_sha1) === false);

		// 4. Persian / Multilingual Unicode Verification
		$user_fa = 'علی_رضایی';
		$pass_fa = 'رمز_عبور_امن_۲۰۲۶';
		$raw_bcrypt_fa = password_hash(mb_strtolower($user_fa, 'UTF-8') . $pass_fa, PASSWORD_BCRYPT, ['cost' => 10]);
		$encoded_bcrypt_fa = smf_password_handler::encode_legacy_password($raw_bcrypt_fa, $user_fa, 2);

		$results['smf21_persian_unicode_authenticated'] = ($driver->check($pass_fa, $encoded_bcrypt_fa) === true);

		// 5. Malformed Hash Protection
		$results['reject_wrong_prefix']   = ($driver->check($pass1, '$wrong_prefix$2$abc$def') === false);
		$results['reject_unknown_version'] = ($driver->check($pass1, '$mcsmf$3$' . base64_encode($user1) . '$hash') === false);
		$results['reject_invalid_b64']    = ($driver->check($pass1, '$mcsmf$2$!@#invalid_b64$hash') === false);
		$results['reject_empty_password'] = ($driver->check('', $encoded_bcrypt) === false);
		$results['reject_empty_hash']     = ($driver->check($pass1, '') === false);

		// 6. Password Handler Conversion
		$conv_bc = $handler->convert_password('smf', ['passwd' => $raw_bcrypt, 'member_name' => $user1]);
		$results['handler_convert_bcrypt'] = ($conv_bc['hash'] === $encoded_bcrypt && $conv_bc['type'] === 'smf' && $conv_bc['requires_reset'] === false);

		$conv_sh = $handler->convert_password('smf', ['passwd' => $raw_sha1, 'member_name' => $user2]);
		$results['handler_convert_sha1'] = ($conv_sh['hash'] === $encoded_sha1 && $conv_sh['type'] === 'smf' && $conv_sh['requires_reset'] === false);

		$conv_empty = $handler->convert_password('smf', ['passwd' => '', 'member_name' => $user1]);
		$results['handler_convert_empty'] = ($conv_empty['hash'] === '' && $conv_empty['type'] === 'none' && $conv_empty['requires_reset'] === false);

		return $results;
	}
}
