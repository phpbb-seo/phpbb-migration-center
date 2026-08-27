<?php
$pdo = new PDO('mysql:host=localhost;dbname=bb_migration_e2e', 'root', '');
$pdo->exec("DELETE FROM phpbb_migration_locks");
echo "Cleared locks in bb_migration_e2e.\n";
