<?php
$pdo = new PDO('mysql:host=localhost;dbname=bb_migration_e2e', 'root', '');

echo "=== Target phpBB Clean Baseline Inventory (bb_migration_e2e) ===\n";
echo "Users (total): " . $pdo->query("SELECT COUNT(*) FROM phpbb_users")->fetchColumn() . "\n";
echo " - Founders/Admins (type 3): " . $pdo->query("SELECT COUNT(*) FROM phpbb_users WHERE user_type = 3")->fetchColumn() . "\n";
echo " - Normal (type 0): " . $pdo->query("SELECT COUNT(*) FROM phpbb_users WHERE user_type = 0")->fetchColumn() . "\n";
echo " - Inactive (type 1): " . $pdo->query("SELECT COUNT(*) FROM phpbb_users WHERE user_type = 1")->fetchColumn() . "\n";
echo " - Ignore/Bots (type 2): " . $pdo->query("SELECT COUNT(*) FROM phpbb_users WHERE user_type = 2")->fetchColumn() . "\n";
echo " - Anonymous (ID 1): " . $pdo->query("SELECT COUNT(*) FROM phpbb_users WHERE user_id = 1")->fetchColumn() . "\n";
echo "Groups: " . $pdo->query("SELECT COUNT(*) FROM phpbb_groups")->fetchColumn() . "\n";
echo "Forums: " . $pdo->query("SELECT COUNT(*) FROM phpbb_forums")->fetchColumn() . "\n";
echo "Topics: " . $pdo->query("SELECT COUNT(*) FROM phpbb_topics")->fetchColumn() . "\n";
echo "Posts: " . $pdo->query("SELECT COUNT(*) FROM phpbb_posts")->fetchColumn() . "\n";
echo "Attachments: " . $pdo->query("SELECT COUNT(*) FROM phpbb_attachments")->fetchColumn() . "\n";
echo "Privmsgs: " . $pdo->query("SELECT COUNT(*) FROM phpbb_privmsgs")->fetchColumn() . "\n";
echo "Poll Options: " . $pdo->query("SELECT COUNT(*) FROM phpbb_poll_options")->fetchColumn() . "\n";
echo "Poll Votes: " . $pdo->query("SELECT COUNT(*) FROM phpbb_poll_votes")->fetchColumn() . "\n";
echo "Bans: " . $pdo->query("SELECT COUNT(*) FROM phpbb_banlist")->fetchColumn() . "\n";
