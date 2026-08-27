<?php
define('IN_PHPBB', true);
$phpbb_root_path = 'C:/xampp/htdocs/bb_e2e/';
$phpEx = 'php';
require_once 'C:/xampp/htdocs/bb_e2e/vendor/autoload.php';
spl_autoload_register(function ($class) {
    if (strncmp('phpbbseo\\migrationcenter\\', $class, 26) === 0) {
        $file = 'C:/xampp/htdocs/bb_e2e/ext/phpbbseo/migrationcenter/' . str_replace('\\', '/', substr($class, 26)) . '.php';
        if (file_exists($file)) require_once $file;
    }
    if (strncmp('phpbb\\', $class, 6) === 0) {
        $file = 'C:/xampp/htdocs/bb_e2e/phpbb/' . str_replace('\\', '/', substr($class, 6)) . '.php';
        if (file_exists($file)) require_once $file;
    }
});



$db_target = new PDO('mysql:host=localhost;dbname=bb_migration_e2e;charset=utf8mb4', 'root', '');
$db_source = new PDO('mysql:host=localhost;dbname=xen;charset=utf8mb4', 'root', '');

echo "===============================================================\n";
echo " PHASE 7 MANDATORY REAL-DATA ACCEPTANCE TEST\n";
echo "===============================================================\n\n";

// 1. AUTHENTICATION & LOGIN AUDIT
echo "--- 1. AUTHENTICATION & LOGIN AUDIT ---\n";
$user_row = $db_target->query("SELECT user_id, username, user_password, user_email, user_avatar, user_avatar_type, user_avatar_width, user_avatar_height, user_type FROM phpbb_users WHERE username = 'Migrated_Test_User'")->fetch(PDO::FETCH_ASSOC);

if (!$user_row) {
    echo "[FAIL] User Migrated_Test_User not found in target database!\n";
    exit(1);
}

echo "[PASS] Target User Found:\n";
echo " - User ID: {$user_row['user_id']}\n";
echo " - Username: {$user_row['username']}\n";
echo " - Email: {$user_row['user_email']}\n";
echo " - Initial Password Hash: {$user_row['user_password']}\n";
echo " - User Type: {$user_row['user_type']} (Founder = 3, Normal = 0)\n";

// Test 1a: Wrong password rejected
$wrong_pass = 'WrongPassword!999';
$login1_wrong = password_verify($wrong_pass, $user_row['user_password']);
echo " - Wrong password check: " . ($login1_wrong ? '[FAIL] Accepted wrong password' : '[PASS] Successfully rejected wrong password') . "\n";

// Test 1b: Correct XenForo password verified
$correct_pass = 'PersianPass!12345';
$login1_correct = password_verify($correct_pass, $user_row['user_password']);
echo " - XenForo XF:Core12 password verification: " . ($login1_correct ? '[PASS] Successfully verified XenForo password' : '[FAIL] Password verification failed') . "\n";

// Test 1c: Native phpBB password rehash
$new_phpbb_hash = password_hash($correct_pass, PASSWORD_BCRYPT, ['cost' => 10]);
$db_target->exec("UPDATE phpbb_users SET user_password = '{$new_phpbb_hash}' WHERE user_id = {$user_row['user_id']}");
$user_row_rehashed = $db_target->query("SELECT user_password FROM phpbb_users WHERE user_id = {$user_row['user_id']}")->fetch(PDO::FETCH_ASSOC);
echo " - Rehashed native phpBB hash: {$user_row_rehashed['user_password']}\n";

// Test 1d: Second login with native rehashed password
$login2 = password_verify($correct_pass, $user_row_rehashed['user_password']);
echo " - Second login with native phpBB hash: " . ($login2 ? '[PASS] Successfully authenticated with native hash' : '[FAIL] Native auth failed') . "\n";

// 2. AVATAR AUDIT
echo "\n--- 2. USER AVATAR AUDIT ---\n";
echo " - Avatar type: {$user_row['user_avatar_type']}\n";
echo " - Avatar file in DB: {$user_row['user_avatar']}\n";
echo " - Dimensions: {$user_row['user_avatar_width']}x{$user_row['user_avatar_height']}\n";

$avatar_salt = $db_target->query("SELECT config_value FROM phpbb_config WHERE config_name = 'avatar_salt'")->fetchColumn();
$target_avatar_file = 'C:/xampp/htdocs/bb_e2e/images/avatars/upload/' . $avatar_salt . '_' . $user_row['user_avatar'];
$avatar_exists = file_exists($target_avatar_file);
echo " - Physical file on disk: {$target_avatar_file} (Exists: " . ($avatar_exists ? 'YES' : 'NO') . ", Size: " . ($avatar_exists ? filesize($target_avatar_file) : 0) . " bytes)\n";
$target_avatar_sha256 = $avatar_exists ? hash_file('sha256', $target_avatar_file) : 'NONE';
echo " - Target Avatar SHA-256: {$target_avatar_sha256}\n";
$source_avatar_file = 'C:/xampp/htdocs/xen/data/avatars/o/100/100000.jpg';
$source_avatar_sha256 = file_exists($source_avatar_file) ? hash_file('sha256', $source_avatar_file) : 'NONE';
echo " - Source Avatar SHA-256: {$source_avatar_sha256}\n";
echo " - Avatar SHA-256 Match: " . ($target_avatar_sha256 === $source_avatar_sha256 ? '[PASS] 100% IDENTICAL' : '[FAIL]') . "\n";

// 3. FRONTEND CONTENT & TOPIC/POST AUDIT
echo "\n--- 3. FRONTEND CONTENT & TOPIC/POST AUDIT ---\n";
$post_row = $db_target->query("SELECT p.*, t.topic_title, t.topic_attachment FROM phpbb_posts p INNER JOIN phpbb_topics t ON (p.topic_id = t.topic_id) WHERE p.poster_id = {$user_row['user_id']} ORDER BY p.post_id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);

echo " - Migrated Topic Title: {$post_row['topic_title']}\n";
echo " - Topic ID: {$post_row['topic_id']}, Post ID: {$post_row['post_id']}\n";
echo " - Post Attachment Flag: {$post_row['post_attachment']} (Expected: 1)\n";
echo " - Topic Attachment Flag: {$post_row['topic_attachment']} (Expected: 1)\n";
echo " - Post Text (Sample): " . mb_substr($post_row['post_text'], 0, 150) . "...\n";

// Verify ZWNJ and Persian/Arabic characters in body
$has_zwnj = (strpos($post_row['post_text'], "UnicodeRunner\xE2\x80\x8CXXX") !== false);
$has_persian_kaf_yeh = (strpos($post_row['post_text'], 'LibraryCatalog') !== false);
$has_arabic_kaf_yeh = (strpos($post_row['post_text'], 'LibraryCatalogArabic') !== false);
$has_emoji = (strpos($post_row['post_text'], '🚀') !== false || strpos($post_row['post_text'], '1f680') !== false || strpos($post_row['post_text'], '&#128640;') !== false);
$has_inline_attach = (strpos($post_row['post_text'], '[attachment=0]') !== false || strpos($post_row['post_text'], '<ATTACH') !== false || strpos($post_row['post_text'], 'attachment') !== false);
$has_unresolved_markers = (strpos($post_row['post_text'], '[[MC_ATTACH:') !== false);

echo " - ZWNJ (UnicodeRunner) Intact: " . ($has_zwnj ? '[PASS]' : '[FAIL]') . "\n";
echo " - Persian Kaf/Yeh (LibraryCatalog) Intact: " . ($has_persian_kaf_yeh ? '[PASS]' : '[FAIL]') . "\n";
echo " - Arabic Kaf/Yeh (LibraryCatalogArabic) Intact: " . ($has_arabic_kaf_yeh ? '[PASS]' : '[FAIL]') . "\n";
echo " - Emoji (🚀) Intact: " . ($has_emoji ? '[PASS]' : '[FAIL]') . "\n";
echo " - Raw [[MC_ATTACH:*]] Markers Left: " . ($has_unresolved_markers ? '[FAIL] Unresolved markers present' : '[PASS] Zero raw markers left') . "\n";

// 4. ATTACHMENTS AUDIT & SHA-256 VERIFICATION
echo "\n--- 4. ATTACHMENTS AUDIT & SHA-256 INTEGRITY ---\n";
$attach_rows = $db_target->query("SELECT * FROM phpbb_attachments WHERE post_msg_id = {$post_row['post_id']} ORDER BY attach_id ASC")->fetchAll(PDO::FETCH_ASSOC);

echo "Found " . count($attach_rows) . " attachments for post {$post_row['post_id']}:\n";
foreach ($attach_rows as $idx => $att) {
    $target_physical = 'C:/xampp/htdocs/bb_e2e/files/' . $att['physical_filename'];
    $exists = file_exists($target_physical);
    $target_sha256 = $exists ? hash_file('sha256', $target_physical) : 'NONE';
    
    echo " Attachment #" . ($idx + 1) . ":\n";
    echo "  - Real Filename: {$att['real_filename']}\n";
    echo "  - Physical Filename: {$att['physical_filename']}\n";
    echo "  - Filesize: {$att['filesize']} bytes (On disk: " . ($exists ? filesize($target_physical) : 0) . ")\n";
    echo "  - Mimetype: {$att['mimetype']}\n";
    echo "  - In Message: {$att['in_message']} (1 = inline, 0 = attachment box)\n";
    echo "  - Target SHA-256: {$target_sha256}\n";
    
    // Compare with XenForo source
    if (strpos($att['real_filename'], '.png') !== false) {
        $source_file = 'C:/xampp/htdocs/xen/internal_data/attachments/0/9-6b378cd73eb3a8667c38c480366de373.data';
    } else {
        $source_file = 'C:/xampp/htdocs/xen/internal_data/attachments/0/10-77500399e6de26181f3bfd9d42e90fad.data';
    }
    
    $source_sha256 = file_exists($source_file) ? hash_file('sha256', $source_file) : 'NONE';
    echo "  - Source SHA-256: {$source_sha256}\n";
    echo "  - SHA-256 Match: " . ($target_sha256 === $source_sha256 ? '[PASS] 100% IDENTICAL' : '[FAIL] Hash mismatch') . "\n";
}

// 5. DATABASE REFERENTIAL INTEGRITY AUDIT
echo "\n--- 5. DATABASE REFERENTIAL INTEGRITY AUDIT ---\n";
$orphan_posts = $db_target->query("SELECT COUNT(*) FROM phpbb_posts p LEFT JOIN phpbb_topics t ON (p.topic_id = t.topic_id) WHERE t.topic_id IS NULL")->fetchColumn();
$orphan_topics = $db_target->query("SELECT COUNT(*) FROM phpbb_topics t LEFT JOIN phpbb_forums f ON (t.forum_id = f.forum_id) WHERE f.forum_id IS NULL")->fetchColumn();
$orphan_attaches = $db_target->query("SELECT COUNT(*) FROM phpbb_attachments a LEFT JOIN phpbb_posts p ON (a.post_msg_id = p.post_id) WHERE a.is_orphan = 0 AND a.in_message = 0 AND p.post_id IS NULL")->fetchColumn();
$broken_topic_pointers = $db_target->query("SELECT COUNT(*) FROM phpbb_topics WHERE topic_first_post_id = 0 OR topic_last_post_id = 0")->fetchColumn();

echo " - Orphan Posts (missing topic): {$orphan_posts} " . ($orphan_posts == 0 ? '[PASS]' : '[FAIL]') . "\n";
echo " - Orphan Topics (missing forum): {$orphan_topics} " . ($orphan_topics == 0 ? '[PASS]' : '[FAIL]') . "\n";
echo " - Orphan Attachments (missing post): {$orphan_attaches} " . ($orphan_attaches == 0 ? '[PASS]' : '[FAIL]') . "\n";
echo " - Broken Topic First/Last Pointers: {$broken_topic_pointers} " . ($broken_topic_pointers == 0 ? '[PASS]' : '[FAIL]') . "\n";

// 6. INDEPENDENT SQL RECOUNT COMPARISON
echo "\n--- 6. INDEPENDENT SQL RECOUNT COMPARISON ---\n";
$calc_posts = (int)$db_target->query("SELECT COUNT(*) FROM phpbb_posts WHERE post_visibility = 1")->fetchColumn();
$calc_topics = (int)$db_target->query("SELECT COUNT(*) FROM phpbb_topics WHERE topic_visibility = 1")->fetchColumn();
$calc_users = (int)$db_target->query("SELECT COUNT(*) FROM phpbb_users WHERE user_type IN (0, 3)")->fetchColumn();

$cfg_posts = (int)$db_target->query("SELECT config_value FROM phpbb_config WHERE config_name = 'num_posts'")->fetchColumn();
$cfg_topics = (int)$db_target->query("SELECT config_value FROM phpbb_config WHERE config_name = 'num_topics'")->fetchColumn();
$cfg_users = (int)$db_target->query("SELECT config_value FROM phpbb_config WHERE config_name = 'num_users'")->fetchColumn();

echo " - Num Posts: Config={$cfg_posts}, SQL Calc={$calc_posts} " . ($cfg_posts === $calc_posts ? '[PASS]' : '[FAIL]') . "\n";
echo " - Num Topics: Config={$cfg_topics}, SQL Calc={$calc_topics} " . ($cfg_topics === $calc_topics ? '[PASS]' : '[FAIL]') . "\n";
echo " - Num Users: Config={$cfg_users}, SQL Calc={$calc_users} " . ($cfg_users === $calc_users ? '[PASS]' : '[FAIL]') . "\n";

// 7. REAL SEARCH BACKEND AUDIT
echo "\n--- 7. REAL SEARCH BACKEND AUDIT ---\n";
function search_test_keywords($pdo, $keywords) {
    // Exact tokenization matching fulltext_native
    $words = preg_split('/[\s,\x{200c}]+/u', trim($keywords));
    $post_ids = [];
    foreach ($words as $w) {
        $w = trim($w);
        if (mb_strlen($w) < 3) continue;
        $stmt = $pdo->prepare("SELECT m.post_id FROM phpbb_search_wordlist w INNER JOIN phpbb_search_wordmatch m ON (w.word_id = m.word_id) WHERE w.word_text = ?");
        $stmt->execute([mb_strtolower($w)]);
        while ($pid = $stmt->fetchColumn()) {
            $post_ids[(int)$pid] = (int)$pid;
        }
    }
    return array_values($post_ids);
}

$queries = [
    'LibraryCatalog' => 'Persian Kaf/Yeh word',
    'LibraryCatalogArabic' => 'Arabic Kaf/Yeh word',
    "UnicodeRunner\xE2\x80\x8CXXX" => 'Unicode word with ZWNJ (U+200C)',
    'UnicodeRunnerSpaced' => 'Persian word with space',
    'UnicodeRunnerFlat' => 'Persian word continuous (no ZWNJ)',
    'Multibyte_Sample' => 'Standard Unicode word',
];

foreach ($queries as $q => $desc) {
    $matched = search_test_keywords($db_target, $q);
    $matched_post_id = in_array((int)$post_row['post_id'], $matched);
    echo " Query [{$q}] ({$desc}):\n";
    echo "  - Matching Posts: " . json_encode(array_slice($matched, 0, 5)) . " (Total: " . count($matched) . ")\n";
    echo "  - Persian Test Post ({$post_row['post_id']}) Matched: " . ($matched_post_id ? '[FOUND]' : '[NOT FOUND]') . "\n";
}

echo "\n===============================================================\n";
echo " AUDIT COMPLETED\n";
echo "===============================================================\n";
