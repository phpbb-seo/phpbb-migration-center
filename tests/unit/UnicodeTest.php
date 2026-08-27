<?php
/**
 * Unicode and RTL Character Safety Test
 */

namespace phpbbseo\migrationcenter\tests\unit;

class UnicodeTest
{
	public function run()
	{
		$test_cases = [
			'Persian Text' => 'Unicode Text - Multibyte Sample',
			'Arabic Text'  => 'Arabic Script - Multibyte Sample',
			'Persian Yeh'  => 'Y',
			'Arabic Yeh'   => 'Y_Ar',
			'Persian Kaf'  => 'K',
			'Arabic Kaf'   => 'K_Ar',
			'ZWNJ Sample' => "UnicodeRunner\xE2\x80\x8CXXX", // U+200C
			'Emoji'        => '😀 🚀 ❤️ 🌟',
			'Mixed Strings'=> 'English Multibyte_Sample Multibyte_Script 😀 12345',
		];

		foreach ($test_cases as $name => $string)
		{
			// Verify UTF-8 validity
			if (!mb_check_encoding($string, 'UTF-8'))
			{
				throw new \Exception("UTF-8 encoding validation failed for: {$name}");
			}

			// Verify JSON encode/decode round trip without losing characters or escaping
			$encoded = json_encode(['text' => $string], JSON_UNESCAPED_UNICODE);
			$decoded = json_decode($encoded, true);

			if (($decoded['text'] ?? '') !== $string)
			{
				throw new \Exception("JSON round-trip mismatch for: {$name}");
			}

			// Verify byte length vs character length
			$char_len = mb_strlen($string, 'UTF-8');
			$byte_len = strlen($string);

			if ($char_len <= 0 || $byte_len < $char_len)
			{
				throw new \Exception("Invalid character length calculation for: {$name}");
			}
		}

		return true;
	}
}
