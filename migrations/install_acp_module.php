<?php
/**
 * phpBB Migration Center Extension
 *
 * @copyright (c) 2026 phpBB SEO Team
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace phpbbseo\migrationcenter\migrations;

/**
 * Migration to install ACP modules
 */
class install_acp_module extends \phpbb\db\migration\migration
{
	/**
	 * Define migration dependencies
	 *
	 * @return array
	 */
	public static function depends_on()
	{
		return array('\phpbbseo\migrationcenter\migrations\install_schema');
	}

	/**
	 * Update data / modules
	 *
	 * @return array
	 */
	public function update_data()
	{
		return array(
			// Add Category under Extensions tab
			array('module.add', array(
				'acp',
				'ACP_CAT_DOT_MODS',
				'ACP_MIGRATION_CENTER'
			)),

			// Add Main Module
			array('module.add', array(
				'acp',
				'ACP_MIGRATION_CENTER',
				array(
					'module_basename' => '\phpbbseo\migrationcenter\acp\main_module',
					'modes'           => array('overview', 'wizard', 'progress', 'errors', 'settings', 'finalize'),
				),
			)),
		);
	}

	/**
	 * Revert data / modules cleanly
	 *
	 * @return array
	 */
	public function revert_data()
	{
		return array(
			// 1. Remove child module first
			array('module.remove', array(
				'acp',
				'ACP_MIGRATION_CENTER',
				array(
					'module_basename' => '\phpbbseo\migrationcenter\acp\main_module',
				),
			)),

			// 2. Remove category after children are removed
			array('module.remove', array(
				'acp',
				'ACP_CAT_DOT_MODS',
				'ACP_MIGRATION_CENTER'
			)),
		);
	}
}
