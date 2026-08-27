<?php
$p = new PDO('mysql:host=localhost;dbname=bb_migration_e2e', 'root', '');
$res = $p->query("SHOW FULL COLUMNS FROM phpbb_topics WHERE Field = 'topic_title'")->fetch(PDO::FETCH_ASSOC);
echo "Collation: {$res['Collation']}\n";

// Let's convert all tables in bb_migration_e2e to utf8mb4
$tables = $p->query("SHOW TABLES LIKE 'phpbb_%'")->fetchAll(PDO::FETCH_COLUMN);
foreach ($tables as $table) {
    $p->exec("ALTER TABLE `{$table}` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_bin");
}
echo "Converted all phpbb_ tables in bb_migration_e2e to utf8mb4 COLLATE utf8mb4_bin.\n";
