<?php
$p = new PDO('mysql:host=localhost;dbname=xen', 'root', '');

echo "=== Top 10 latest xf_post rows ===\n";
$stmt = $p->query("SELECT post_id, thread_id, user_id, username, post_date FROM xf_post ORDER BY post_id DESC LIMIT 10");
while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo "post_id: {$r['post_id']}, thread_id: {$r['thread_id']}, user_id: {$r['user_id']}, username: {$r['username']}, post_date: {$r['post_date']}\n";
}

echo "\n=== Table Counts in XenForo ===\n";
$tables = [
    'xf_post', 'xf_thread', 'xf_user', 'xf_attachment', 'xf_attachment_data',
    'xf_conversation_master', 'xf_conversation_user', 'xf_conversation_message',
    'xf_poll', 'xf_poll_response', 'xf_poll_vote',
    'xf_user_ban', 'xf_ban_email', 'xf_ip_match',
    'xf_thread_watch', 'xf_forum_watch'
];

foreach ($tables as $t) {
    try {
        $cnt = $p->query("SELECT COUNT(*) FROM {$t}")->fetchColumn();
        echo "{$t}: {$cnt}\n";
    } catch (Exception $e) {
        echo "{$t}: Table not found or error ({$e->getMessage()})\n";
    }
}
