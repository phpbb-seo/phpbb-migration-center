<?php
/**
 * XenForo Node Permission Mapping and Scope Security Unit Test
 */

namespace phpbbseo\migrationcenter\tests\unit;

use phpbbseo\migrationcenter\source\xenforo\permission\xf_permission_matrix;

class XfNodePermissionTest
{
	public function run()
	{
		$matrix = xf_permission_matrix::get_matrix();

		// 1. Check all forum-scoped permissions in matrix
		$forum_permissions = [];
		foreach ($matrix as $key => $def)
		{
			if ($def['scope'] === 'forum')
			{
				$forum_permissions[$key] = $def;

				// SECURITY INVARIANT: No forum/node permission may ever use an 'a_' (admin) option!
				if (strpos($def['phpbb_option'], 'a_') === 0)
				{
					throw new \Exception("CRITICAL SECURITY DEFECT: Forum permission '{$key}' mapped to administrative option '{$def['phpbb_option']}'!");
				}

				// Must be a local option ('f_' or 'm_')
				if (strpos($def['phpbb_option'], 'f_') !== 0 && strpos($def['phpbb_option'], 'm_') !== 0)
				{
					throw new \Exception("Forum permission '{$key}' mapped to invalid option prefix '{$def['phpbb_option']}'");
				}
			}
		}

		if (empty($forum_permissions))
		{
			throw new \Exception("No forum scoped permissions found in matrix");
		}

		// 2. Verify representative mappings
		$expected_mappings = [
			'forum.viewNode'         => 'f_list',
			'forum.viewContent'      => 'f_read',
			'forum.postThread'       => 'f_post',
			'forum.postReply'        => 'f_reply',
			'forum.uploadAttachment' => 'f_attach',
		];

		foreach ($expected_mappings as $xf_perm => $expected_opt)
		{
			if (!isset($forum_permissions[$xf_perm]) || $forum_permissions[$xf_perm]['phpbb_option'] !== $expected_opt)
			{
				throw new \Exception("Expected mapping for {$xf_perm} -> {$expected_opt} failed");
			}
		}

		return true;
	}
}
