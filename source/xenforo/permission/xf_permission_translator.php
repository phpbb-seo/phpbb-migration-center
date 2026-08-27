<?php
/**
 * phpBB Migration Center Extension
 *
 * @copyright (c) 2026 phpBB SEO Team
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace phpbbseo\migrationcenter\source\xenforo\permission;

/**
 * XenForo Permission Translator
 */
class xf_permission_translator
{
	/** @var array */
	protected $matrix;

	/**
	 * Constructor
	 */
	public function __construct()
	{
		$this->matrix = xf_permission_matrix::get_matrix();
	}

	/**
	 * Translate a single XenForo permission entry
	 *
	 * @param array $entry
	 * @return array
	 */
	public function translate_entry(array $entry): array
	{
		$perm_group = (string)($entry['permission_group_id'] ?? '');
		$perm_id    = (string)($entry['permission_id'] ?? '');
		$perm_key   = $perm_group . '.' . $perm_id;
		$val_type   = (string)($entry['permission_value'] ?? 'unset');
		$val_int    = (int)($entry['permission_value_int'] ?? 0);

		if (!isset($this->matrix[$perm_key]))
		{
			// Unknown permission - conservative default: NEVER grant access!
			return [
				'status'       => 'unsupported',
				'perm_key'     => $perm_key,
				'phpbb_option' => null,
				'scope'        => 'unknown',
				'confidence'   => 'unsupported',
				'auth_setting' => 0, // ACL_NO
				'notes'        => "Unmapped XenForo capability: {$perm_key}",
			];
		}

		$def = $this->matrix[$perm_key];

		if ($def['confidence'] === 'unsupported' || empty($def['phpbb_option']))
		{
			return [
				'status'       => 'unsupported',
				'perm_key'     => $perm_key,
				'phpbb_option' => null,
				'scope'        => $def['scope'],
				'confidence'   => 'unsupported',
				'auth_setting' => 0,
				'notes'        => $def['notes'],
			];
		}

		// Node scope permissions are deferred to Phase 4B
		if ($def['scope'] === 'forum')
		{
			return [
				'status'       => 'deferred_node',
				'perm_key'     => $perm_key,
				'phpbb_option' => $def['phpbb_option'],
				'scope'        => 'forum',
				'confidence'   => $def['confidence'],
				'auth_setting' => 0,
				'notes'        => $def['notes'] . ' (Deferred to Phase 4B)',
			];
		}

		// Translate value semantics conservatively
		$auth_setting = 0; // Default NO
		if ($val_type === 'allow')
		{
			$auth_setting = 1; // ACL_YES
		}
		else if ($val_type === 'deny' || $val_type === 'never')
		{
			$auth_setting = -1; // ACL_NEVER (strictly restrictive)
		}
		else if ($val_type === 'use_int')
		{
			$auth_setting = ($val_int > 0 || $val_int === -1) ? 1 : 0;
		}

		return [
			'status'       => 'mapped',
			'perm_key'     => $perm_key,
			'phpbb_option' => $def['phpbb_option'],
			'scope'        => $def['scope'],
			'confidence'   => $def['confidence'],
			'auth_setting' => $auth_setting,
			'notes'        => $def['notes'],
		];
	}

	/**
	 * Compute summary statistics for a set of XenForo permission entries
	 *
	 * @param array $entries
	 * @return array
	 */
	public function compute_stats(array $entries): array
	{
		$stats = [
			'total'            => count($entries),
			'exact'            => 0,
			'reduced_fidelity' => 0,
			'unsupported'      => 0,
			'deferred_node'    => 0,
		];

		foreach ($entries as $entry)
		{
			$trans = $this->translate_entry($entry);
			if ($trans['status'] === 'deferred_node')
			{
				$stats['deferred_node']++;
			}
			else if ($trans['confidence'] === 'exact')
			{
				$stats['exact']++;
			}
			else if ($trans['confidence'] === 'reduced')
			{
				$stats['reduced_fidelity']++;
			}
			else
			{
				$stats['unsupported']++;
			}
		}

		return $stats;
	}
}
