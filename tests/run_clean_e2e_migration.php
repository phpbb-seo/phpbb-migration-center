<?php
$db_name = 'bb_migration_e2e';
$pdo = new PDO("mysql:host=localhost;dbname={$db_name};charset=utf8mb4", 'root', '');

echo "=== Resetting clean target state ===\n";
// Clean up all migration-owned records and tables
$pdo->exec("DELETE FROM phpbb_users WHERE user_id > 2 AND user_type != 2");
$pdo->exec("DELETE FROM phpbb_forums WHERE forum_id > 2");
$pdo->exec("DELETE FROM phpbb_topics WHERE topic_id > 1");
$pdo->exec("DELETE FROM phpbb_posts WHERE post_id > 1");
$pdo->exec("DELETE FROM phpbb_attachments");
$pdo->exec("DELETE FROM phpbb_privmsgs");
$pdo->exec("DELETE FROM phpbb_privmsgs_to");
$pdo->exec("DELETE FROM phpbb_poll_options");
$pdo->exec("DELETE FROM phpbb_poll_votes");
$pdo->exec("DELETE FROM phpbb_banlist");

$pdo->exec("DELETE FROM phpbb_migration_runs");
$pdo->exec("DELETE FROM phpbb_migration_steps");
$pdo->exec("DELETE FROM phpbb_migration_id_map");
$pdo->exec("DELETE FROM phpbb_migration_errors");
$pdo->exec("DELETE FROM phpbb_migration_locks");

// Clean physical target files
$target_files = glob('C:/xampp/htdocs/bb_e2e/files/*');
foreach ($target_files as $f) {
    if (basename($f) !== '.htaccess' && basename($f) !== 'index.htm') {
        @unlink($f);
    }
}

$target_avatars = glob('C:/xampp/htdocs/bb_e2e/images/avatars/upload/*');
foreach ($target_avatars as $f) {
    if (basename($f) !== '.htaccess' && basename($f) !== 'index.htm') {
        @unlink($f);
    }
}

// Ensure avatar configuration is open for migration
$pdo->exec("UPDATE phpbb_config SET config_value = '1' WHERE config_name = 'allow_avatar'");
$pdo->exec("UPDATE phpbb_config SET config_value = '1' WHERE config_name = 'allow_avatar_upload'");
$pdo->exec("UPDATE phpbb_config SET config_value = '524288' WHERE config_name = 'avatar_filesize'");
$pdo->exec("UPDATE phpbb_config SET config_value = '300' WHERE config_name = 'avatar_max_width'");
$pdo->exec("UPDATE phpbb_config SET config_value = '300' WHERE config_name = 'avatar_max_height'");

echo "Clean state prepared successfully.\n";
