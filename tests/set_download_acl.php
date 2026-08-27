<?php
$p = new PDO('mysql:host=localhost;dbname=bb_migration_e2e;charset=utf8mb4', 'root', '');
$opts = $p->query("SELECT auth_option_id, auth_option FROM phpbb_acl_options WHERE auth_option IN ('f_download', 'u_download', 'f_read')")->fetchAll(PDO::FETCH_KEY_PAIR);
print_r($opts);

$f_dl_id = $opts['f_download'] ?? 0;
$f_read_id = $opts['f_read'] ?? 0;
$u_dl_id = $opts['u_download'] ?? 0;

// Directly give Registered Users (group 2) f_download, f_read, u_download
$p->exec("INSERT IGNORE INTO phpbb_acl_groups (group_id, forum_id, auth_option_id, auth_role_id, auth_setting) VALUES 
(2, 0, {$u_dl_id}, 0, 1),
(2, 507, {$f_dl_id}, 0, 1),
(2, 507, {$f_read_id}, 0, 1),
(1, 507, {$f_read_id}, 0, 1)
");

// Clear acl cache
$p->exec("DELETE FROM phpbb_acl_users");
$p->exec("UPDATE phpbb_users SET user_permissions = ''");
echo "ACL download permissions configured for forum 507.\n";
