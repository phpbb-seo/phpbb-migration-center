<?php
$p = new PDO('mysql:host=localhost;dbname=xen', 'root', '');
$all = $p->query('SELECT post_id FROM xf_post ORDER BY post_id ASC')->fetchAll(PDO::FETCH_COLUMN);
$all_map = array_flip($all);
$missing = [];
for ($i = 1; $i <= 7820; $i++) {
    if (!isset($all_map[$i])) {
        $missing[] = $i;
    }
}
echo "Missing post IDs (" . count($missing) . "): " . implode(', ', $missing) . "\n";
echo "Total rows: " . count($all) . "\n";
echo "Max ID: " . max($all) . "\n";
