<?php
$source_bb = 'C:/xampp/htdocs/bb';
$target_bb = 'C:/xampp/htdocs/bb_e2e';
$db_name = 'bb_migration_e2e';

echo "=== STEP 1: Setting up isolated target database {$db_name} ===\n";
$pdo = new PDO('mysql:host=localhost', 'root', '');
$pdo->exec("DROP DATABASE IF EXISTS `{$db_name}`");
$pdo->exec("CREATE DATABASE `{$db_name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

// Connect to new database
$pdo_target = new PDO("mysql:host=localhost;dbname={$db_name};charset=utf8mb4", 'root', '');
$pdo_src = new PDO("mysql:host=localhost;dbname=bb;charset=utf8mb4", 'root', '');

// Get all phpbb base tables (excluding migrationcenter extension runtime/temporary tables)
$tables = $pdo_src->query("SHOW TABLES LIKE 'phpbb_%'")->fetchAll(PDO::FETCH_COLUMN);
foreach ($tables as $table) {
    // Exclude extension-generated tables so extension installer can run cleanly
    if (strpos($table, 'phpbb_migration_') === 0) {
        continue;
    }
    
    // Copy table schema
    $create_stmt = $pdo_src->query("SHOW CREATE TABLE `{$table}`")->fetch(PDO::FETCH_ASSOC)['Create Table'];
    $pdo_target->exec($create_stmt);
    
    // For base phpBB configuration, styles, modules, roles, permissions: copy rows
    // For posts, topics, users, clean up any migrated test rows to establish a clean phpBB baseline
    if (in_array($table, ['phpbb_users', 'phpbb_topics', 'phpbb_posts', 'phpbb_forums', 'phpbb_attachments', 'phpbb_privmsgs', 'phpbb_privmsgs_to', 'phpbb_poll_options', 'phpbb_poll_votes', 'phpbb_banlist'])) {
        if ($table === 'phpbb_users') {
            // Keep only Founder (user_id = 2) and Anonymous (user_id = 1) and Bots (user_type = 2)
            $pdo_src->query("SELECT * FROM phpbb_users WHERE user_id <= 2 OR user_type = 2");
            $rows = $pdo_src->query("SELECT * FROM phpbb_users WHERE user_id <= 2 OR user_type = 2")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                $cols = array_keys($row);
                $vals = array_values($row);
                $placeholders = implode(',', array_fill(0, count($cols), '?'));
                $sql = "INSERT INTO `{$table}` (`" . implode('`,`', $cols) . "`) VALUES ({$placeholders})";
                $stmt = $pdo_target->prepare($sql);
                $stmt->execute($vals);
            }
        } else if ($table === 'phpbb_forums') {
            // Keep standard default forum
            $rows = $pdo_src->query("SELECT * FROM phpbb_forums WHERE forum_id = 2 OR forum_id = 1")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                $cols = array_keys($row);
                $vals = array_values($row);
                $placeholders = implode(',', array_fill(0, count($cols), '?'));
                $sql = "INSERT INTO `{$table}` (`" . implode('`,`', $cols) . "`) VALUES ({$placeholders})";
                $stmt = $pdo_target->prepare($sql);
                $stmt->execute($vals);
            }
        } else if ($table === 'phpbb_topics') {
            $rows = $pdo_src->query("SELECT * FROM phpbb_topics WHERE topic_id = 1")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                $cols = array_keys($row);
                $vals = array_values($row);
                $placeholders = implode(',', array_fill(0, count($cols), '?'));
                $sql = "INSERT INTO `{$table}` (`" . implode('`,`', $cols) . "`) VALUES ({$placeholders})";
                $stmt = $pdo_target->prepare($sql);
                $stmt->execute($vals);
            }
        } else if ($table === 'phpbb_posts') {
            $rows = $pdo_src->query("SELECT * FROM phpbb_posts WHERE post_id = 1")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                $cols = array_keys($row);
                $vals = array_values($row);
                $placeholders = implode(',', array_fill(0, count($cols), '?'));
                $sql = "INSERT INTO `{$table}` (`" . implode('`,`', $cols) . "`) VALUES ({$placeholders})";
                $stmt = $pdo_target->prepare($sql);
                $stmt->execute($vals);
            }
        }
    } else {
        // Copy all standard configuration/permission/module rows
        $rows = $pdo_src->query("SELECT * FROM `{$table}`")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $cols = array_keys($row);
            $vals = array_values($row);
            $placeholders = implode(',', array_fill(0, count($cols), '?'));
            $sql = "INSERT INTO `{$table}` (`" . implode('`,`', $cols) . "`) VALUES ({$placeholders})";
            $stmt = $pdo_target->prepare($sql);
            $stmt->execute($vals);
        }
    }
}

// Clean extension registration in ext table so we can cleanly enable it on target
$pdo_target->exec("DELETE FROM phpbb_ext WHERE ext_name = 'phpbbseo/migrationcenter'");
$pdo_target->exec("DELETE FROM phpbb_migrations WHERE migration_name LIKE '%phpbbseo%'");

// Update cookie settings to avoid session collisions
$pdo_target->exec("UPDATE phpbb_config SET config_value = 'phpbb3_e2e' WHERE config_name = 'cookie_name'");
$pdo_target->exec("UPDATE phpbb_config SET config_value = '/bb_e2e' WHERE config_name = 'script_path'");

echo "=== STEP 2: Cloning phpBB files to {$target_bb} ===\n";
if (!is_dir($target_bb)) {
    mkdir($target_bb, 0777, true);
}

// Recursive copy function
function recurse_copy($src, $dst) {
    $dir = opendir($src);
    @mkdir($dst);
    while (false !== ($file = readdir($dir))) {
        if (($file != '.') && ($file != '..')) {
            if (is_dir($src . '/' . $file)) {
                if ($file === 'cache') {
                    @mkdir($dst . '/' . $file);
                    continue;
                }
                recurse_copy($src . '/' . $file, $dst . '/' . $file);
            } else {
                copy($src . '/' . $file, $dst . '/' . $file);
            }
        }
    }
    closedir($dir);
}

// Copy core files
$items = ['adm', 'assets', 'bin', 'config', 'download', 'ext', 'files', 'images', 'includes', 'language', 'phpbb', 'store', 'styles', 'vendor', 'cron.php', 'index.php', 'mcp.php', 'memberlist.php', 'posting.php', 'report.php', 'search.php', 'ucp.php', 'viewforum.php', 'viewonline.php', 'viewtopic.php'];
foreach ($items as $item) {
    $s = $source_bb . '/' . $item;
    $d = $target_bb . '/' . $item;
    if (is_dir($s)) {
        recurse_copy($s, $d);
    } else if (file_exists($s)) {
        copy($s, $d);
    }
}

// Write config.php for target
$config_php = "<?php\n"
    . "// phpBB 3.3.x auto-generated configuration file\n"
    . "// Do not change anything in this file!\n"
    . "\$dbms = 'phpbb\\\\db\\\\driver\\\\mysqli';\n"
    . "\$dbhost = 'localhost';\n"
    . "\$dbport = '3306';\n"
    . "\$dbname = '{$db_name}';\n"
    . "\$dbuser = 'root';\n"
    . "\$dbpasswd = '';\n"
    . "\$table_prefix = 'phpbb_';\n"
    . "\$phpbb_adm_relative_path = 'adm/';\n"
    . "\$acm_type = 'phpbb\\\\cache\\\\driver\\\\file';\n"
    . "\$phpbb_installed = true;\n"
    . "@define('PHPBB_INSTALLED', true);\n"
    . "@define('PHPBB_ENVIRONMENT', 'production');\n";

file_put_contents($target_bb . '/config.php', $config_php);

// Ensure cache directory exists and is clean
if (!is_dir($target_bb . '/cache')) {
    mkdir($target_bb . '/cache', 0777, true);
}

echo "=== Target {$target_bb} setup completed successfully ===\n";
