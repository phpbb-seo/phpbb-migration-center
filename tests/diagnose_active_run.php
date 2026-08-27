<?php
$db_host = 'localhost';
$db_user = 'root';
$db_pass = '';
$db_name = 'bb';

$pdo = new PDO("mysql:host={$db_host};dbname={$db_name};charset=utf8mb4", $db_user, $db_pass);

$run_id = '4de15029-9119-4ffb-ac39-292ead06a829';

echo "===============================================================\n";
echo " READ-ONLY DIAGNOSIS FOR RUN: {$run_id}\n";
echo "===============================================================\n\n";

// 1. RUN RECORD
echo "--- 1. RUN RECORD (phpbb_migration_runs) ---\n";
$stmt = $pdo->prepare("SELECT * FROM phpbb_migration_runs WHERE run_id = ?");
$stmt->execute([$run_id]);
$run = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$run) {
    echo " [ERROR] No run record found with ID {$run_id} in database `bb`!\n";
    // Check all runs
    echo "\nAll existing runs in database:\n";
    foreach ($pdo->query("SELECT * FROM phpbb_migration_runs") as $r) {
        print_r($r);
    }
} else {
    print_r($run);
}

// 2. STEPS
echo "\n--- 2. STEPS (phpbb_migration_steps) ---\n";
$stmt = $pdo->prepare("SELECT * FROM phpbb_migration_steps WHERE run_id = ?");
$stmt->execute([$run_id]);
$steps = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($steps)) {
    echo " [NOTE] No step records found for this run.\n";
} else {
    foreach ($steps as $s) {
        print_r($s);
    }
}

// 3. LOCKS
echo "\n--- 3. LOCKS (phpbb_migration_locks) ---\n";
$locks = $pdo->query("SELECT * FROM phpbb_migration_locks")->fetchAll(PDO::FETCH_ASSOC);
if (empty($locks)) {
    echo " [NOTE] No active locks found in phpbb_migration_locks.\n";
} else {
    foreach ($locks as $l) {
        print_r($l);
        if (isset($l['expires_at'])) {
            $is_stale = (time() > $l['expires_at']);
            echo "   Lock State: " . ($is_stale ? 'STALE (expired)' : 'ACTIVE') . " (Expires at: " . date('Y-m-d H:i:s', $l['expires_at']) . ", Current Time: " . date('Y-m-d H:i:s') . ")\n";
        }
        if (isset($l['heartbeat_at'])) {
            $diff = time() - $l['heartbeat_at'];
            echo "   Heartbeat Age: {$diff} seconds ago (" . date('Y-m-d H:i:s', $l['heartbeat_at']) . ")\n";
        }
    }
}

// 4. ID MAPS (Mapped items)
echo "\n--- 4. MAPPED RECORDS (phpbb_migration_id_map) ---\n";
$stmt = $pdo->prepare("SELECT content_type, COUNT(*) as cnt FROM phpbb_migration_id_map WHERE run_id = ? GROUP BY content_type");
$stmt->execute([$run_id]);
$maps = $stmt->fetchAll(PDO::FETCH_ASSOC);
if (empty($maps)) {
    echo " [NOTE] No mapped records for run {$run_id}.\n";
} else {
    foreach ($maps as $m) {
        echo "   {$m['content_type']}: {$m['cnt']} mapped rows\n";
    }
}

// 5. ERRORS (phpbb_migration_errors)
echo "\n--- 5. ERROR LOGS (phpbb_migration_errors) ---\n";
if ($pdo->query("SHOW TABLES LIKE 'phpbb_migration_errors'")->rowCount() > 0) {
    $stmt = $pdo->prepare("SELECT * FROM phpbb_migration_errors WHERE run_id = ? ORDER BY id DESC LIMIT 10");
    $stmt->execute([$run_id]);
    $errors = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($errors)) {
        echo " [NOTE] Zero error logs recorded.\n";
    } else {
        foreach ($errors as $e) {
            print_r($e);
        }
    }
} else {
    echo " [NOTE] Table phpbb_migration_errors does not exist.\n";
}

// 6. CHECK FOR ACTIVE PHP WORKER PROCESSES
echo "\n--- 6. RUNNING WORKER PROCESSES (OS LEVEL) ---\n";
exec('powershell -Command "Get-CimInstance Win32_Process -Filter \"Name = \'php.exe\'\" | Select-Object ProcessId, CommandLine | Format-List"', $proc_out);
echo implode("\n", $proc_out) . "\n";

echo "===============================================================\n";
