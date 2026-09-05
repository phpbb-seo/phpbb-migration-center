<?php
/**
 * phpBB Migration Center Extension
 *
 * @copyright (c) 2026 phpBB SEO Team
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace phpbbseo\migrationcenter\tests\unit\source\mybb;

use phpbbseo\migrationcenter\source\mybb\normalizer\mybb_group_normalizer;

/**
 * Unit Test for MyBB Group Normalizer
 */
class MybbGroupNormalizerTest
{
	public function run(): array
	{
		$normalizer = new mybb_group_normalizer();
		$results = [];

		// Admin group (gid 4)
		$admin_row = [
			'gid' => 4,
			'title' => 'Administrators',
			'description' => 'Board administrators',
			'namestyle' => '<span style="color: #ff0000;"><strong>{username}</strong></span>',
		];
		$admin_dto = $normalizer->normalize($admin_row);
		$results['admin_source_id'] = ($admin_dto->source_id === 4);
		$results['admin_name'] = ($admin_dto->group_name === 'Administrators');
		$results['admin_color'] = ($admin_dto->group_colour === 'FF0000');
		$results['admin_is_system'] = ($admin_dto->is_system_group === true);

		// Super Mod group (gid 3)
		$smod_row = [
			'gid' => 3,
			'title' => 'Super Moderators',
			'description' => 'Super Moderators',
			'namestyle' => '<span style="color: #00aa00;">{username}</span>',
		];
		$smod_dto = $normalizer->normalize($smod_row);
		$results['smod_is_system'] = ($smod_dto->is_system_group === true);
		$results['smod_color'] = ($smod_dto->group_colour === '00AA00');

		// Registered Users (gid 2)
		$reg_row = [
			'gid' => 2,
			'title' => 'Registered Users',
			'description' => 'Standard forum members',
			'namestyle' => '{username}',
		];
		$reg_dto = $normalizer->normalize($reg_row);
		$results['reg_source_id'] = ($reg_dto->source_id === 2);
		$results['reg_color'] = ($reg_dto->group_colour === '');

		return $results;
	}
}
