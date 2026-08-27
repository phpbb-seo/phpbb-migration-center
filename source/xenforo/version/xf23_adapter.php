<?php
/**
 * phpBB Migration Center Extension
 *
 * @copyright (c) 2026 phpBB SEO Team
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace phpbbseo\migrationcenter\source\xenforo\version;

/**
 * XenForo 2.3 Adapter
 */
class xf23_adapter extends xf22_adapter
{
	/**
	 * Feature compatibility breakdown
	 *
	 * @return array
	 */
	public function get_feature_compatibility(): array
	{
		$compat = parent::get_feature_compatibility();
		$compat['featured_content'] = ['status' => 'not_imported', 'note' => 'XF 2.3 featured content not supported in core phpBB'];
		return $compat;
	}
}
