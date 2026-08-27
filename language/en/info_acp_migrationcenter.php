<?php
/**
 * phpBB Migration Center Extension [English]
 *
 * @copyright (c) 2026 phpBB SEO Team
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

if (!defined('IN_PHPBB'))
{
	exit;
}

if (empty($lang) || !is_array($lang))
{
	$lang = array();
}

$lang = array_merge($lang, array(
	'ACP_MIGRATION_CENTER'         => 'Migration Center',
	'ACP_MIGRATION_CENTER_EXPLAIN' => 'Enterprise-grade forum migration framework for migrating XenForo, vBulletin, and other platforms into phpBB.',
	'ACP_MIGRATION_OVERVIEW'       => 'Migration Overview',
	'ACP_MIGRATION_WIZARD'         => 'Migration Wizard',
	'ACP_MIGRATION_PROGRESS'       => 'Live Progress',
	'ACP_MIGRATION_ERRORS'         => 'Error Log & Reports',
	'ACP_MIGRATION_SETTINGS'       => 'General Settings',
	'ACP_MIGRATION_FINALIZE'       => 'Finalization & Health Check',
));
