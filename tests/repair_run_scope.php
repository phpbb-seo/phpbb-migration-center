<?php
$db_host = 'localhost';
$db_user = 'root';
$db_pass = '';
$db_name = 'bb';

$pdo = new PDO("mysql:host={$db_host};dbname={$db_name};charset=utf8mb4", $db_user, $db_pass);
$run_id = '4de15029-9119-4ffb-ac39-292ead06a829';

echo "=== Repairing Missing Posts Step for Run {$run_id} ===\n";

// 1. Check current run
$stmt = $pdo->prepare("SELECT * FROM phpbb_migration_runs WHERE run_id = ?");
$stmt->execute([$run_id]);
$run = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$run) {
    die("Run {$run_id} not found!\n");
}

$options = json_decode($run['options_json'], true) ?: [];
if (!in_array('posts', $options['selected_steps'] ?? [], true)) {
    $options['selected_steps'][] = 'posts';
    $updated_options_json = json_encode($options, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $u_stmt = $pdo->prepare("UPDATE phpbb_migration_runs SET options_json = ? WHERE run_id = ?");
    $u_stmt->execute([$updated_options_json, $run_id]);
    echo " - Added 'posts' to run options_json.\n";
}

// 2. Re-order and insert steps
$ordered_step_names = [
    'groups',
    'users',
    'group_memberships',
    'global_permissions',
    'forums',
    'node_permissions',
    'topics',
    'posts',
    'attachments',
    'avatars',
    'conversations',
    'conversation_messages',
    'conversation_attachments',
    'polls',
    'bans',
];

// Check existing steps
$existing_steps = [];
$stmt = $pdo->prepare("SELECT * FROM phpbb_migration_steps WHERE run_id = ?");
$stmt->execute([$run_id]);
while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $existing_steps[$r['step_name']] = $r;
}

// Clear and re-insert in canonical order
$pdo->prepare("DELETE FROM phpbb_migration_steps WHERE run_id = ?")->execute([$run_id]);

$insert_stmt = $pdo->prepare("INSERT INTO phpbb_migration_steps 
    (run_id, step_name, status, current_cursor, max_source_id, total_records, imported_records, skipped_records, failed_records, step_order, started_at, completed_at, stats_json)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

// Get total posts from source xen database
$src_pdo = new PDO("mysql:host={$db_host};dbname=xen;charset=utf8mb4", $db_user, $db_pass);
$total_posts = (int)$src_pdo->query("SELECT COUNT(*) FROM xf_post")->fetchColumn();
$max_post_id = (string)$src_pdo->query("SELECT MAX(post_id) FROM xf_post")->fetchColumn();

foreach ($ordered_step_names as $idx => $step_name) {
    $order = $idx + 1;
    $existing = $existing_steps[$step_name] ?? null;
    
    $status = 'pending';
    $cursor = '0';
    $max_id = $existing['max_source_id'] ?? '0';
    $total = $existing['total_records'] ?? 0;
    
    if ($step_name === 'posts') {
        $max_id = $max_post_id;
        $total = $total_posts;
    }
    
    $insert_stmt->execute([
        $run_id,
        $step_name,
        $status,
        $cursor,
        $max_id,
        $total,
        0, // imported
        0, // skipped
        0, // failed
        $order,
        0, // started_at
        0, // completed_at
        json_encode([], JSON_UNESCAPED_UNICODE),
    ]);
}

// 3. Record audit log
$pdo->prepare("INSERT INTO phpbb_migration_errors (run_id, step_name, content_type, source_id, severity, error_code, message, context_json, created_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)")->execute([
        $run_id,
        'core',
        'step',
        '0',
        'info',
        'SCOPE_REPAIR',
        'Added missing posts step between topics and attachments during Phase 7B dependency reconciliation.',
        json_encode([], JSON_UNESCAPED_UNICODE),
        time(),
    ]);

echo " - Inserted all 15 steps with correct ordering and recorded audit log.\n";
