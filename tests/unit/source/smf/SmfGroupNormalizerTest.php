<?php
/**
 * phpBB Migration Center Extension
 *
 * @copyright (c) 2026 phpBB SEO Team
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace phpbbseo\migrationcenter\tests\unit\source\smf;

use phpbbseo\migrationcenter\source\smf\normalizer\smf_group_normalizer;

/**
 * Unit Test for SMF Group Normalizer
 */
class SmfGroupNormalizerTest
{
	public function run(): array
	{
		$normalizer = new smf_group_normalizer();
		$results = [];

		// Admin group (id_group 1)
		$admin_row = [
			'id_group' => 1,
			'group_name' => 'Administrator',
			'description' => 'Board administrators',
			'online_color' => '#FF0000',
			'min_posts' => -1,
		];
		$admin_dto = $normalizer->normalize($admin_row);
		$results['admin_source_id'] = ($admin_dto->source_id === 1);
		$results['admin_name'] = ($admin_dto->group_name === 'Administrator');
		$results['admin_color'] = ($admin_dto->group_colour === 'FF0000');
		$results['admin_is_system'] = ($admin_dto->is_system_group === true);

		// Global Moderator (id_group 2)
		$gmod_row = [
			'id_group' => 2,
			'group_name' => 'Global Moderator',
			'description' => 'Global forum moderators',
			'online_color' => '#0000FF',
			'min_posts' => -1,
		];
		$gmod_dto = $normalizer->normalize($gmod_row);
		$results['gmod_source_id'] = ($gmod_dto->source_id === 2);
		$results['gmod_color'] = ($gmod_dto->group_colour === '0000FF');
		$results['gmod_is_system'] = ($gmod_dto->is_system_group === true);

		// Custom Post-based Group
		$post_group_row = [
			'id_group' => 5,
			'group_name' => 'Junior Member',
			'description' => 'Members with over 50 posts',
			'online_color' => '',
			'min_posts' => 50,
		];
		$post_dto = $normalizer->normalize($post_group_row);
		$results['post_group_source_id'] = ($post_dto->source_id === 5);
		$results['post_group_is_not_system'] = ($post_dto->is_system_group === false);

		return $results;
	}
}
