<?php
$p = new PDO('mysql:host=localhost;dbname=bb_migration_e2e;charset=utf8mb4', 'root', '');
$res = $p->query("SELECT e.extension, g.group_name, g.allow_group FROM phpbb_extensions e INNER JOIN phpbb_extension_groups g ON (e.group_id = g.group_id) WHERE e.extension IN ('pdf', 'png', 'jpg', 'zip')")->fetchAll(PDO::FETCH_ASSOC);
print_r($res);
$p->exec("UPDATE phpbb_extension_groups SET allow_group = 1");
echo "Enabled all attachment extension groups.\n";
