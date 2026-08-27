<?php
$p = new PDO('mysql:host=localhost;dbname=bb_migration_e2e', 'root', '');
$res = $p->query("SHOW COLUMNS FROM phpbb_migration_id_map")->fetchAll(PDO::FETCH_ASSOC);
print_r($res);
$post = $p->query("SELECT forum_id FROM phpbb_posts WHERE post_id = 23752")->fetchColumn();
echo "Post 23752 forum_id: {$post}\n";
