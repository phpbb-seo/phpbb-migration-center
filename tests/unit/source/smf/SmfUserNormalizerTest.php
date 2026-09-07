<?php
/**
 * phpBB Migration Center Extension
 *
 * @copyright (c) 2026 phpBB SEO Team
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace phpbbseo\migrationcenter\tests\unit\source\smf;

use phpbbseo\migrationcenter\source\smf\normalizer\smf_user_normalizer;

/**
 * Unit Test for SMF User Normalizer
 */
class SmfUserNormalizerTest
{
	public function run(): array
	{
		$normalizer = new smf_user_normalizer();
		$results = [];

		$user_row = [
			'id_member' => 42,
			'member_name' => 'SarahConnor',
			'real_name' => 'Sarah Connor',
			'email_address' => 'sarah@example.com',
			'id_group' => 0,
			'id_post_group' => 4,
			'date_registered' => 1609459200,
			'last_login' => 1609500000,
			'posts' => 55,
			'passwd' => '$2y$10$abcdefghijklmnopqrstuvwxyz123456789012345678901234567',
			'password_salt' => '',
			'signature' => 'No fate but what we make.',
			'website_url' => 'https://example.com',
			'member_ip' => '127.0.0.1',
			'is_activated' => 1,
		];

		$dto = $normalizer->normalize($user_row);

		$results['id_member_normalized'] = ($dto->source_id === 42);
		$results['username_normalized'] = ($dto->username === 'SarahConnor');
		$results['email_normalized'] = ($dto->email === 'sarah@example.com');
		$results['registered_date_normalized'] = ($dto->registered_date === 1609459200);
		$results['post_count_normalized'] = ($dto->post_count === 55);
		$results['password_has_mcsmf_prefix'] = (strpos($dto->password_hash, '$mcsmf$2$') === 0);
		$results['website_normalized'] = ($dto->website === 'https://example.com');
		$results['signature_normalized'] = ($dto->signature === 'No fate but what we make.');
		$results['user_type_is_normal'] = ($dto->user_type === 0);

		return $results;
	}
}
