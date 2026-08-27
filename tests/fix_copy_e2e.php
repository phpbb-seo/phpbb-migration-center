<?php
$source_bb = 'C:/xampp/htdocs/bb';
$target_bb = 'C:/xampp/htdocs/bb_e2e';

function copy_all($src, $dst, $is_root = true) {
    if (!is_dir($dst)) {
        @mkdir($dst, 0777, true);
    }
    $dir = opendir($src);
    while (false !== ($file = readdir($dir))) {
        if ($file === '.' || $file === '..') {
            continue;
        }
        $s = $src . '/' . $file;
        $d = $dst . '/' . $file;
        if (is_dir($s)) {
            if ($is_root && $file === 'cache') {
                @mkdir($d, 0777, true);
                continue;
            }
            copy_all($s, $d, false);
        } else {
            copy($s, $d);
        }
    }
    closedir($dir);
}

echo "=== Copying all files from bb to bb_e2e ===\n";
copy_all($source_bb, $target_bb, true);
echo "Copy completed.\n";
