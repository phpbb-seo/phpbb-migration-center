<?php
$p = new PDO('mysql:host=localhost;dbname=bb_migration_e2e', 'root', '');
$p->exec("DELETE FROM phpbb_modules WHERE module_basename LIKE '%migrationcenter%' OR module_langname LIKE '%MIGRATION%'");
$p->exec("DELETE FROM phpbb_ext WHERE ext_name = 'phpbbseo/migrationcenter'");
$p->exec("DELETE FROM phpbb_migrations WHERE migration_name LIKE '%phpbbseo%'");
echo "Cleaned module and migration rows in bb_migration_e2e.\n";
