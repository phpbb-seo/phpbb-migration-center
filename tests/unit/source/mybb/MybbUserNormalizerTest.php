<?php
/**
 * phpBB Migration Center Extension
 *
 * @copyright (c) 2026 phpBB SEO Team
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace phpbbseo\migrationcenter\tests\unit\source\mybb;

use phpbbseo\migrationcenter\source\mybb\normalizer\mybb_user_normalizer;

/**
 * Unit Test for MyBB User Normalizer
 */
class MybbUserNormalizerTest
{
	public function run(): array
	{
		$normalizer = new mybb_user_normalizer();
		$results = [];

		$user_row = [
			'uid' => 42,
			'username' => 'SarahConnor',
			'email' => 'sarah@example.com',
			'usergroup' => 2,
			'regdate' => 1609459200,
			'lastactive' => 1609500000,
			'postnum' => 55,
			'threadnum' => 10,
			'password' => 'd0763edaa9d9bd2a9516280e9044d885',
			'salt' => 'abc123salt',
			'signature' => 'No fate but what we make.',
			'website' => 'https://example.com',
			'lastip' => '127.0.0.1',
		];

		$dto = $normalizer->normalize($user_row);

		$results['uid_normalized'] = ($dto->source_id === 42);
		$results['username_normalized'] = ($dto->username === 'SarahConnor');
		$results['email_normalized'] = ($dto->email === 'sarah@example.com');
		$results['regdate_normalized'] = ($dto->registered_date === 1609459200);
		$results['post_count_normalized'] = ($dto->post_count === 55);
		$results['password_has_mcmybb_prefix'] = (strpos($dto->password_hash, '$mcmybb$1$') === 0);
		$results['website_normalized'] = ($dto->website === 'https://example.com');
		$results['signature_normalized'] = ($dto->signature === 'No fate but what we make.');

		return $results;
	}
}
