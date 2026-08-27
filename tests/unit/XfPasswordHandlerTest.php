<?php
/**
 * XenForo Password Handler Unit Test
 */

namespace phpbbseo\migrationcenter\tests\unit;

use phpbbseo\migrationcenter\source\xenforo\password\xf_password_handler;

class XfPasswordHandlerTest
{
	public function run()
	{
		$handler = new xf_password_handler();

		// Test 1: XF:Core12 with standard bcrypt hash ($2y$)
		$known_pass = 'Secret123!';
		$bcrypt_hash = password_hash($known_pass, PASSWORD_BCRYPT, ['cost' => 10]);
		$serialized_data = serialize(['hash' => $bcrypt_hash]);

		if (!$handler->is_supported('XF:Core12', $serialized_data))
		{
			throw new \Exception("XF:Core12 with bcrypt should be supported");
		}

		$converted = $handler->convert_password('XF:Core12', $serialized_data);
		if ($converted['type'] !== 'bcrypt' || $converted['requires_reset'] !== false || empty($converted['hash']))
		{
			throw new \Exception("XF:Core12 password conversion failed: " . json_encode($converted));
		}

		// Verify that phpBB password_verify validates this hash
		if (!password_verify($known_pass, $converted['hash']))
		{
			throw new \Exception("Bcrypt hash verification failed with correct password");
		}
		if (password_verify('WrongPassword', $converted['hash']))
		{
			throw new \Exception("Bcrypt hash incorrectly validated wrong password");
		}

		// Test 2: XF:NoPassword
		if (!$handler->is_supported('XF:NoPassword', ''))
		{
			throw new \Exception("XF:NoPassword should be supported");
		}
		$nopass = $handler->convert_password('XF:NoPassword', '');
		if ($nopass['type'] !== 'none' || $nopass['requires_reset'] !== false)
		{
			throw new \Exception("XF:NoPassword conversion failed");
		}

		// Test 3: Unsupported legacy scheme (e.g. unknown vB or old scheme)
		$unsupported = $handler->convert_password('UnknownScheme', 'garbage_data');
		if ($unsupported['requires_reset'] !== true || $unsupported['type'] !== 'unsupported')
		{
			throw new \Exception("Unsupported scheme should require password reset");
		}

		// Test 4: Unserialize safety (no class instantiation)
		$exploit_serialized = 'O:8:"stdClass":1:{s:4:"test";s:5:"value";}';
		$parsed = $handler->parse_auth_data($exploit_serialized);
		// With allowed_classes => false, an object unserializes to __PHP_Incomplete_Class or false/empty
		if (is_object($parsed) && get_class($parsed) === 'stdClass')
		{
			throw new \Exception("Unsafe deserialization detected: instantiated stdClass object!");
		}

		return true;
	}
}
