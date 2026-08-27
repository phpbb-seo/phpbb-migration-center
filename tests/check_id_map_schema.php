<?php
$p = new PDO('mysql:host=localhost;dbname=bb_migration_e2e', 'root', '');
$row = $p->query('SHOW CREATE TABLE phpbb_migration_id_map')->fetch(PDO::FETCH_ASSOC);
echo $row['Create Table'] . "\n";
