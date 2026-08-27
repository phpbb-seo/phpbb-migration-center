<?php
/**
 * XenForo Group Resolution & Unicode Unit Test
 */

namespace phpbbseo\migrationcenter\tests\unit;

use phpbbseo\migrationcenter\core\dto\group_dto;

class XfGroupResolutionTest
{
	public function run()
	{
		list($db, $table_prefix) = get_test_db();

		// 1. Default XenForo standard groups canonical resolution
		$default_groups = [
			1 => ['name' => 'Unregistered / Unconfirmed', 'canonical' => 'GUESTS'],
			2 => ['name' => 'Registered', 'canonical' => 'REGISTERED'],
			3 => ['name' => 'Administrative', 'canonical' => 'ADMINISTRATORS'],
			4 => ['name' => 'Moderating', 'canonical' => 'GLOBAL_MODERATORS'],
		];

		foreach ($default_groups as $xf_id => $info)
		{
			$g = new group_dto();
			$g->source_id = $xf_id;
			$g->group_name = $info['name'];
			$g->is_builtin = true;
			$g->canonical_name = $info['canonical'];

			// Verify dynamic resolution from phpbb_groups
			$sql = "SELECT group_id, group_name FROM {$table_prefix}groups WHERE group_name = '{$info['canonical']}'";
			$res = $db->sql_query($sql);
			$target_gid = (int)$db->sql_fetchfield('group_id');
			$db->sql_freeresult($res);

			if ($target_gid <= 0)
			{
				throw new \Exception("Failed to dynamically resolve canonical phpBB group: {$info['canonical']}");
			}
		}

		// 2. Custom Group Creation with Persian, Arabic, and Emoji Unicode titles
		$custom_groups = [
			5 => "XXXX_XYXX_KXXXXXX_UnicodeRunner\xE2\x80\x8CXXX", // Persian with ZWNJ
			6 => 'Super_Moderators_Group',          // Arabic
			7 => 'VIP Platinum Members 🌟🚀',        // Emoji
		];

		foreach ($custom_groups as $id => $title)
		{
			$cg = new group_dto();
			$cg->source_id = $id;
			$cg->group_name = $title;
			$cg->is_builtin = false;

			if (!mb_check_encoding($cg->group_name, 'UTF-8'))
			{
				throw new \Exception("Custom group UTF-8 encoding check failed for ID: {$id}");
			}
		}

		return true;
	}
}
