<?php
$dir = 'C:/xampp/htdocs/bb/ext/phpbbseo/migrationcenter';
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
foreach ($iterator as $file) {
    if ($file->isFile() && $file->getExtension() === 'php') {
        if (strpos($file->getPathname(), 'tests') !== false) continue;
        $content = file_get_contents($file->getPathname());
        if (strpos($content, 'C:/xampp') !== false || strpos($content, 'C:\\xampp') !== false) {
            echo "Found hardcoded path in: " . $file->getPathname() . "\n";
        }
    }
}
