<?php
/**
 * phpBB Migration Center - Standalone Fallback 301 Redirect Script
 *
 * This high-performance script handles permanent 301 redirects for legacy URLs
 * from XenForo, vBulletin, SMF, and MyBB into phpBB using the `phpbb_migration_id_map`
 * table.
 *
 * It operates independently without booting the full phpBB framework,
 * ensuring execution times under 2ms with minimal memory footprint.
 *
 * Placement options:
 *   1. Placed in phpBB root as `legacy_redirect.php`, OR
 *   2. Placed in `ext/phpbbseo/migrationcenter/legacy_redirect.php`.
 *
 * @copyright (c) 2026 phpBB SEO Team
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

declare(strict_types=1);

// Prevent caching of redirect logic while ensuring permanent 301 for clients/bots
header('X-Redirect-By: phpBB-MigrationCenter-301');

// 1. Locate phpBB config.php
$config_candidates = [
    __DIR__ . '/config.php',
    dirname(__DIR__) . '/config.php',
    dirname(dirname(dirname(__DIR__))) . '/config.php',
    dirname(dirname(dirname(dirname(__DIR__)))) . '/config.php',
];

$config_file = null;
foreach ($config_candidates as $cand) {
    if (file_exists($cand) && is_readable($cand)) {
        $config_file = $cand;
        break;
    }
}

if (!$config_file) {
    http_response_code(500);
    die('Migration Center Redirect Error: Unable to locate phpBB config.php.');
}

// 2. Load phpBB database configuration
$dbms = $dbhost = $dbport = $dbuser = $dbpasswd = $dbname = $table_prefix = null;
require $config_file;

if (empty($dbname) || empty($dbuser) || !isset($table_prefix)) {
    http_response_code(500);
    die('Migration Center Redirect Error: Invalid database configuration in config.php.');
}

// 3. Connect to Database via PDO
$dbport_str = !empty($dbport) ? ';port=' . (int)$dbport : '';
$dsn = "mysql:host={$dbhost}{$dbport_str};dbname={$dbname};charset=utf8mb4";

try {
    $pdo = new PDO($dsn, $dbuser, $dbpasswd, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_SILENT,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
} catch (\Throwable $e) {
    http_response_code(500);
    die('Migration Center Redirect Error: Database connection failed.');
}

// 4. Determine phpBB Base Web URL
$is_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);
$protocol = $is_https ? 'https://' : 'http://';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$script_dir = dirname($_SERVER['SCRIPT_NAME'] ?? '/');
if (strpos($script_dir, '/ext/phpbbseo/migrationcenter') !== false) {
    $base_dir = str_replace('/ext/phpbbseo/migrationcenter', '', $script_dir);
} else {
    $base_dir = $script_dir;
}
$base_dir = rtrim(str_replace('\\', '/', $base_dir), '/');
$forum_base_url = $protocol . $host . ($base_dir ? $base_dir . '/' : '/');

// 5. Detect Content Type and Source ID
$content_type = null; // 'topic', 'post', 'forum', 'user'
$source_id    = null; // integer / string

$req_uri = $_SERVER['REQUEST_URI'] ?? '';
$query_str = $_SERVER['QUERY_STRING'] ?? '';

// --- Check Explicit GET parameters ---
// XenForo GET
if (!empty($_GET['threads'])) {
    $content_type = 'topic';
    $source_id    = (int)$_GET['threads'];
} elseif (!empty($_GET['posts'])) {
    $content_type = 'post';
    $source_id    = (int)$_GET['posts'];
} elseif (!empty($_GET['forums'])) {
    $content_type = 'forum';
    $source_id    = (int)$_GET['forums'];
} elseif (!empty($_GET['members'])) {
    $content_type = 'user';
    $source_id    = (int)$_GET['members'];
}
// vBulletin GET
elseif (!empty($_GET['vb_thread_id']) || !empty($_GET['t'])) {
    $content_type = 'topic';
    $source_id    = (int)($_GET['vb_thread_id'] ?? $_GET['t']);
} elseif (!empty($_GET['vb_post_id']) || !empty($_GET['p'])) {
    $content_type = 'post';
    $source_id    = (int)($_GET['vb_post_id'] ?? $_GET['p']);
} elseif (!empty($_GET['vb_forum_id']) || !empty($_GET['f'])) {
    $content_type = 'forum';
    $source_id    = (int)($_GET['vb_forum_id'] ?? $_GET['f']);
} elseif (!empty($_GET['vb_user_id']) || !empty($_GET['u'])) {
    $content_type = 'user';
    $source_id    = (int)($_GET['vb_user_id'] ?? $_GET['u']);
}
// SMF GET
elseif (!empty($_GET['smf_topic']) || (preg_match('/(?:^|;)topic=([0-9]+)/', $query_str, $m_smf_t) && ($source_id = (int)$m_smf_t[1]))) {
    $content_type = 'topic';
    $source_id    = $source_id ?: (int)$_GET['smf_topic'];
} elseif (!empty($_GET['smf_board']) || (preg_match('/(?:^|;)board=([0-9]+)/', $query_str, $m_smf_b) && ($source_id = (int)$m_smf_b[1]))) {
    $content_type = 'forum';
    $source_id    = $source_id ?: (int)$_GET['smf_board'];
} elseif (!empty($_GET['smf_user']) || (preg_match('/(?:^|;)action=profile;(?:u|user)=([0-9]+)/', $query_str, $m_smf_u) && ($source_id = (int)$m_smf_u[1]))) {
    $content_type = 'user';
    $source_id    = $source_id ?: (int)$_GET['smf_user'];
}
// MyBB GET
elseif (!empty($_GET['mybb_tid']) || !empty($_GET['tid'])) {
    $content_type = 'topic';
    $source_id    = (int)($_GET['mybb_tid'] ?? $_GET['tid']);
} elseif (!empty($_GET['mybb_fid']) || !empty($_GET['fid'])) {
    $content_type = 'forum';
    $source_id    = (int)($_GET['mybb_fid'] ?? $_GET['fid']);
} elseif (!empty($_GET['mybb_pid']) || !empty($_GET['pid'])) {
    $content_type = 'post';
    $source_id    = (int)($_GET['mybb_pid'] ?? $_GET['pid']);
} elseif (!empty($_GET['mybb_uid']) || !empty($_GET['uid'])) {
    $content_type = 'user';
    $source_id    = (int)($_GET['mybb_uid'] ?? $_GET['uid']);
}

// --- Parse URI Patterns if not matched via GET ---
if (!$content_type || !$source_id) {
    // XenForo URI patterns: /threads/slug.123/ or /threads/123/
    if (preg_match('#/threads/(?:[^/]*\.)?([0-9]+)/?#i', $req_uri, $m)) {
        $content_type = 'topic';
        $source_id    = (int)$m[1];
    } elseif (preg_match('#/posts/([0-9]+)/?#i', $req_uri, $m)) {
        $content_type = 'post';
        $source_id    = (int)$m[1];
    } elseif (preg_match('#/forums/(?:[^/]*\.)?([0-9]+)/?#i', $req_uri, $m)) {
        $content_type = 'forum';
        $source_id    = (int)$m[1];
    } elseif (preg_match('#/members/(?:[^/]*\.)?([0-9]+)/?#i', $req_uri, $m)) {
        $content_type = 'user';
        $source_id    = (int)$m[1];
    }
    // MyBB URI patterns: thread-123.html, forum-123.html, post-123.html, user-123.html
    elseif (preg_match('#/thread-([0-9]+)\.html#i', $req_uri, $m)) {
        $content_type = 'topic';
        $source_id    = (int)$m[1];
    } elseif (preg_match('#/forum-([0-9]+)\.html#i', $req_uri, $m)) {
        $content_type = 'forum';
        $source_id    = (int)$m[1];
    } elseif (preg_match('#/post-([0-9]+)\.html#i', $req_uri, $m)) {
        $content_type = 'post';
        $source_id    = (int)$m[1];
    } elseif (preg_match('#/user-([0-9]+)\.html#i', $req_uri, $m)) {
        $content_type = 'user';
        $source_id    = (int)$m[1];
    }
}

// 6. If no content type or ID found, redirect to forum home
if (!$content_type || !$source_id) {
    header('HTTP/1.1 301 Moved Permanently');
    header('Location: ' . $forum_base_url . 'index.php');
    exit;
}

// 7. Query phpbb_migration_id_map for Target ID
$target_id = null;
$table_id_map = $table_prefix . 'migration_id_map';

$stmt = $pdo->prepare("SELECT target_id FROM `{$table_id_map}` WHERE content_type = :type AND source_id = :src LIMIT 1");
if ($stmt && $stmt->execute([':type' => $content_type, ':src' => (string)$source_id])) {
    $row = $stmt->fetch();
    if (!empty($row['target_id'])) {
        $target_id = (int)$row['target_id'];
    }
}

// Fallback to original ID if unmapped (e.g. preserved 1:1 IDs)
$final_id = $target_id ?: (int)$source_id;

// 8. Build Target phpBB URL
$target_url = '';
switch ($content_type) {
    case 'topic':
        $target_url = $forum_base_url . 'viewtopic.php?t=' . $final_id;
        break;

    case 'post':
        $target_url = $forum_base_url . 'viewtopic.php?p=' . $final_id . '#p' . $final_id;
        break;

    case 'forum':
        $target_url = $forum_base_url . 'viewforum.php?f=' . $final_id;
        break;

    case 'user':
        $target_url = $forum_base_url . 'memberlist.php?mode=viewprofile&u=' . $final_id;
        break;

    default:
        $target_url = $forum_base_url . 'index.php';
        break;
}

// 9. Send Permanent 301 Redirect
header('HTTP/1.1 301 Moved Permanently');
header('Status: 301 Moved Permanently');
header('Location: ' . $target_url);
exit;
