<?php
/**
 * phpBB Migration Center Extension - Redirect Generator Unit Tests
 *
 * @copyright (c) 2026 phpBB SEO Team
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

declare(strict_types=1);

require_once __DIR__ . '/../../core/seo/redirect_generator.php';

use phpbbseo\migrationcenter\core\seo\redirect_generator;

class RedirectGeneratorTest
{
	public function run(): void
	{
		echo "Testing RedirectGeneratorTest...\n";

		// 1. XenForo
		$xf_apache = redirect_generator::get_htaccess_rules('xenforo');
		assert(strpos($xf_apache, 'threads/') !== false, 'XenForo htaccess should contain threads/');
		assert(strpos($xf_apache, 'posts/') !== false, 'XenForo htaccess should contain posts/');
		assert(strpos($xf_apache, 'forums/') !== false, 'XenForo htaccess should contain forums/');
		assert(strpos($xf_apache, 'members/') !== false, 'XenForo htaccess should contain members/');

		$xf_nginx = redirect_generator::get_nginx_rules('xenforo');
		assert(strpos($xf_nginx, 'rewrite ^/threads/') !== false, 'XenForo nginx should contain rewrite ^/threads/');

		// 2. vBulletin
		$vb_apache = redirect_generator::get_htaccess_rules('vbulletin');
		assert(strpos($vb_apache, 'showthread.php') !== false, 'vB htaccess should contain showthread.php');
		assert(strpos($vb_apache, 'showpost.php') !== false, 'vB htaccess should contain showpost.php');
		assert(strpos($vb_apache, 'forumdisplay.php') !== false, 'vB htaccess should contain forumdisplay.php');

		$vb_nginx = redirect_generator::get_nginx_rules('vbulletin');
		assert(strpos($vb_nginx, 'showthread.php') !== false, 'vB nginx should contain showthread.php');

		// 3. MyBB
		$mybb_apache = redirect_generator::get_htaccess_rules('mybb');
		assert(strpos($mybb_apache, 'thread-([0-9]+)\.html') !== false, 'MyBB htaccess should match thread-*.html');

		$mybb_nginx = redirect_generator::get_nginx_rules('mybb');
		assert(strpos($mybb_nginx, 'thread-([0-9]+)\.html') !== false, 'MyBB nginx should match thread-*.html');

		// 4. SMF
		$smf_apache = redirect_generator::get_htaccess_rules('smf');
		assert(strpos($smf_apache, 'topic=([0-9]+)') !== false, 'SMF htaccess should match topic=');

		$smf_nginx = redirect_generator::get_nginx_rules('smf');
		assert(strpos($smf_nginx, 'topic=([0-9]+)') !== false, 'SMF nginx should match topic=');

		echo "  [PASS] All RedirectGeneratorTest assertions passed!\n";
	}
}

$test = new RedirectGeneratorTest();
$test->run();
