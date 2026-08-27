<?php
/**
 * phpBB Migration Center Extension [Persian]
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
	'ACP_MIGRATION_CENTER'         => 'مرکز مهاجرت',
	'ACP_MIGRATION_CENTER_EXPLAIN' => 'چارچوب سازمانی و امن برای انتقال داده‌های انجمن از زنفورو و سایر سیستم‌ها به phpBB.',
	'ACP_MIGRATION_OVERVIEW'       => 'نمای کلی مهاجرت',
	'ACP_MIGRATION_WIZARD'         => 'ویزارد راه‌اندازی',
	'ACP_MIGRATION_PROGRESS'       => 'پیشرفت زنده',
	'ACP_MIGRATION_ERRORS'         => 'گزارش خطاها و هشدارها',
	'ACP_MIGRATION_SETTINGS'       => 'تنظیمات عمومی',
	'ACP_MIGRATION_FINALIZE'       => 'نهایی‌سازی و بررسی سلامت',
));