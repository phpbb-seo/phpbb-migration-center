<?php
$p = new PDO('mysql:host=localhost;dbname=bb_migration_e2e', 'root', '');
foreach ($p->query("SELECT config_name, config_value FROM phpbb_config WHERE config_name LIKE '%avatar%'") as $r) {
    echo "{$r['config_name']} = {$r['config_value']}\n";
}

// Enable avatar upload in phpBB settings
$p->exec("UPDATE phpbb_config SET config_value = '1' WHERE config_name = 'allow_avatar'");
$p->exec("UPDATE phpbb_config SET config_value = '1' WHERE config_name = 'allow_avatar_upload'");
$p->exec("UPDATE phpbb_config SET config_value = '524288' WHERE config_name = 'avatar_filesize'"); // 512KB
$p->exec("UPDATE phpbb_config SET config_value = '300' WHERE config_name = 'avatar_max_width'");
$p->exec("UPDATE phpbb_config SET config_value = '300' WHERE config_name = 'avatar_max_height'");
echo "Updated avatar config in bb_migration_e2e to allow avatar uploads.\n";
