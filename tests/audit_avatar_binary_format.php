<?php
$finfo = finfo_open(FILEINFO_MIME_TYPE);

$src_avatar_100000 = 'C:/xampp/htdocs/xen/data/avatars/o/100/100000.jpg';
$tgt_avatar_100000 = 'C:/xampp/htdocs/bb_e2e/images/avatars/upload/89b4ab60e821736bbd92a2bffc805d81_100000.jpg';

echo "=== 1. USER 100000 (Migrated_Test_User) AVATAR BINARY AUDIT ===\n";
if (file_exists($src_avatar_100000)) {
    $src_mime = finfo_file($finfo, $src_avatar_100000);
    $src_info = getimagesize($src_avatar_100000);
    $src_sha = hash_file('sha256', $src_avatar_100000);
    echo "Source Avatar:\n";
    echo " - Path: {$src_avatar_100000}\n";
    echo " - MIME (finfo): {$src_mime}\n";
    echo " - Dimensions (getimagesize): {$src_info[0]}x{$src_info[1]}, type={$src_info[2]} ({$src_info['mime']})\n";
    echo " - SHA-256: {$src_sha}\n";
}

if (file_exists($tgt_avatar_100000)) {
    $tgt_mime = finfo_file($finfo, $tgt_avatar_100000);
    $tgt_info = getimagesize($tgt_avatar_100000);
    $tgt_sha = hash_file('sha256', $tgt_avatar_100000);
    echo "Target Avatar:\n";
    echo " - Path: {$tgt_avatar_100000}\n";
    echo " - MIME (finfo): {$tgt_mime}\n";
    echo " - Dimensions (getimagesize): {$tgt_info[0]}x{$tgt_info[1]}, type={$tgt_info[2]} ({$tgt_info['mime']})\n";
    echo " - SHA-256: {$tgt_sha}\n";
}

$src_avatar_1 = 'C:/xampp/htdocs/xen/data/avatars/o/0/1.jpg';
$tgt_avatar_1 = 'C:/xampp/htdocs/bb_e2e/images/avatars/upload/89b4ab60e821736bbd92a2bffc805d81_100169.jpg';

echo "\n=== 2. USER 1 (admin) AVATAR BINARY AUDIT ===\n";
if (file_exists($src_avatar_1)) {
    $src_mime = finfo_file($finfo, $src_avatar_1);
    $src_info = getimagesize($src_avatar_1);
    $src_sha = hash_file('sha256', $src_avatar_1);
    echo "Source Avatar:\n";
    echo " - Path: {$src_avatar_1}\n";
    echo " - MIME (finfo): {$src_mime}\n";
    echo " - Dimensions (getimagesize): {$src_info[0]}x{$src_info[1]}, type={$src_info[2]} ({$src_info['mime']})\n";
    echo " - SHA-256: {$src_sha}\n";
}

if (file_exists($tgt_avatar_1)) {
    $tgt_mime = finfo_file($finfo, $tgt_avatar_1);
    $tgt_info = getimagesize($tgt_avatar_1);
    $tgt_sha = hash_file('sha256', $tgt_avatar_1);
    echo "Target Avatar:\n";
    echo " - Path: {$tgt_avatar_1}\n";
    echo " - MIME (finfo): {$tgt_mime}\n";
    echo " - Dimensions (getimagesize): {$tgt_info[0]}x{$tgt_info[1]}, type={$tgt_info[2]} ({$tgt_info['mime']})\n";
    echo " - SHA-256: {$tgt_sha}\n";
}
finfo_close($finfo);
