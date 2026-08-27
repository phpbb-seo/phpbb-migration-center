<?php
require_once 'C:/xampp/htdocs/bb_e2e/vendor/autoload.php';

spl_autoload_register(function ($class) {
    if (strpos($class, 'phpbb\\') === 0) {
        $file = 'C:/xampp/htdocs/bb_e2e/' . str_replace('\\', '/', $class) . '.php';
        if (file_exists($file)) require_once $file;
    } else if (strpos($class, 'phpbbseo\\migrationcenter\\') === 0) {
        $file = 'C:/xampp/htdocs/bb/ext/phpbbseo/migrationcenter/' . str_replace('\\', '/', substr($class, strlen('phpbbseo\\migrationcenter\\'))) . '.php';
        if (file_exists($file)) require_once $file;
    }
});

$db_user = 'root';
$db_pass = '';
$db_host = 'localhost';
$db_name = 'bb_migration_resume_test';

echo "===============================================================\n";
echo " REAL INTERRUPTION & RESUME INTEGRATION AUDIT\n";
echo "===============================================================\n\n";

$pdo_server = new PDO("mysql:host={$db_host}", $db_user, $db_pass);
$pdo_server->exec("DROP DATABASE IF EXISTS `{$db_name}`");
$pdo_server->exec("CREATE DATABASE `{$db_name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_bin");

// Load clean phpBB backup
echo " - Restoring clean phpBB target database: {$db_name}...\n";
$clean_sql_file = 'C:/xampp/htdocs/bb_e2e/clean_utf8_backup.sql';
exec("C:\\xampp\\mysql\\bin\\mysql.exe -u root {$db_name} < \"{$clean_sql_file}\"");
$pdo_target = new PDO("mysql:host={$db_host};dbname={$db_name};charset=utf8mb4", $db_user, $db_pass);

// Enable extension schema in resume test db
$pdo_target->exec("CREATE TABLE IF NOT EXISTS `phpbb_migration_runs` (
  `run_id` VARCHAR(36) NOT NULL,
  `source_system` VARCHAR(50) NOT NULL,
  `status` VARCHAR(20) NOT NULL DEFAULT 'pending',
  `start_time` INT(11) UNSIGNED NOT NULL DEFAULT 0,
  `end_time` INT(11) UNSIGNED NOT NULL DEFAULT 0,
  `config_json` LONGTEXT NOT NULL DEFAULT '',
  PRIMARY KEY (`run_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin");

$pdo_target->exec("CREATE TABLE IF NOT EXISTS `phpbb_migration_steps` (
  `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `run_id` VARCHAR(36) NOT NULL,
  `step_name` VARCHAR(50) NOT NULL,
  `status` VARCHAR(20) NOT NULL DEFAULT 'pending',
  `items_total` INT(11) UNSIGNED NOT NULL DEFAULT 0,
  `items_processed` INT(11) UNSIGNED NOT NULL DEFAULT 0,
  `items_imported` INT(11) UNSIGNED NOT NULL DEFAULT 0,
  `items_skipped` INT(11) UNSIGNED NOT NULL DEFAULT 0,
  `items_failed` INT(11) UNSIGNED NOT NULL DEFAULT 0,
  `cursor_position` VARCHAR(255) NOT NULL DEFAULT '',
  `start_time` INT(11) UNSIGNED NOT NULL DEFAULT 0,
  `end_time` INT(11) UNSIGNED NOT NULL DEFAULT 0,
  `error_log_json` LONGTEXT NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  KEY `idx_run_step` (`run_id`, `step_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin");

$pdo_target->exec("CREATE TABLE IF NOT EXISTS `phpbb_migration_id_map` (
  `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `run_id` VARCHAR(36) NOT NULL,
  `source_system` VARCHAR(50) NOT NULL,
  `content_type` VARCHAR(50) NOT NULL,
  `source_id` VARCHAR(100) NOT NULL,
  `target_id` VARCHAR(100) NOT NULL,
  `status` VARCHAR(30) NOT NULL DEFAULT 'mapped',
  `checksum` VARCHAR(64) NOT NULL DEFAULT '',
  `metadata_json` LONGTEXT NOT NULL DEFAULT '',
  `created_at` INT(11) UNSIGNED NOT NULL DEFAULT 0,
  `updated_at` INT(11) UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_lookup` (`source_system`, `content_type`, `source_id`),
  KEY `idx_target` (`content_type`, `target_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin");

$pdo_target->exec("CREATE TABLE IF NOT EXISTS `phpbb_migration_locks` (
  `lock_key` VARCHAR(64) NOT NULL,
  `owner_token` VARCHAR(64) NOT NULL,
  `acquired_at` INT(11) UNSIGNED NOT NULL,
  `expires_at` INT(11) UNSIGNED NOT NULL,
  PRIMARY KEY (`lock_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin");

echo " [PASS] Resume test database initialized with clean schema.\n";

// Convert all tables in resume test db to utf8mb4
foreach ($pdo_target->query("SHOW TABLES LIKE 'phpbb_%'")->fetchAll(PDO::FETCH_COLUMN) as $t) {
    $pdo_target->exec("ALTER TABLE `{$t}` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_bin");
}

// Perform Migration Phase 1: Run until 2 post batches (400 posts)
echo "\n--- 1. EXECUTING INITIAL RUN WITH FORCED INTERRUPTION ---\n";
// Create Run ID
$run_id = 'resume-test-' . bin2hex(random_bytes(8));
$pdo_target->exec("INSERT INTO phpbb_migration_runs (run_id, source_system, status, start_time) VALUES ('{$run_id}', 'xenforo', 'running', " . time() . ")");

$config_dto = \phpbbseo\migrationcenter\core\dto\migration_config_dto::from_array([
    'source_system' => 'xenforo',
    'source_path'   => 'C:/xampp/htdocs/xen',
    'batch_size'    => 200,
    'db_host'       => 'localhost',
    'db_user'       => 'root',
    'db_password'   => '',
    'db_name'       => 'xen',
    'db_port'       => 3306,
]);

// Real phpBB DBAL Driver
$dbal = new \phpbb\db\driver\mysqli();
$dbal->sql_connect('localhost', 'root', '', 'bb_migration_resume_test', 3306, false, false);

$id_mapper = new \phpbbseo\migrationcenter\core\mapping\id_mapper($dbal, 'phpbb_');
$state_mgr = new \phpbbseo\migrationcenter\core\state\state_manager($dbal, 'phpbb_');
$lock_mgr = new \phpbbseo\migrationcenter\core\state\lock_manager($dbal, 'phpbb_', 300);
$cfg_mock = new \phpbb\config\config([]);
$cache_mock = new \phpbb\cache\driver\dummy();
$writer = new \phpbbseo\migrationcenter\core\writer\phpbb_target_writer($dbal, $cfg_mock, $cache_mock, $id_mapper, 'phpbb_');

$provider = new \phpbbseo\migrationcenter\source\xenforo\xenforo_source_provider('C:/xampp/htdocs/bb_e2e/');
$groups_step = new \phpbbseo\migrationcenter\source\xenforo\step\groups_step();
$users_step = new \phpbbseo\migrationcenter\source\xenforo\step\users_step();
$forums_step = new \phpbbseo\migrationcenter\source\xenforo\step\forums_step();
$topics_step = new \phpbbseo\migrationcenter\source\xenforo\step\topics_step();
$posts_step = new \phpbbseo\migrationcenter\source\xenforo\step\posts_step();

// 1. Run groups, users, forums, topics completely
$groups_step->process_batch($run_id, 0, 200, $config_dto, $provider, $writer);
$users_step->process_batch($run_id, 0, 200, $config_dto, $provider, $writer);
$forums_step->process_batch($run_id, 0, 200, $config_dto, $provider, $writer);

$t_cursor = 0;
while (true) {
    $t_res = $topics_step->process_batch($run_id, $t_cursor, 200, $config_dto, $provider, $writer);
    if ($t_res->is_completed || $t_res->read_count === 0) break;
    $t_cursor = $t_res->next_cursor;
}

// 2. Run posts for Batch 1 (cursor 0 -> 202)
$res_batch1 = $posts_step->process_batch($run_id, 0, 200, $config_dto, $provider, $writer);
$state_mgr->update_step($run_id, 'posts', 'running', $res_batch1->next_cursor, $res_batch1->imported_count, $res_batch1->skipped_count, $res_batch1->failed_count);

// 3. Run posts for Batch 2 (cursor 202 -> 402)
$res_batch2 = $posts_step->process_batch($run_id, $res_batch1->next_cursor, 200, $config_dto, $provider, $writer);
$state_mgr->update_step($run_id, 'posts', 'running', $res_batch2->next_cursor, $res_batch2->imported_count, $res_batch2->skipped_count, $res_batch2->failed_count);

// SIMULATE CRASH / INTERRUPT
$interrupted_cursor = $res_batch2->next_cursor;
$interrupted_posts_count = (int)$pdo_target->query("SELECT COUNT(*) FROM phpbb_posts WHERE post_id > 1")->fetchColumn();
echo " [INTERRUPTED] Migration halted unexpectedly after 2 batches.\n";
echo "  - Last Saved Cursor: {$interrupted_cursor}\n";
echo "  - Intermediate Target Posts in DB: {$interrupted_posts_count} (Expected: 400)\n";

// 4. RESUME EXACT SAME RUN_ID
echo "\n--- 2. RESUMING MIGRATION FROM SAVED CURSOR ({$interrupted_cursor}) ---\n";
$current_cursor = $interrupted_cursor;
$resumed_batches = 0;
while (true) {
    $res = $posts_step->process_batch($run_id, $current_cursor, 200, $config_dto, $provider, $writer);
    
    $state_mgr->update_step($run_id, 'posts', $res->is_completed ? 'completed' : 'running', $res->next_cursor, $res->imported_count, $res->skipped_count, $res->failed_count);
    $resumed_batches++;
    
    if ($res->is_completed || $res->read_count === 0) {
        break;
    }
    $current_cursor = $res->next_cursor;
}

echo " [COMPLETE] Resumed migration finished after {$resumed_batches} additional batches.\n";

// 5. AUDIT FINAL COUNTS & DUPLICATES
echo "\n--- 3. POST-RESUME INTEGRITY AUDIT ---\n";
$final_posts_count = (int)$pdo_target->query("SELECT COUNT(*) FROM phpbb_posts WHERE post_id > 1")->fetchColumn();
echo " - Total Migrated Posts in DB: {$final_posts_count} (Expected: 7820) " . ($final_posts_count === 7820 ? '[PASS]' : '[FAIL]') . "\n";

// Check for any duplicate mapped source IDs
$dup_maps = $pdo_target->query("SELECT source_id, COUNT(*) as cnt FROM phpbb_migration_id_map WHERE content_type = 'post' GROUP BY source_id HAVING cnt > 1")->fetchAll(PDO::FETCH_ASSOC);
echo " - Duplicate Mapped Post IDs: " . count($dup_maps) . " " . (count($dup_maps) === 0 ? '[PASS]' : '[FAIL]') . "\n";

// Check for any missing source IDs
$src_pdo = new PDO("mysql:host={$db_host};dbname=xen;charset=utf8mb4", $db_user, $db_pass);
$src_ids = $src_pdo->query("SELECT post_id FROM xf_post ORDER BY post_id ASC")->fetchAll(PDO::FETCH_COLUMN);
$mapped_ids = $pdo_target->query("SELECT source_id FROM phpbb_migration_id_map WHERE content_type = 'post'")->fetchAll(PDO::FETCH_COLUMN);

$diff = array_diff($src_ids, $mapped_ids);
echo " - Missing Source Post IDs: " . count($diff) . " " . (count($diff) === 0 ? '[PASS]' : '[FAIL]') . "\n";

echo "\n===============================================================\n";
echo " INTERRUPTION & RESUME AUDIT COMPLETED\n";
echo "===============================================================\n";
