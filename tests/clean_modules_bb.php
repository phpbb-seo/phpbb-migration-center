<?php
$pdo = new PDO('mysql:host=localhost;dbname=bb;charset=utf8mb4', 'root', '');
$pdo->exec("DELETE FROM phpbb_modules WHERE module_basename LIKE '%migrationcenter%' OR module_langname = 'ACP_MIGRATION_CENTER'");
$pdo->exec("DELETE FROM phpbb_migrations WHERE migration_name LIKE '%migrationcenter%'");
echo "Cleaned pre-existing modules and migration records from bb.\n";
