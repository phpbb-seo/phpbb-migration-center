<?php
/**
 * phpBB Migration Center Extension
 *
 * @copyright (c) 2026 phpBB SEO Team
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace phpbbseo\migrationcenter\source\xenforo\version;

/**
 * XenForo 2.2 Adapter
 */
class xf22_adapter extends xf21_adapter
{
	/**
	 * Feature compatibility breakdown
	 *
	 * @return array
	 */
	public function get_feature_compatibility(): array
	{
		$compat = parent::get_feature_compatibility();
		$compat['profile_banners']    = ['status' => 'not_imported', 'note' => 'XF 2.2 profile banners not supported in core phpBB'];
		$compat['content_voting']     = ['status' => 'not_imported', 'note' => 'XF 2.2 content votes not supported in core phpBB'];
		$compat['question_solutions'] = ['status' => 'reduced_fidelity', 'note' => 'Question solutions converted as normal topic posts'];
		return $compat;
	}
}
