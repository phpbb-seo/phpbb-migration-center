<?php
/**
 * XenForo Permission Translator Unit Test
 */

namespace phpbbseo\migrationcenter\tests\unit;

use phpbbseo\migrationcenter\source\xenforo\permission\xf_permission_translator;

class XfPermissionTranslatorTest
{
	public function run()
	{
		$translator = new xf_permission_translator();

		// 1. Exact permission mappings
		$exact_entries = [
			['permission_group_id' => 'general', 'permission_id' => 'search', 'permission_value' => 'allow'],
			['permission_group_id' => 'general', 'permission_id' => 'viewProfile', 'permission_value' => 'allow'],
			['permission_group_id' => 'avatar', 'permission_id' => 'allowed', 'permission_value' => 'allow'],
			['permission_group_id' => 'signature', 'permission_id' => 'basic', 'permission_value' => 'allow'],
			['permission_group_id' => 'conversation', 'permission_id' => 'start', 'permission_value' => 'allow'],
		];

		foreach ($exact_entries as $entry)
		{
			$trans = $translator->translate_entry($entry);
			if ($trans['status'] !== 'mapped' || $trans['confidence'] !== 'exact' || $trans['auth_setting'] !== 1)
			{
				throw new \Exception("Exact permission translation failed for {$entry['permission_group_id']}.{$entry['permission_id']}");
			}
		}

		// 2. Deny / Never Precedence
		$never_entry = [
			'permission_group_id' => 'general',
			'permission_id'       => 'search',
			'permission_value'    => 'never',
		];
		$never_trans = $translator->translate_entry($never_entry);
		if ($never_trans['auth_setting'] !== -1)
		{
			throw new \Exception("Explicit 'never' permission must translate to ACL_NEVER (-1), got: " . $never_trans['auth_setting']);
		}

		// 3. Reduced Fidelity Mappings
		$reduced_entry = [
			'permission_group_id' => 'general',
			'permission_id'       => 'cleanSpam',
			'permission_value'    => 'allow',
		];
		$reduced_trans = $translator->translate_entry($reduced_entry);
		if ($reduced_trans['status'] !== 'mapped' || $reduced_trans['confidence'] !== 'reduced')
		{
			throw new \Exception("Reduced fidelity translation check failed");
		}

		// 4. Unsupported / Unknown Permissions (Must NEVER grant allow!)
		$unknown_entry = [
			'permission_group_id' => 'customAddonGroup',
			'permission_id'       => 'superCustomSecretPerm',
			'permission_value'    => 'allow',
		];
		$unknown_trans = $translator->translate_entry($unknown_entry);
		if ($unknown_trans['status'] !== 'unsupported' || $unknown_trans['auth_setting'] === 1)
		{
			throw new \Exception("Security Violation: Unknown permission was converted to allow!");
		}

		// 5. Node vs Global Permission Separation (Deferred to Phase 4B)
		$node_entry = [
			'permission_group_id' => 'forum',
			'permission_id'       => 'postThread',
			'permission_value'    => 'allow',
		];
		$node_trans = $translator->translate_entry($node_entry);
		if ($node_trans['status'] !== 'deferred_node' || $node_trans['scope'] !== 'forum')
		{
			throw new \Exception("Node permission was not properly marked as deferred");
		}

		// 6. Security Regression Test: No XenForo permission may ever map to phpBB root wildcard a_
		$admin_view_entry = [
			'permission_group_id' => 'admin',
			'permission_id'       => 'view',
			'permission_value'    => 'allow',
		];
		$admin_view_trans = $translator->translate_entry($admin_view_entry);
		if ($admin_view_trans['phpbb_option'] === 'a_' || $admin_view_trans['auth_setting'] === 1)
		{
			throw new \Exception("CRITICAL SECURITY DEFECT: admin.view must not map to phpBB root wildcard a_!");
		}

		$matrix = \phpbbseo\migrationcenter\source\xenforo\permission\xf_permission_matrix::get_matrix();
		foreach ($matrix as $key => $mapping)
		{
			if ($mapping['phpbb_option'] === 'a_')
			{
				throw new \Exception("CRITICAL SECURITY DEFECT: Permission {$key} maps to broad wildcard a_ in matrix!");
			}
		}

		return true;
	}
}
