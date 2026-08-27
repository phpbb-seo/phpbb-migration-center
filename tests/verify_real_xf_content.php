<?php
$p = new PDO('mysql:host=localhost;dbname=xen', 'root', '');
echo "=== Real XenForo Attachments On Disk ===\n";
foreach ($p->query('SELECT data_id, filename, file_key, file_size, file_hash FROM xf_attachment_data WHERE data_id IN (9, 10)') as $r) {
    $group = floor($r['data_id'] / 1000);
    $path1 = "C:/xampp/htdocs/xen/internal_data/attachments/{$group}/{$r['data_id']}-{$r['file_key']}.data";
    $exists = file_exists($path1);
    $sha256 = $exists ? hash_file('sha256', $path1) : 'NONE';
    echo "File: {$r['filename']}\n";
    echo " - Path: {$path1}\n";
    echo " - Exists: " . ($exists ? 'YES' : 'NO') . "\n";
    echo " - Size: {$r['file_size']} bytes (on disk: " . ($exists ? filesize($path1) : 0) . ")\n";
    echo " - SHA-256: {$sha256}\n\n";
}

echo "=== Real XenForo Avatar On Disk ===\n";
$user_id = 100000;
$group = floor($user_id / 1000);
foreach (['o', 'l', 'm', 's'] as $size) {
    $avatarPath = "C:/xampp/htdocs/xen/data/avatars/{$size}/{$group}/{$user_id}.jpg";
    $exists = file_exists($avatarPath);
    echo "Avatar {$size}: {$avatarPath} (exists: " . ($exists ? 'YES' : 'NO') . ", size: " . ($exists ? filesize($avatarPath) : 0) . ")\n";
}
