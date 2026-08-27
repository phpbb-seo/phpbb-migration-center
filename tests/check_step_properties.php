<?php
$files = glob('C:/xampp/htdocs/bb/ext/phpbbseo/migrationcenter/source/xenforo/step/*_step.php');
foreach ($files as $f) {
    $content = file_get_contents($f);
    if (strpos($content, 'items_') !== false) {
        echo "Found items_ in: " . basename($f) . "\n";
    }
    if (strpos($content, 'get_option') !== false) {
        echo "Found get_option in: " . basename($f) . "\n";
    }
}
