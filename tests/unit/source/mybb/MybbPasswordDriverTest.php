<?php
/**
 * phpBB Migration Center Extension
 *
 * @copyright (c) 2026 phpBB SEO Team
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace phpbbseo\migrationcenter\tests\unit\source\mybb;

use phpbbseo\migrationcenter\source\mybb\password\mybb_password_driver;
use phpbbseo\migrationcenter\source\mybb\password\mybb_password_handler;

/**
 * Unit Test for MyBB Password Driver & Encoding
 */
class MybbPasswordDriverTest
{
	public function run(): array
	{
		$results = [];
		global $phpbb_container;

		$cfg = $phpbb_container ? $phpbb_container->get('config') : (class_exists(\phpbb\config\config::class) ? new \phpbb\config\config([]) : null);
		$hlp = $phpbb_container ? $phpbb_container->get('passwords.driver_helper') : (class_exists(\phpbb\passwords\driver\helper::class) && $cfg ? new \phpbb\passwords\driver\helper($cfg) : null);
		$driver = new mybb_password_driver($cfg ?? new \phpbb\config\config([]), $hlp ?? new \phpbb\passwords\driver\helper($cfg ?? new \phpbb\config\config([])));
		$handler = new mybb_password_handler();

		// 1. Prefix and Legacy Identification
		$results['driver_prefix_is_mcmybb']   = ($driver->get_prefix() === '$mcmybb$');
		$results['driver_is_legacy']          = ($driver->is_legacy() === true);
		$results['driver_hash_returns_false'] = ($driver->hash('any_password') === false);

		// 2. Correct Legacy Password Verification
		// MyBB formula: md5(md5($salt) . md5($password))
		$plain1 = '123456';
		$salt1  = 'k9$z/P#123@abc';
		$raw_md5_1 = md5(md5($salt1) . md5($plain1));
		$encoded1 = mybb_password_handler::encode_legacy_password($raw_md5_1, $salt1);

		$results['valid_password_authenticated'] = ($driver->check($plain1, $encoded1) === true);

		// 3. Persian / Multilingual Unicode Password Verification
		$plain_fa = 'گذرواژه_مای‌بی‌بی_۲۰۲۶';
		$salt_fa  = 'salt_fa_$!@#';
		$raw_md5_fa = md5(md5($salt_fa) . md5($plain_fa));
		$encoded_fa = mybb_password_handler::encode_legacy_password($raw_md5_fa, $salt_fa);

		$results['persian_unicode_password_authenticated'] = ($driver->check($plain_fa, $encoded_fa) === true);

		// 4. Wrong Password Rejection
		$results['wrong_password_rejected'] = ($driver->check('WrongPasswordHere', $encoded1) === false);

		// 5. Malformed Hashes Rejected Safely
		$results['reject_wrong_prefix']   = ($driver->check($plain1, '$wrong_prefix$1$abc$def') === false);
		$results['reject_wrong_version']  = ($driver->check($plain1, '$mcmybb$2$' . $raw_md5_1 . '$' . base64_encode($salt1)) === false);
		$results['reject_truncated_md5']  = ($driver->check($plain1, '$mcmybb$1$shortmd5$' . base64_encode($salt1)) === false);
		$results['reject_invalid_b64']    = ($driver->check($plain1, '$mcmybb$1$' . $raw_md5_1 . '$!!!invalid_b64!!!') === false);
		$results['reject_empty_password'] = ($driver->check('', $encoded1) === false);
		$results['reject_empty_hash']     = ($driver->check($plain1, '') === false);

		// 6. Password Handler Conversion Tests
		$conv_valid = $handler->convert_password('mybb', ['password' => $raw_md5_1, 'salt' => $salt1]);
		$results['handler_convert_valid'] = ($conv_valid['hash'] === $encoded1 && $conv_valid['type'] === 'mybb' && $conv_valid['requires_reset'] === false);

		$conv_empty = $handler->convert_password('mybb', ['password' => '', 'salt' => '']);
		$results['handler_convert_empty'] = ($conv_empty['hash'] === '' && $conv_empty['type'] === 'none' && $conv_empty['requires_reset'] === false);

		$conv_bad = $handler->convert_password('mybb', ['password' => 'invalid_non_hex_md5', 'salt' => 'salt']);
		$results['handler_convert_malformed'] = ($conv_bad['requires_reset'] === true);

		// 7. Format Unambiguity with special character salts
		$salt_with_dollars = '$$$salt$$$with$$dollars$$$';
		$raw_md5_d = md5(md5($salt_with_dollars) . md5('test'));
		$encoded_d = mybb_password_handler::encode_legacy_password($raw_md5_d, $salt_with_dollars);
		$results['salt_with_dollar_signs_parsed_cleanly'] = ($driver->check('test', $encoded_d) === true);

		return $results;
	}
}
