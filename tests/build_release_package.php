<?php
$source_dir = 'C:/xampp/htdocs/bb/ext/phpbbseo/migrationcenter';
$zip_file = 'C:/xampp/htdocs/bb/phpbbseo_migrationcenter_v1.0.0.zip';

@unlink($zip_file);
$zip = new ZipArchive();
if ($zip->open($zip_file, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    die("Cannot create zip file\n");
}

$exclude_folders = ['tests', 'scratch', '.git'];
$exclude_files = ['.DS_Store', 'Thumbs.db', '.gitignore'];

$files = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($source_dir, RecursiveDirectoryIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);

$added_count = 0;
foreach ($files as $file) {
    $relative_path = substr($file->getPathname(), strlen($source_dir) + 1);
    $relative_path = str_replace('\\', '/', $relative_path);
    
    // Check excluded directories
    $parts = explode('/', $relative_path);
    if (in_array($parts[0], $exclude_folders)) {
        continue;
    }
    
    if (in_array(basename($relative_path), $exclude_files)) {
        continue;
    }

    $zip_entry_name = 'phpbbseo/migrationcenter/' . $relative_path;

    if ($file->isDir()) {
        $zip->addEmptyDir($zip_entry_name);
    } else if ($file->isFile()) {
        $zip->addFile($file->getPathname(), $zip_entry_name);
        $added_count++;
    }
}

$zip->close();
echo "Release package created successfully: {$zip_file}\n";
echo "Total files packed: {$added_count}\n";
echo "File size: " . round(filesize($zip_file) / 1024, 2) . " KB\n";
