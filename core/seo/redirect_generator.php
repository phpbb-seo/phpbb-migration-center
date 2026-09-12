<?php
/**
 * phpBB Migration Center Extension
 *
 * @copyright (c) 2026 phpBB SEO Team
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

declare(strict_types=1);

namespace phpbbseo\migrationcenter\core\seo;

/**
 * Webserver 301 Permanent Redirect Rule Generator for Legacy Platforms
 */
class redirect_generator
{
	/**
	 * Generate Apache / LiteSpeed .htaccess Rewrite Rules
	 *
	 * @param string $source_system
	 * @return string
	 */
	public static function get_htaccess_rules(string $source_system): string
	{
		$sys = strtolower(trim($source_system));

		if (strpos($sys, 'xenforo') !== false)
		{
			return <<<HTACCESS
# ==============================================================================
# phpBB SEO / Migration Center: XenForo Legacy 301 Permanent Redirects
# Place this at the top of your phpBB root .htaccess (or root domain .htaccess)
# ==============================================================================
<IfModule mod_rewrite.c>
RewriteEngine On

# 1. XenForo Friendly URLs (Threads, Posts, Forums, Members)
RewriteRule ^threads/[^/]*\.([0-9]+)/?.*$ index.php?threads=$1 [L,QSA]
RewriteRule ^threads/([0-9]+)/?.*$ index.php?threads=$1 [L,QSA]
RewriteRule ^posts/([0-9]+)/?.*$ index.php?posts=$1 [L,QSA]
RewriteRule ^forums/[^/]*\.([0-9]+)/?.*$ index.php?forums=$1 [L,QSA]
RewriteRule ^forums/([0-9]+)/?.*$ index.php?forums=$1 [L,QSA]
RewriteRule ^members/[^/]*\.([0-9]+)/?.*$ index.php?members=$1 [L,QSA]
RewriteRule ^members/([0-9]+)/?.*$ index.php?members=$1 [L,QSA]

# 2. XenForo Non-Friendly Query String URLs (e.g. index.php?threads/...)
RewriteCond %{QUERY_STRING} ^threads/[^/]*\.([0-9]+) [NC]
RewriteRule ^index\.php$ index.php?threads=%1 [L,QSA]
RewriteCond %{QUERY_STRING} ^threads/([0-9]+) [NC]
RewriteRule ^index\.php$ index.php?threads=%1 [L,QSA]
RewriteCond %{QUERY_STRING} ^posts/([0-9]+) [NC]
RewriteRule ^index\.php$ index.php?posts=%1 [L,QSA]
RewriteCond %{QUERY_STRING} ^forums/[^/]*\.([0-9]+) [NC]
RewriteRule ^index\.php$ index.php?forums=%1 [L,QSA]
RewriteCond %{QUERY_STRING} ^members/[^/]*\.([0-9]+) [NC]
RewriteRule ^index\.php$ index.php?members=%1 [L,QSA]
</IfModule>
HTACCESS;
		}

		if (strpos($sys, 'vbulletin') !== false || strpos($sys, 'vb') !== false)
		{
			return <<<HTACCESS
# ==============================================================================
# phpBB SEO / Migration Center: vBulletin Legacy 301 Permanent Redirects
# Place this at the top of your phpBB root .htaccess (or root domain .htaccess)
# ==============================================================================
<IfModule mod_rewrite.c>
RewriteEngine On

# 1. vBulletin Standard URLs (showthread.php, showpost.php, forumdisplay.php, member.php)
RewriteCond %{QUERY_STRING} (?:^|&)t=([0-9]+) [NC]
RewriteRule ^showthread\.php$ index.php?t=%1 [L,QSA]
RewriteCond %{QUERY_STRING} (?:^|&)p=([0-9]+) [NC]
RewriteRule ^showthread\.php$ index.php?p=%1 [L,QSA]
RewriteCond %{QUERY_STRING} (?:^|&)p=([0-9]+) [NC]
RewriteRule ^showpost\.php$ index.php?p=%1 [L,QSA]
RewriteCond %{QUERY_STRING} (?:^|&)f=([0-9]+) [NC]
RewriteRule ^forumdisplay\.php$ index.php?f=%1 [L,QSA]
RewriteCond %{QUERY_STRING} (?:^|&)u=([0-9]+) [NC]
RewriteRule ^member\.php$ index.php?u=%1 [L,QSA]

# 2. vBulletin 4.x / 5.x / vBSEO Friendly URLs
RewriteRule ^threads/([0-9]+)-.*$ index.php?vb_thread_id=$1 [L,QSA]
RewriteRule ^threads/([0-9]+)$ index.php?vb_thread_id=$1 [L,QSA]
RewriteRule ^forum/([0-9]+)-.*$ index.php?vb_forum_id=$1 [L,QSA]
RewriteRule ^members/([0-9]+)-.*$ index.php?vb_user_id=$1 [L,QSA]
</IfModule>
HTACCESS;
		}

		if (strpos($sys, 'mybb') !== false)
		{
			return <<<HTACCESS
# ==============================================================================
# phpBB SEO / Migration Center: MyBB Legacy 301 Permanent Redirects
# Place this at the top of your phpBB root .htaccess (or root domain .htaccess)
# ==============================================================================
<IfModule mod_rewrite.c>
RewriteEngine On

# 1. MyBB SEF Friendly URLs (thread-123.html, forum-12.html, user-5.html, post-99.html)
RewriteRule ^thread-([0-9]+)\.html$ index.php?mybb_tid=$1 [L,QSA]
RewriteRule ^forum-([0-9]+)\.html$ index.php?mybb_fid=$1 [L,QSA]
RewriteRule ^user-([0-9]+)\.html$ index.php?mybb_uid=$1 [L,QSA]
RewriteRule ^post-([0-9]+)\.html$ index.php?mybb_pid=$1 [L,QSA]

# 2. MyBB Standard Query Strings
RewriteCond %{QUERY_STRING} (?:^|&)tid=([0-9]+) [NC]
RewriteRule ^showthread\.php$ index.php?mybb_tid=%1 [L,QSA]
RewriteCond %{QUERY_STRING} (?:^|&)pid=([0-9]+) [NC]
RewriteRule ^showthread\.php$ index.php?mybb_pid=%1 [L,QSA]
RewriteCond %{QUERY_STRING} (?:^|&)fid=([0-9]+) [NC]
RewriteRule ^forumdisplay\.php$ index.php?mybb_fid=%1 [L,QSA]
RewriteCond %{QUERY_STRING} (?:^|&)uid=([0-9]+) [NC]
RewriteRule ^member\.php$ index.php?mybb_uid=%1 [L,QSA]
</IfModule>
HTACCESS;
		}

		if (strpos($sys, 'smf') !== false)
		{
			return <<<HTACCESS
# ==============================================================================
# phpBB SEO / Migration Center: SMF Legacy 301 Permanent Redirects
# Place this at the top of your phpBB root .htaccess (or root domain .htaccess)
# ==============================================================================
<IfModule mod_rewrite.c>
RewriteEngine On

# 1. SMF Topics (index.php?topic=123.0)
RewriteCond %{QUERY_STRING} (?:^|;)topic=([0-9]+) [NC]
RewriteRule ^index\.php$ index.php?smf_topic=%1 [L,QSA]

# 2. SMF Boards (index.php?board=12.0)
RewriteCond %{QUERY_STRING} (?:^|;)board=([0-9]+) [NC]
RewriteRule ^index\.php$ index.php?smf_board=%1 [L,QSA]

# 3. SMF User Profiles (index.php?action=profile;u=5)
RewriteCond %{QUERY_STRING} (?:^|;)action=profile;(?:u|user)=([0-9]+) [NC]
RewriteRule ^index\.php$ index.php?smf_user=%1 [L,QSA]
</IfModule>
HTACCESS;
		}

		// Generic fallback
		return <<<HTACCESS
# ==============================================================================
# phpBB SEO / Migration Center: Generic Legacy 301 Permanent Redirects
# ==============================================================================
<IfModule mod_rewrite.c>
RewriteEngine On
RewriteRule ^threads/([0-9]+)/?.*$ index.php?threads=$1 [L,QSA]
RewriteRule ^posts/([0-9]+)/?.*$ index.php?posts=$1 [L,QSA]
RewriteRule ^forums/([0-9]+)/?.*$ index.php?forums=$1 [L,QSA]
</IfModule>
HTACCESS;
	}

	/**
	 * Generate Nginx Server Block Rewrite Rules
	 *
	 * @param string $source_system
	 * @return string
	 */
	public static function get_nginx_rules(string $source_system): string
	{
		$sys = strtolower(trim($source_system));

		if (strpos($sys, 'xenforo') !== false)
		{
			return <<<NGINX
# ==============================================================================
# phpBB SEO / Migration Center: XenForo Legacy 301 Permanent Redirects (Nginx)
# Add these rewrite rules inside your phpBB server { ... } block
# ==============================================================================
location ~ ^/threads/[^/]*\.([0-9]+)/? {
    rewrite ^/threads/[^/]*\.([0-9]+)/?.*$ /index.php?threads=$1 last;
}
location ~ ^/threads/([0-9]+)/? {
    rewrite ^/threads/([0-9]+)/?.*$ /index.php?threads=$1 last;
}
location ~ ^/posts/([0-9]+)/? {
    rewrite ^/posts/([0-9]+)/?.*$ /index.php?posts=$1 last;
}
location ~ ^/forums/[^/]*\.([0-9]+)/? {
    rewrite ^/forums/[^/]*\.([0-9]+)/?.*$ /index.php?forums=$1 last;
}
location ~ ^/members/[^/]*\.([0-9]+)/? {
    rewrite ^/members/[^/]*\.([0-9]+)/?.*$ /index.php?members=$1 last;
}
NGINX;
		}

		if (strpos($sys, 'vbulletin') !== false || strpos($sys, 'vb') !== false)
		{
			return <<<NGINX
# ==============================================================================
# phpBB SEO / Migration Center: vBulletin Legacy 301 Permanent Redirects (Nginx)
# Add these rewrite rules inside your phpBB server { ... } block
# ==============================================================================
location = /showthread.php {
    if (\$args ~ "(?:^|&)t=([0-9]+)") {
        set \$vb_t \$1;
        rewrite ^/showthread\.php$ /index.php?t=\$vb_t last;
    }
    if (\$args ~ "(?:^|&)p=([0-9]+)") {
        set \$vb_p \$1;
        rewrite ^/showthread\.php$ /index.php?p=\$vb_p last;
    }
}
location = /showpost.php {
    if (\$args ~ "(?:^|&)p=([0-9]+)") {
        set \$vb_p \$1;
        rewrite ^/showpost\.php$ /index.php?p=\$vb_p last;
    }
}
location = /forumdisplay.php {
    if (\$args ~ "(?:^|&)f=([0-9]+)") {
        set \$vb_f \$1;
        rewrite ^/forumdisplay\.php$ /index.php?f=\$vb_f last;
    }
}
location = /member.php {
    if (\$args ~ "(?:^|&)u=([0-9]+)") {
        set \$vb_u \$1;
        rewrite ^/member\.php$ /index.php?u=\$vb_u last;
    }
}
location ~ ^/threads/([0-9]+) {
    rewrite ^/threads/([0-9]+).*$ /index.php?vb_thread_id=\$1 last;
}
NGINX;
		}

		if (strpos($sys, 'mybb') !== false)
		{
			return <<<NGINX
# ==============================================================================
# phpBB SEO / Migration Center: MyBB Legacy 301 Permanent Redirects (Nginx)
# Add these rewrite rules inside your phpBB server { ... } block
# ==============================================================================
location ~ ^/thread-([0-9]+)\.html$ {
    rewrite ^/thread-([0-9]+)\.html$ /index.php?mybb_tid=\$1 last;
}
location ~ ^/forum-([0-9]+)\.html$ {
    rewrite ^/forum-([0-9]+)\.html$ /index.php?mybb_fid=\$1 last;
}
location ~ ^/user-([0-9]+)\.html$ {
    rewrite ^/user-([0-9]+)\.html$ /index.php?mybb_uid=\$1 last;
}
location ~ ^/post-([0-9]+)\.html$ {
    rewrite ^/post-([0-9]+)\.html$ /index.php?mybb_pid=\$1 last;
}
NGINX;
		}

		if (strpos($sys, 'smf') !== false)
		{
			return <<<NGINX
# ==============================================================================
# phpBB SEO / Migration Center: SMF Legacy 301 Permanent Redirects (Nginx)
# Add these rewrite rules inside your phpBB server { ... } block
# ==============================================================================
if (\$args ~ "(?:^|;)topic=([0-9]+)") {
    set \$smf_t \$1;
    rewrite ^/index\.php$ /index.php?smf_topic=\$smf_t last;
}
if (\$args ~ "(?:^|;)board=([0-9]+)") {
    set \$smf_b \$1;
    rewrite ^/index\.php$ /index.php?smf_board=\$smf_b last;
}
if (\$args ~ "(?:^|;)action=profile;(?:u|user)=([0-9]+)") {
    set \$smf_u \$1;
    rewrite ^/index\.php$ /index.php?smf_user=\$smf_u last;
}
NGINX;
		}

		return <<<NGINX
# ==============================================================================
# phpBB SEO / Migration Center: Generic Legacy 301 Permanent Redirects (Nginx)
# ==============================================================================
location ~ ^/threads/([0-9]+)/? {
    rewrite ^/threads/([0-9]+)/?.*$ /index.php?threads=\$1 last;
}
NGINX;
	}
}
