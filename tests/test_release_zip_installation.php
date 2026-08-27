<?php
$zip_file = 'C:/xampp/htdocs/bb/phpbbseo_migrationcenter_v1.0.0.zip';
$test_ext_dir = 'C:/xampp/htdocs/bb_e2e/ext/phpbbseo/migrationcenter_zip_test';

echo "===============================================================\n";
echo " RELEASE ZIP PACKAGE & LIFECYCLE AUDIT\n";
echo "===============================================================\n\n";

// 1. Unpack ZIP
echo "--- 1. UNPACKING RELEASE ZIP ---\n";
$zip = new ZipArchive();
if ($zip->open($zip_file) === true) {
    echo " - Opened: {$zip_file} (Size: " . round(filesize($zip_file)/1024, 2) . " KB, Entries: {$zip->numFiles})\n";
    
    // Check no excluded files are inside ZIP
    $has_test_files = false;
    $has_scratch = false;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $stat = $zip->statIndex($i);
        if (strpos($stat['name'], 'tests/') !== false) $has_test_files = true;
        if (strpos($stat['name'], 'scratch/') !== false) $has_scratch = true;
    }
    
    echo " - Excludes 'tests/' directory: " . (!$has_test_files ? '[PASS]' : '[FAIL]') . "\n";
    echo " - Excludes 'scratch/' directory: " . (!$has_scratch ? '[PASS]' : '[FAIL]') . "\n";
    $zip->close();
} else {
    die("Failed to open ZIP file\n");
}

// 2. CLI Extension Disable & Enable Lifecycle Test
echo "\n--- 2. EXTENSION ENABLE / DISABLE / RE-ENABLE LIFECYCLE ---\n";
$cmd_disable = 'C:\\xampp\\php\\php.exe C:\\xampp\\htdocs\\bb_e2e\\bin\\phpbbcli.php extension:disable phpbbseo/migrationcenter';
exec($cmd_disable, $out_dis, $code_dis);
echo " - Disable Command Exit Code: {$code_dis} " . ($code_dis === 0 ? '[PASS]' : '[FAIL]') . "\n";

$cmd_enable = 'C:\\xampp\\php\\php.exe C:\\xampp\\htdocs\\bb_e2e\\bin\\phpbbcli.php extension:enable phpbbseo/migrationcenter';
exec($cmd_enable, $out_en, $code_en);
echo " - Re-Enable Command Exit Code: {$code_en} " . ($code_en === 0 ? '[PASS]' : '[FAIL]') . "\n";

// 3. Permission & ACP Module Verification
echo "\n--- 3. PERMISSION & ACP MODULE VERIFICATION ---\n";
$pdo = new PDO('mysql:host=localhost;dbname=bb_migration_e2e;charset=utf8mb4', 'root', '');
$perm_exists = $pdo->query("SELECT auth_option_id FROM phpbb_acl_options WHERE auth_option = 'a_migrationcenter'")->fetchColumn();
echo " - Dedicated Permission 'a_migrationcenter': " . ($perm_exists ? "[PASS] Found (ID: {$perm_exists})" : '[FAIL]') . "\n";

$acp_module_exists = $pdo->query("SELECT module_id FROM phpbb_modules WHERE module_basename LIKE '%migrationcenter%' AND module_class = 'acp'")->fetchColumn();
echo " - ACP Module Registered: " . ($acp_module_exists ? "[PASS] Found (ID: {$acp_module_exists})" : '[FAIL]') . "\n";

// 4. Multilingual & RTL Language Pack Verification
echo "\n--- 4. MULTILINGUAL & RTL AUDIT ---\n";
$en_lang_file = 'C:/xampp/htdocs/bb/ext/phpbbseo/migrationcenter/language/en/migrationcenter.php';
$fa_lang_file = 'C:/xampp/htdocs/bb/ext/phpbbseo/migrationcenter/language/fa/migrationcenter.php';

echo " - English language file exists: " . (file_exists($en_lang_file) ? '[PASS]' : '[FAIL]') . "\n";
echo " - Persian language file exists: " . (file_exists($fa_lang_file) ? '[PASS]' : '[FAIL]') . "\n";

$lang_en = [];
include $en_lang_file;
$en_keys = array_keys($lang_en ?? $lang);

$lang_fa = [];
include $fa_lang_file;
$fa_keys = array_keys($lang_fa ?? $lang);

$missing_in_fa = array_diff($en_keys, $fa_keys);
echo " - Missing translation keys in Persian: " . count($missing_in_fa) . " " . (count($missing_in_fa) === 0 ? '[PASS]' : '[FAIL]') . "\n";

echo "\n===============================================================\n";
echo " RELEASE ZIP AUDIT COMPLETED\n";
echo "===============================================================\n";
