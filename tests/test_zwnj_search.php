<?php
global $phpbb_container;
require_once __DIR__ . '/bootstrap.php';

list($db, $table_prefix) = get_test_db();
$indexer = $phpbb_container->get('phpbbseo.migrationcenter.search_indexer');
$backend = $indexer->get_backend_instance();

$zwnj_word = "UnicodeRunner\xE2\x80\x8CXXX"; // U+0645 U+06CC U+200C U+0631 U+0648 U+0645
$no_zwnj_word = "UnicodeRunnerFlat";        // U+0645 U+06CC U+0631 U+0648 U+0645
$spaced_word = "UnicodeRunnerSpaced";        // U+0645 U+06CC U+0020 U+0631 U+0648 U+0645

$sentence = "UnicodeSearchTest " . $zwnj_word . " in system";

echo "Sentence: " . $sentence . "\n";
echo "ZWNJ word hex: " . bin2hex($zwnj_word) . "\n";

// Test split_message on backend
$r = new ReflectionMethod($backend, 'split_message');
$r->setAccessible(true);
$words = $r->invoke($backend, $sentence);
echo "Indexed tokens from split_message:\n";
foreach ($words as $w) {
    echo " - Token: '{$w}', hex: " . bin2hex($w) . "\n";
}

// Test split_message on queries
$q1_tokens = $r->invoke($backend, $zwnj_word);
echo "Tokens for query '{$zwnj_word}' (with ZWNJ): " . json_encode($q1_tokens) . "\n";

$q2_tokens = $r->invoke($backend, $no_zwnj_word);
echo "Tokens for query '{$no_zwnj_word}' (without ZWNJ): " . json_encode($q2_tokens) . "\n";

$q3_tokens = $r->invoke($backend, $spaced_word);
echo "Tokens for query '{$spaced_word}' (with space): " . json_encode($q3_tokens) . "\n";
