<?php
$pdo = new PDO('mysql:host=localhost;dbname=xen;charset=utf8mb4', 'root', '');

echo "=== 1. TOPICS (xf_thread) ===\n";
echo "Total rows: " . $pdo->query("SELECT COUNT(*) FROM xf_thread")->fetchColumn() . "\n";
echo "Threads with thread_id > 535:\n";
foreach ($pdo->query("SELECT thread_id, node_id, title, user_id, username, post_date FROM xf_thread WHERE thread_id > 535 ORDER BY thread_id ASC") as $r) {
    echo " - Thread ID {$r['thread_id']}: '{$r['title']}' by User ID {$r['user_id']} ({$r['username']}), Node: {$r['node_id']}, Date: {$r['post_date']}\n";
}

echo "\n=== 2. POSTS (xf_post) ===\n";
echo "Total rows: " . $pdo->query("SELECT COUNT(*) FROM xf_post")->fetchColumn() . "\n";
echo "Posts with post_id > 7818:\n";
foreach ($pdo->query("SELECT post_id, thread_id, user_id, username, post_date FROM xf_post WHERE post_id > 7818 ORDER BY post_id ASC") as $r) {
    echo " - Post ID {$r['post_id']}: Thread ID {$r['thread_id']}, User ID {$r['user_id']} ({$r['username']}), Date: {$r['post_date']}\n";
}

echo "\n=== 3. ATTACHMENTS (xf_attachment & xf_attachment_data) ===\n";
echo "Total xf_attachment rows: " . $pdo->query("SELECT COUNT(*) FROM xf_attachment")->fetchColumn() . "\n";
echo "Total xf_attachment_data rows: " . $pdo->query("SELECT COUNT(*) FROM xf_attachment_data")->fetchColumn() . "\n";
foreach ($pdo->query("SELECT a.attachment_id, a.data_id, a.content_type, a.content_id, d.filename, d.file_size, d.file_key FROM xf_attachment a INNER JOIN xf_attachment_data d ON (a.data_id = d.data_id) ORDER BY a.attachment_id ASC") as $r) {
    echo " - Attachment ID {$r['attachment_id']}: Data ID {$r['data_id']}, Content: {$r['content_type']} #{$r['content_id']}, Filename: '{$r['filename']}', Size: {$r['file_size']} bytes, Key: {$r['file_key']}\n";
}

echo "\n=== 4. AVATARS ===\n";
foreach ($pdo->query("SELECT user_id, username, avatar_date, avatar_width, avatar_height FROM xf_user WHERE avatar_date > 0 ORDER BY user_id ASC") as $r) {
    echo " - User ID {$r['user_id']} ({$r['username']}): avatar_date={$r['avatar_date']}, width={$r['avatar_width']}, height={$r['avatar_height']}\n";
}
