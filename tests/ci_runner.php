<?php
/**
 * phpBB Migration Center - Standalone CI Test Runner
 *
 * Runs environment-independent unit tests in CI environments without requiring
 * a full live phpBB database installation.
 */

// 1. Register PSR-4 Autoloader for Extension
spl_autoload_register(function ($class) {
    $prefix = 'phpbbseo\\migrationcenter\\';
    $base_dir = dirname(__DIR__) . '/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relative_class = substr($class, $len);
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';

    if (file_exists($file)) {
        require_once $file;
    }
});

// Mock minimal phpBB constants if needed
if (!defined('IN_PHPBB')) {
    define('IN_PHPBB', true);
}

echo "===========================================\n";
echo " phpBB Migration Center - CI Test Runner\n";
echo "===========================================\n\n";

$standalone_tests = [
    'UnicodeTest'                => \phpbbseo\migrationcenter\tests\unit\UnicodeTest::class,
    'XfPasswordHandlerTest'      => \phpbbseo\migrationcenter\tests\unit\XfPasswordHandlerTest::class,
    'XfAttachmentPathResolverTest'=> \phpbbseo\migrationcenter\tests\unit\XfAttachmentPathResolverTest::class,
    'XfAvatarPathResolverTest'   => \phpbbseo\migrationcenter\tests\unit\XfAvatarPathResolverTest::class,
    'XfConfigDetectorTest'       => \phpbbseo\migrationcenter\tests\unit\XfConfigDetectorTest::class,
    'XfConversationNormalizerTest'=> \phpbbseo\migrationcenter\tests\unit\XfConversationNormalizerTest::class,
    'XfForumTreeBuilderTest'     => \phpbbseo\migrationcenter\tests\unit\XfForumTreeBuilderTest::class,
    'XfNodePermissionTest'       => \phpbbseo\migrationcenter\tests\unit\XfNodePermissionTest::class,
    'XfPermissionTranslatorTest' => \phpbbseo\migrationcenter\tests\unit\XfPermissionTranslatorTest::class,
    'XfTopicNormalizerTest'      => \phpbbseo\migrationcenter\tests\unit\XfTopicNormalizerTest::class,
    'XfUserNormalizationTest'   => \phpbbseo\migrationcenter\tests\unit\XfUserNormalizationTest::class,
];

$passed = 0;
$failed = 0;

foreach ($standalone_tests as $name => $class) {
    echo "[RUN] {$name}... ";
    try {
        $file = dirname(__DIR__) . '/tests/unit/' . $name . '.php';
        if (file_exists($file)) {
            require_once $file;
        }
        
        $test = new $class();
        $test->run();
        echo "PASSED\n";
        $passed++;
    } catch (\Throwable $e) {
        echo "FAILED: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine() . "\n";
        $failed++;
    }
}

echo "\n-------------------------------------------\n";
echo "Summary: Total: " . count($standalone_tests) . ", Passed: {$passed}, Failed: {$failed}\n";
echo "===========================================\n";

if ($failed > 0) {
    exit(1);
}
exit(0);