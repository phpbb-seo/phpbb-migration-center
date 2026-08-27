<?php
/**
 * phpBB Migration Center Extension
 *
 * @copyright (c) 2026 phpBB SEO Team
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace phpbbseo\migrationcenter\source\xenforo\version;

/**
 * XenForo 2.1 Adapter
 */
class xf21_adapter extends xf_base_adapter
{
	/**
	 * Feature compatibility breakdown
	 *
	 * @return array
	 */
	public function get_feature_compatibility(): array
	{
		$compat = parent::get_feature_compatibility();
		$compat['reactions'] = ['status' => 'not_imported', 'note' => 'XF 2.1 reactions require phpBB Thanks/Reactions extension'];
		$compat['bookmarks'] = ['status' => 'not_imported', 'note' => 'XF 2.1 bookmarks are not supported natively in phpBB core'];
		return $compat;
	}
}
