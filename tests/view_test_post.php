<?php
$p = new PDO('mysql:host=localhost;dbname=bb_migration_e2e;charset=utf8mb4', 'root', '');
$post = $p->query("SELECT post_text FROM phpbb_posts WHERE post_id = 23752")->fetchColumn();
echo "Full post_text:\n" . $post . "\n";
