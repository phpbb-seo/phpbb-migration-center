<?php
$pdo_src = new PDO('mysql:host=localhost;dbname=bb', 'root', '');
$pdo_dst = new PDO('mysql:host=localhost;dbname=bb_migration_e2e', 'root', '');

$tables = ['phpbb_migration_runs', 'phpbb_migration_steps', 'phpbb_migration_id_map', 'phpbb_migration_errors', 'phpbb_migration_settings', 'phpbb_migration_locks'];

foreach ($tables as $t) {
    $pdo_dst->exec("DROP TABLE IF EXISTS `{$t}`");
    $create_sql = $pdo_src->query("SHOW CREATE TABLE `{$t}`")->fetch(PDO::FETCH_ASSOC)['Create Table'];
    $pdo_dst->exec($create_sql);
    echo "Created table {$t} in bb_migration_e2e.\n";
}
