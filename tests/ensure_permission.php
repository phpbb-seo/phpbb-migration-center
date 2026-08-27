<?php
$pdo = new PDO('mysql:host=localhost;dbname=bb_migration_e2e;charset=utf8mb4', 'root', '');
$perm = $pdo->query("SELECT auth_option_id FROM phpbb_acl_options WHERE auth_option = 'a_migrationcenter'")->fetchColumn();
if (!$perm) {
    $pdo->exec("INSERT INTO phpbb_acl_options (auth_option, is_global, is_local, founder_only) VALUES ('a_migrationcenter', 1, 0, 0)");
    echo "Permission a_migrationcenter inserted.\n";
} else {
    echo "Permission a_migrationcenter already exists (ID: {$perm}).\n";
}
