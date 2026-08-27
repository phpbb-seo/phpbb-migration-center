<?php
$db_user = 'root';
$db_pass = '';
$db_host = 'localhost';
$db_name = 'bb';

echo "=== Preparing Clean phpBB Database for bb ===\n";

$pdo_server = new PDO("mysql:host={$db_host}", $db_user, $db_pass);
$pdo_server->exec("DROP DATABASE IF EXISTS `{$db_name}`");
$pdo_server->exec("CREATE DATABASE `{$db_name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_bin");

// Restore clean backup
$clean_sql_file = 'C:/xampp/htdocs/bb_e2e/clean_utf8_backup.sql';
exec("C:\\xampp\\mysql\\bin\\mysql.exe -u root {$db_name} < \"{$clean_sql_file}\"");

$pdo = new PDO("mysql:host={$db_host};dbname={$db_name};charset=utf8mb4", $db_user, $db_pass);

// Verify tables
$tables = $pdo->query("SHOW TABLES LIKE 'phpbb_%'")->fetchAll(PDO::FETCH_COLUMN);
echo "Restored " . count($tables) . " tables to database `bb`.\n";

// Convert tables to utf8mb4
foreach ($tables as $t) {
    $pdo->exec("ALTER TABLE `{$t}` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_bin");
}

// Clean files/ folder in bb (except .htaccess and index.htm)
$files_dir = 'C:/xampp/htdocs/bb/files';
$deleted_files = 0;
if (is_dir($files_dir)) {
    foreach (scandir($files_dir) as $f) {
        if ($f === '.' || $f === '..' || $f === '.htaccess' || $f === 'index.htm') continue;
        @unlink($files_dir . '/' . $f);
        $deleted_files++;
    }
}
echo "Cleaned {$deleted_files} files from {$files_dir}.\n";

// Clean images/avatars/upload folder in bb (except .htaccess and index.htm)
$avatar_dir = 'C:/xampp/htdocs/bb/images/avatars/upload';
$deleted_avatars = 0;
if (is_dir($avatar_dir)) {
    foreach (scandir($avatar_dir) as $f) {
        if ($f === '.' || $f === '..' || $f === '.htaccess' || $f === 'index.htm') continue;
        @unlink($avatar_dir . '/' . $f);
        $deleted_avatars++;
    }
}
echo "Cleaned {$deleted_avatars} avatar files from {$avatar_dir}.\n";
