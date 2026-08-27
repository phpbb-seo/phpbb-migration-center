<?php
$p = new PDO('mysql:host=localhost;dbname=bb', 'root', '');
$p->exec('DELETE FROM phpbb_migration_locks');
echo "Locks cleared\n";
