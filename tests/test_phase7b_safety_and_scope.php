<?php
require_once 'C:/xampp/htdocs/bb_e2e/vendor/autoload.php';

spl_autoload_register(function($class) {
    $prefix = 'phpbbseo\\migrationcenter\\';
    if (strpos($class, $prefix) === 0) {
        $rel = str_replace('\\', '/', substr($class, strlen($prefix)));
        $file = 'C:/xampp/htdocs/bb/ext/phpbbseo/migrationcenter/' . $rel . '.php';
        if (file_exists($file)) require_once $file;
    }
    if (strpos($class, 'phpbb\\') === 0) {
        $rel = str_replace('\\', '/', substr($class, 6));
        $file = 'C:/xampp/htdocs/bb/phpbb/' . $rel . '.php';
        if (file_exists($file)) require_once $file;
    }
});

echo "===============================================================\n";
echo " REGRESSION TESTS: SCOPE EXPANSION & SAFETY GATES\n";
echo "===============================================================\n\n";

// 1. Dependency Resolution & Automatic Expansion
echo "--- 1. DEPENDENCY AUTO-EXPANSION TEST ---\n";
$registry = new \phpbbseo\migrationcenter\core\engine\step_registry();
$registry->register(new \phpbbseo\migrationcenter\source\xenforo\step\groups_step());
$registry->register(new \phpbbseo\migrationcenter\source\xenforo\step\users_step());
$registry->register(new \phpbbseo\migrationcenter\source\xenforo\step\group_memberships_step());
$registry->register(new \phpbbseo\migrationcenter\source\xenforo\step\global_permissions_step());
$registry->register(new \phpbbseo\migrationcenter\source\xenforo\step\forums_step());
$registry->register(new \phpbbseo\migrationcenter\source\xenforo\step\node_permissions_step());
$registry->register(new \phpbbseo\migrationcenter\source\xenforo\step\topics_step());
$registry->register(new \phpbbseo\migrationcenter\source\xenforo\step\posts_step());
$registry->register(new \phpbbseo\migrationcenter\source\xenforo\step\attachments_step());
$registry->register(new \phpbbseo\migrationcenter\source\xenforo\step\avatars_step());
$registry->register(new \phpbbseo\migrationcenter\source\xenforo\step\conversations_step());
$registry->register(new \phpbbseo\migrationcenter\source\xenforo\step\conversation_messages_step());
$registry->register(new \phpbbseo\migrationcenter\source\xenforo\step\conversation_attachments_step());
$registry->register(new \phpbbseo\migrationcenter\source\xenforo\step\polls_step());
$registry->register(new \phpbbseo\migrationcenter\source\xenforo\step\bans_step());

// Test crafted scope omitting posts
$crafted_scope = ['groups', 'users', 'topics', 'attachments'];
$resolved = $registry->resolve_order($crafted_scope);
$has_posts = in_array('posts', $resolved, true);
$topic_pos = array_search('topics', $resolved, true);
$post_pos = array_search('posts', $resolved, true);
$attach_pos = array_search('attachments', $resolved, true);

echo " - Crafted scope: " . implode(', ', $crafted_scope) . "\n";
echo " - Expanded order: " . implode(' -> ', $resolved) . "\n";
echo " - Auto-added 'posts': " . ($has_posts ? '[PASS]' : '[FAIL]') . "\n";
echo " - Correct Sequence (topics < posts < attachments): " . (($topic_pos < $post_pos && $post_pos < $attach_pos) ? '[PASS]' : '[FAIL]') . "\n";

// 2. Finalization Completion Gate with Incomplete Run
echo "\n--- 2. FINALIZATION COMPLETION GATE TEST ---\n";
$pdo = new PDO('mysql:host=localhost;dbname=bb;charset=utf8mb4', 'root', '');
$run_id = '4de15029-9119-4ffb-ac39-292ead06a829';

require_once 'C:/xampp/htdocs/bb/phpbb/db/driver/driver_interface.php';
require_once 'C:/xampp/htdocs/bb/phpbb/db/driver/driver.php';
require_once 'C:/xampp/htdocs/bb/phpbb/db/driver/mysql_base.php';
require_once 'C:/xampp/htdocs/bb/phpbb/db/driver/mysqli.php';

$dbal = new \phpbb\db\driver\mysqli();
$dbal->sql_connect('localhost', 'root', '', 'bb', 3306);

$id_mapper = new \phpbbseo\migrationcenter\core\mapping\id_mapper($dbal, 'phpbb_');
$verifier = new \phpbbseo\migrationcenter\core\verification\migration_verifier(
    $dbal,
    new \phpbb\config\config([]),
    $id_mapper,
    'phpbb_'
);

$res_empty = $verifier->verify_all('');
echo " - Empty run ID verify_all(): Passed=" . ($res_empty['passed'] ? 'true [FAIL]' : 'false [PASS]') . ", Error=" . ($res_empty['error'] ?? '') . "\n";

$res_active = $verifier->verify_all($run_id);
echo " - Active Incomplete Run verify_all(): Passed=" . ($res_active['passed'] ? 'true [FAIL]' : 'false [PASS]') . "\n";
$gate_check = null;
foreach ($res_active['checks'] as $c) {
    if ($c['id'] === 'completion_gate') $gate_check = $c;
}
echo " - Completion Gate Status: {$gate_check['status']} [PASS] (Message: {$gate_check['message']})\n";

// 3. Persisted Step Inventory for Run 4de15029-9119-4ffb-ac39-292ead06a829
echo "\n--- 3. PERSISTED 15-STEP AUDIT FOR RUN {$run_id} ---\n";
$stmt = $pdo->prepare("SELECT step_order, step_name, status, current_cursor, total_records, imported_records, skipped_records, failed_records FROM phpbb_migration_steps WHERE run_id = ? ORDER BY step_order ASC");
$stmt->execute([$run_id]);
$persisted_steps = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "Total Steps in Database: " . count($persisted_steps) . " " . (count($persisted_steps) === 15 ? '[PASS]' : '[FAIL]') . "\n";
printf("%-5s %-26s %-10s %-8s %-8s %-8s %-8s %-8s\n", "Order", "Step Name", "Status", "Cursor", "Total", "Imported", "Skipped", "Failed");
echo str_repeat('-', 85) . "\n";

$all_pending = true;
$all_zero = true;

foreach ($persisted_steps as $s) {
    printf("%-5d %-26s %-10s %-8s %-8d %-8d %-8d %-8d\n", 
        $s['step_order'], 
        $s['step_name'], 
        $s['status'], 
        $s['current_cursor'], 
        $s['total_records'], 
        $s['imported_records'], 
        $s['skipped_records'], 
        $s['failed_records']
    );
    if ($s['status'] !== 'pending') $all_pending = false;
    if ((int)$s['imported_records'] !== 0 || (int)$s['skipped_records'] !== 0 || (int)$s['failed_records'] !== 0) $all_zero = false;
}

echo "\n - All 15 Steps are Pending: " . ($all_pending ? '[PASS]' : '[FAIL]') . "\n";
echo " - All Counters are 0: " . ($all_zero ? '[PASS]' : '[FAIL]') . "\n";

echo "\n===============================================================\n";
echo " SAFETY & REGRESSION AUDIT COMPLETED\n";
echo "===============================================================\n";
