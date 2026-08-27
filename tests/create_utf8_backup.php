<?php
$raw = file_get_contents('C:/xampp/htdocs/bb_e2e/bb_migration_e2e_clean_backup.sql');
$utf8 = mb_convert_encoding($raw, 'UTF-8', 'UTF-16LE');
// Remove any UTF-8 BOM
$utf8 = ltrim($utf8, "\xEF\xBB\xBF");
file_put_contents('C:/xampp/htdocs/bb_e2e/clean_utf8_backup.sql', $utf8);
echo "Clean UTF-8 backup saved. Size: " . strlen($utf8) . " bytes\n";
