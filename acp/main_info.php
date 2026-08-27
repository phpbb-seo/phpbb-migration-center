<?php
/**
 * phpBB Migration Center Extension
 *
 * @copyright (c) 2026 phpBB SEO Team
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace phpbbseo\migrationcenter\acp;

/**
 * ACP Module Info
 */
class main_info
{
	/**
	 * Define module metadata and modes
	 *
	 * @return array
	 */
	public function module()
	{
		return array(
			'filename' => '\phpbbseo\migrationcenter\acp\main_module',
			'title'    => 'ACP_MIGRATION_CENTER',
			'modes'    => array(
				'overview' => array(
					'title' => 'ACP_MIGRATION_OVERVIEW',
					'auth'  => 'ext_phpbbseo/migrationcenter && (acl_a_migrationcenter || acl_a_board)',
					'cat'   => array('ACP_MIGRATION_CENTER'),
				),
				'wizard'   => array(
					'title' => 'ACP_MIGRATION_WIZARD',
					'auth'  => 'ext_phpbbseo/migrationcenter && (acl_a_migrationcenter || acl_a_board)',
					'cat'   => array('ACP_MIGRATION_CENTER'),
				),
				'progress' => array(
					'title' => 'ACP_MIGRATION_PROGRESS',
					'auth'  => 'ext_phpbbseo/migrationcenter && (acl_a_migrationcenter || acl_a_board)',
					'cat'   => array('ACP_MIGRATION_CENTER'),
				),
				'errors'   => array(
					'title' => 'ACP_MIGRATION_ERRORS',
					'auth'  => 'ext_phpbbseo/migrationcenter && (acl_a_migrationcenter || acl_a_board)',
					'cat'   => array('ACP_MIGRATION_CENTER'),
				),
				'settings' => array(
					'title'   => 'ACP_MIGRATION_SETTINGS',
					'auth'    => 'ext_phpbbseo/migrationcenter && (acl_a_migrationcenter || acl_a_board)',
					'cat'     => array('ACP_MIGRATION_CENTER'),
					'display' => false,
				),
				'finalize' => array(
					'title' => 'ACP_MIGRATION_FINALIZE',
					'auth'  => 'ext_phpbbseo/migrationcenter && (acl_a_migrationcenter || acl_a_board)',
					'cat'   => array('ACP_MIGRATION_CENTER'),
				),
			),
		);
	}
}
