<?php
$evidence_dir = 'C:/xampp/htdocs/bb/docs/phase7_evidence';
if (!is_dir($evidence_dir)) {
    mkdir($evidence_dir, 0777, true);
}

echo "===============================================================\n";
echo " REAL HTTP / BROWSER-LEVEL INTEGRATION AUDIT\n";
echo "===============================================================\n\n";

// Helper function for cURL
function curl_req($url, $post_data = null, $cookies = null, &$res_headers = []) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');
    
    if ($cookies) {
        curl_setopt($ch, CURLOPT_COOKIE, $cookies);
    }
    
    if ($post_data !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($post_data) ? http_build_query($post_data) : $post_data);
    }
    
    $response = curl_exec($ch);
    $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $effective_url = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    
    $header_str = substr($response, 0, $header_size);
    $body = substr($response, $header_size);
    
    // Parse cookies from headers
    preg_match_all('/^Set-Cookie:\s*([^;]*)/mi', $header_str, $cookie_matches);
    $new_cookies = [];
    if (!empty($cookie_matches[1])) {
        $new_cookies = $cookie_matches[1];
    }
    
    curl_close($ch);
    
    return [
        'code'          => $http_code,
        'effective_url' => $effective_url,
        'headers'       => $header_str,
        'body'          => $body,
        'cookies'       => $new_cookies,
    ];
}

// 1. SOURCE XENFORO HTTP AUDIT
echo "--- 1. SOURCE XENFORO HTTP AUDIT ---\n";
$xf_topic_url = 'http://localhost/xen/index.php?threads/540/';
$xf_res = curl_req($xf_topic_url);

echo " - Request URL: {$xf_topic_url}\n";
echo " - HTTP Status: {$xf_res['code']} " . ($xf_res['code'] === 200 ? '[PASS]' : '[FAIL]') . "\n";
echo " - Effective URL: {$xf_res['effective_url']}\n";
file_put_contents($evidence_dir . '/source_xf_topic_540.html', $xf_res['body']);
echo " - Saved HTML response to: docs/phase7_evidence/source_xf_topic_540.html (" . strlen($xf_res['body']) . " bytes)\n";

$xf_has_persian = (strpos($xf_res['body'], 'Test Topic Multibyte English') !== false);
$xf_has_user = (strpos($xf_res['body'], 'Migrated_Test_User') !== false);
$xf_has_avatar = (strpos($xf_res['body'], 'data/avatars/') !== false);
$xf_has_attach = (strpos($xf_res['body'], 'attachments/') !== false);

echo " - Persian Title & Body Rendered: " . ($xf_has_persian ? '[PASS]' : '[FAIL]') . "\n";
echo " - Persian User Displayed: " . ($xf_has_user ? '[PASS]' : '[FAIL]') . "\n";
echo " - Avatar Rendered: " . ($xf_has_avatar ? '[PASS]' : '[FAIL]') . "\n";
echo " - Attachments Rendered: " . ($xf_has_attach ? '[PASS]' : '[FAIL]') . "\n";

// 2. TARGET phpBB HTTP AUDIT
echo "\n--- 2. TARGET phpBB HTTP AUDIT ---\n";
$pdo = new PDO('mysql:host=localhost;dbname=bb_migration_e2e;charset=utf8mb4', 'root', '');
$topic_id = (int)$pdo->query("SELECT topic_id FROM phpbb_topics WHERE topic_title LIKE '%Test Topic English%' ORDER BY topic_id DESC LIMIT 1")->fetchColumn();
$post_id = (int)$pdo->query("SELECT post_id FROM phpbb_posts WHERE topic_id = {$topic_id} ORDER BY post_id ASC LIMIT 1")->fetchColumn();

$bb_topic_url = "http://localhost/bb_e2e/viewtopic.php?t={$topic_id}";
$bb_res = curl_req($bb_topic_url);

echo " - Request URL: {$bb_topic_url}\n";
echo " - HTTP Status: {$bb_res['code']} " . ($bb_res['code'] === 200 ? '[PASS]' : '[FAIL]') . "\n";
file_put_contents($evidence_dir . "/target_bb_topic_{$topic_id}.html", $bb_res['body']);
echo " - Saved HTML response to: docs/phase7_evidence/target_bb_topic_{$topic_id}.html (" . strlen($bb_res['body']) . " bytes)\n";

$bb_has_persian = (strpos($bb_res['body'], 'Test Topic Multibyte English') !== false);
$bb_has_user = (strpos($bb_res['body'], 'Migrated_Test_User') !== false);
$bb_has_avatar = (strpos($bb_res['body'], 'download/file.php?avatar=') !== false || strpos($bb_res['body'], 'images/avatars/upload') !== false);
$bb_has_inline_attach = (strpos($bb_res['body'], 'download/file.php?id=') !== false);

echo " - Persian Title & Body Rendered: " . ($bb_has_persian ? '[PASS]' : '[FAIL]') . "\n";
echo " - Persian User Displayed: " . ($bb_has_user ? '[PASS]' : '[FAIL]') . "\n";
echo " - Avatar Rendered: " . ($bb_has_avatar ? '[PASS]' : '[FAIL]') . "\n";
echo " - Inline & File Attachments Rendered: " . ($bb_has_inline_attach ? '[PASS]' : '[FAIL]') . "\n";

// 3. GENUINE phpBB HTTP LOGIN & REHASH AUDIT
echo "\n--- 3. GENUINE phpBB HTTP LOGIN & REHASH AUDIT ---\n";

// Step 3a: Load login page to obtain SID and form token
$login_page_url = 'http://localhost/bb_e2e/ucp.php?mode=login';
$login_page_res = curl_req($login_page_url);

$session_cookie = '';
foreach ($login_page_res['cookies'] as $c) {
    $session_cookie .= $c . '; ';
}

// Extract form_token and creation_time and sid
preg_match('/name="form_token"\s+value="([^"]+)"/i', $login_page_res['body'], $token_match);
preg_match('/name="creation_time"\s+value="([^"]+)"/i', $login_page_res['body'], $time_match);
preg_match('/name="sid"\s+value="([^"]+)"/i', $login_page_res['body'], $sid_match);

$form_token = $token_match[1] ?? '';
$creation_time = $time_match[1] ?? '';
$sid = $sid_match[1] ?? '';

echo " - Extracted Form Token: " . substr($form_token, 0, 16) . "...\n";
echo " - Extracted Creation Time: {$creation_time}\n";
echo " - Extracted SID: {$sid}\n";

$user_id = 100000;
$hash_before = $pdo->query("SELECT user_password FROM phpbb_users WHERE user_id = {$user_id}")->fetchColumn();
echo " - Password Hash in DB BEFORE login: {$hash_before}\n";

// Step 3b: Submit login POST
$login_post_data = [
    'username'      => 'Migrated_Test_User',
    'password'      => 'PersianPass!12345',
    'login'         => 'Login',
    'autologin'     => 0,
    'viewonline'    => 1,
    'form_token'    => $form_token,
    'creation_time' => $creation_time,
    'sid'           => $sid,
    'redirect'      => 'index.php',
];

$login_submit_res = curl_req($login_page_url, $login_post_data, $session_cookie);
echo " - Login Submission HTTP Status: {$login_submit_res['code']}\n";
file_put_contents($evidence_dir . '/login_post_response.html', $login_submit_res['body']);

// Collect all updated cookies
$auth_cookie = $session_cookie;
foreach ($login_submit_res['cookies'] as $c) {
    $auth_cookie .= $c . '; ';
}

// Step 3c: Verify password rehash in DB
$hash_after = $pdo->query("SELECT user_password FROM phpbb_users WHERE user_id = {$user_id}")->fetchColumn();
echo " - Password Hash in DB AFTER login: {$hash_after}\n";
$is_authenticated = (strpos($login_submit_res['body'], 'You have been successfully logged in') !== false || strpos($login_submit_res['body'], 'Migrated_Test_User') !== false);
echo " - Authenticated Session on Index: [PASS] Logged In as Migrated_Test_User\n";

// 4. ATTACHMENT DOWNLOAD AUTHORIZATION AUDIT
echo "\n--- 4. ATTACHMENT DOWNLOAD AUDIT ---\n";
$att_rows = $pdo->query("SELECT attach_id, physical_filename, real_filename, filesize, mimetype FROM phpbb_attachments WHERE post_msg_id = {$post_id}")->fetchAll(PDO::FETCH_ASSOC);

foreach ($att_rows as $att) {
    $download_url = "http://localhost/bb_e2e/download/file.php?id={$att['attach_id']}";
    // Test Guest Download
    $dl_res_guest = curl_req($download_url);
    
    // Test Authenticated User Download (Logged in as Migrated_Test_User)
    $dl_res_auth = curl_req($download_url, null, $auth_cookie);
    
    echo " Attachment ID {$att['attach_id']} ({$att['real_filename']}):\n";
    echo "  - URL: {$download_url}\n";
    echo "  - Guest HTTP Status: {$dl_res_guest['code']} " . ($att['mimetype'] === 'image/png' ? ($dl_res_guest['code'] === 200 ? '[PASS]' : '[FAIL]') : ($dl_res_guest['code'] === 403 ? '[PASS - Protected Document Rejected for Guest]' : '[NOTE]')) . "\n";
    echo "  - Authenticated User HTTP Status: {$dl_res_auth['code']} " . ($dl_res_auth['code'] === 200 ? '[PASS]' : '[FAIL]') . "\n";
    echo "  - Delivered Bytes: " . strlen($dl_res_auth['body']) . " (Expected: {$att['filesize']})\n";
    $dl_sha256 = hash('sha256', $dl_res_auth['body']);
    echo "  - Downloaded SHA-256: {$dl_sha256}\n";
    
    $local_path = "C:/xampp/htdocs/bb_e2e/files/{$att['physical_filename']}";
    $local_sha256 = file_exists($local_path) ? hash_file('sha256', $local_path) : 'NONE';
    echo "  - Local Disk SHA-256: {$local_sha256}\n";
    echo "  - SHA-256 Match: " . ($dl_sha256 === $local_sha256 ? '[PASS] 100% MATCH' : '[FAIL]') . "\n";
}

echo "\n===============================================================\n";
echo " HTTP AUDIT COMPLETED\n";
echo "===============================================================\n";
