<?php
$pdo = new PDO('mysql:host=localhost;dbname=bb_migration_e2e;charset=utf8mb4', 'root', '');

// Grant Standard Forum permissions (role_id = 15 = ROLE_FORUM_STANDARD or full forum permissions) to Registered Users (group 2) and Guests (group 1) on all forums
$forum_ids = $pdo->query("SELECT forum_id FROM phpbb_forums")->fetchAll(PDO::FETCH_COLUMN);
foreach ($forum_ids as $fid) {
    // Check if group 2 (Registered Users) has role on forum
    $exists = $pdo->query("SELECT 1 FROM phpbb_acl_groups WHERE group_id = 2 AND forum_id = {$fid}")->fetchColumn();
    if (!$exists) {
        $pdo->exec("INSERT INTO phpbb_acl_groups (group_id, forum_id, auth_option_id, auth_role_id, auth_setting) VALUES (2, {$fid}, 0, 15, 0)");
    }
    // Check if group 1 (Guests) has role on forum (role 17 = ROLE_FORUM_BOT / READONLY)
    $exists_guest = $pdo->query("SELECT 1 FROM phpbb_acl_groups WHERE group_id = 1 AND forum_id = {$fid}")->fetchColumn();
    if (!$exists_guest) {
        $pdo->exec("INSERT INTO phpbb_acl_groups (group_id, forum_id, auth_option_id, auth_role_id, auth_setting) VALUES (1, {$fid}, 0, 17, 0)");
    }
}

// Clear ACL cache in config
$pdo->exec("DELETE FROM phpbb_acl_users WHERE forum_id > 0");
echo "Standard forum permissions assigned to Registered Users and Guests.\n";
