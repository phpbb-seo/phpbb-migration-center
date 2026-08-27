<?php
$source_ext = 'C:/xampp/htdocs/bb/ext/phpbbseo/migrationcenter';
$target_ext = 'C:/xampp/htdocs/bb_e2e/ext/phpbbseo/migrationcenter';

// Recursive sync function
function sync_dir($src, $dst) {
    if (!is_dir($dst)) {
        mkdir($dst, 0777, true);
    }
    $dir = opendir($src);
    while (false !== ($file = readdir($dir))) {
        if (($file != '.') && ($file != '..')) {
            if (is_dir($src . '/' . $file)) {
                sync_dir($src . '/' . $file, $dst . '/' . $file);
            } else {
                copy($src . '/' . $file, $dst . '/' . $file);
            }
        }
    }
    closedir($dir);
}

echo "=== Syncing Extension to bb_e2e ===\n";
sync_dir($source_ext, $target_ext);
echo "Extension files synced successfully.\n";
