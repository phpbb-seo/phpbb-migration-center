<?php
global $phpbb_container;
require_once __DIR__ . '/bootstrap.php';

list($db, $table_prefix) = get_test_db();
$indexer = $phpbb_container->get('phpbbseo.migrationcenter.search_indexer');
$backend = $indexer->get_backend_instance();

$r = new ReflectionMethod($backend, 'split_message');
$r->setAccessible(true);

$fa_word = "LibraryCatalog"; // Persian Kaf U+06A9 + Persian Yeh U+06CC
$ar_word = "LibraryCatalogArabic"; // Arabic Kaf U+0643 + Arabic Yeh U+064A

echo "Persian word: {$fa_word} (hex: " . bin2hex($fa_word) . ")\n";
echo "Arabic word:  {$ar_word} (hex: " . bin2hex($ar_word) . ")\n";

$fa_tokens = $r->invoke($backend, $fa_word);
$ar_tokens = $r->invoke($backend, $ar_word);

echo "Persian indexed tokens: " . json_encode($fa_tokens) . "\n";
echo "Arabic indexed tokens:  " . json_encode($ar_tokens) . "\n";

echo "Are tokens equal? " . ($fa_tokens === $ar_tokens ? "YES" : "NO (distinct tokens)") . "\n";
