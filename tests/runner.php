<?php
/**
 * Migration Center Test Runner
 */

require_once __DIR__ . '/bootstrap.php';

list($test_db, $test_prefix) = get_test_db();

function clean_test_db($test_db, $test_prefix)
{
	global $phpbb_container;
	$test_db->sql_query("DELETE FROM {$test_prefix}migration_runs");
	$test_db->sql_query("DELETE FROM {$test_prefix}migration_steps");
	$test_db->sql_query("DELETE FROM {$test_prefix}migration_id_map");
	$test_db->sql_query("DELETE FROM {$test_prefix}migration_errors");
	$test_db->sql_query("DELETE FROM {$test_prefix}migration_locks");
	$test_db->sql_query("DELETE FROM {$test_prefix}groups WHERE group_id > 7");
	$test_db->sql_query("DELETE FROM {$test_prefix}users WHERE user_id > 2 AND user_type = 0");
	$test_db->sql_query("UPDATE {$test_prefix}users SET username = 'admin', username_clean = 'admin' WHERE user_id = 2");

	if (!empty($phpbb_container) && $phpbb_container->has('phpbbseo.migrationcenter.id_mapper'))
	{
		$phpbb_container->get('phpbbseo.migrationcenter.id_mapper')->clear_cache();
	}
}

clean_test_db($test_db, $test_prefix);

$total = 0;
$passed = 0;
$failed = 0;

echo "===========================================\n";
echo " phpBB Migration Center - Test Runner\n";
echo "===========================================\n\n";

clean_test_db($test_db, $test_prefix);
// Test 1: Unicode Integrity Test
clean_test_db($test_db, $test_prefix);
$total++;
echo "[RUN] UnicodeTest... ";
try
{
	$test = new \phpbbseo\migrationcenter\tests\unit\UnicodeTest();
	$test->run();
	$passed++;
	echo "PASSED\n";
}
catch (\Exception $e)
{
	$failed++;
	echo "FAILED: " . $e->getMessage() . "\n";
}

clean_test_db($test_db, $test_prefix);
// Test 2: Extension Architecture & Classes Integrity Test
$total++;
echo "[RUN] ExtensionSkeletonTest... ";
try
{
	$classes = [
		\phpbbseo\migrationcenter\ext::class,
		\phpbbseo\migrationcenter\migrations\install_schema::class,
		\phpbbseo\migrationcenter\migrations\install_acp_module::class,
		\phpbbseo\migrationcenter\acp\main_info::class,
		\phpbbseo\migrationcenter\acp\main_module::class,
		\phpbbseo\migrationcenter\core\contract\source_provider_interface::class,
		\phpbbseo\migrationcenter\core\contract\id_mapper_interface::class,
		\phpbbseo\migrationcenter\core\contract\step_interface::class,
		\phpbbseo\migrationcenter\core\dto\migration_config_dto::class,
		\phpbbseo\migrationcenter\core\dto\run_state_dto::class,
		\phpbbseo\migrationcenter\core\dto\step_result_dto::class,
		\phpbbseo\migrationcenter\core\dto\user_dto::class,
		\phpbbseo\migrationcenter\core\mapping\id_mapper::class,
		\phpbbseo\migrationcenter\core\state\lock_manager::class,
		\phpbbseo\migrationcenter\core\state\state_manager::class,
		\phpbbseo\migrationcenter\core\engine\step_registry::class,
		\phpbbseo\migrationcenter\core\engine\provider_registry::class,
		\phpbbseo\migrationcenter\core\engine\migration_engine::class,
		\phpbbseo\migrationcenter\core\writer\phpbb_target_writer::class,
		\phpbbseo\migrationcenter\console\command\check_command::class,
		\phpbbseo\migrationcenter\console\command\run_command::class,
		\phpbbseo\migrationcenter\console\command\resume_command::class,
		\phpbbseo\migrationcenter\console\command\pause_command::class,
		\phpbbseo\migrationcenter\console\command\status_command::class,
		\phpbbseo\migrationcenter\console\command\retry_command::class,
		\phpbbseo\migrationcenter\console\command\verify_command::class,
	];

	foreach ($classes as $cls)
	{
		if (!class_exists($cls) && !interface_exists($cls))
		{
			throw new \Exception("Class or interface not found: {$cls}");
		}
	}

	$passed++;
	echo "PASSED\n";
}
catch (\Exception $e)
{
	$failed++;
	echo "FAILED: " . $e->getMessage() . "\n";
}

clean_test_db($test_db, $test_prefix);
// Test 3: Step Dependency Resolution Test
$total++;
echo "[RUN] StepDependencyResolutionTest... ";
try
{
	$registry = new \phpbbseo\migrationcenter\core\engine\step_registry();

	// Mock steps
	$stepA = new class implements \phpbbseo\migrationcenter\core\contract\step_interface {
		public function get_name(): string { return 'groups'; }
		public function get_label(): string { return 'Groups'; }
		public function get_dependencies(): array { return []; }
		public function process_batch(string $run_id, $cursor, int $batch_size, \phpbbseo\migrationcenter\core\dto\migration_config_dto $config, \phpbbseo\migrationcenter\core\contract\source_provider_interface $provider, \phpbbseo\migrationcenter\core\contract\target_writer_interface $writer): \phpbbseo\migrationcenter\core\dto\step_result_dto { return new \phpbbseo\migrationcenter\core\dto\step_result_dto(); }
	};
	$stepB = new class implements \phpbbseo\migrationcenter\core\contract\step_interface {
		public function get_name(): string { return 'users'; }
		public function get_label(): string { return 'Users'; }
		public function get_dependencies(): array { return ['groups']; }
		public function process_batch(string $run_id, $cursor, int $batch_size, \phpbbseo\migrationcenter\core\dto\migration_config_dto $config, \phpbbseo\migrationcenter\core\contract\source_provider_interface $provider, \phpbbseo\migrationcenter\core\contract\target_writer_interface $writer): \phpbbseo\migrationcenter\core\dto\step_result_dto { return new \phpbbseo\migrationcenter\core\dto\step_result_dto(); }
	};
	$stepC = new class implements \phpbbseo\migrationcenter\core\contract\step_interface {
		public function get_name(): string { return 'topics'; }
		public function get_label(): string { return 'Topics'; }
		public function get_dependencies(): array { return ['users']; }
		public function process_batch(string $run_id, $cursor, int $batch_size, \phpbbseo\migrationcenter\core\dto\migration_config_dto $config, \phpbbseo\migrationcenter\core\contract\source_provider_interface $provider, \phpbbseo\migrationcenter\core\contract\target_writer_interface $writer): \phpbbseo\migrationcenter\core\dto\step_result_dto { return new \phpbbseo\migrationcenter\core\dto\step_result_dto(); }
	};

	$registry->register($stepA);
	$registry->register($stepB);
	$registry->register($stepC);

	$order = $registry->resolve_order(['topics', 'users', 'groups']);
	if ($order !== ['groups', 'users', 'topics'])
	{
		throw new \Exception("Step ordering failed. Got: " . implode(', ', $order));
	}

	$passed++;
	echo "PASSED\n";
}
catch (\Exception $e)
{
	$failed++;
	echo "FAILED: " . $e->getMessage() . "\n";
}

clean_test_db($test_db, $test_prefix);
// Test 4: ID Mapper Test
$total++;
echo "[RUN] IdMapperTest... ";
try
{
	$test = new \phpbbseo\migrationcenter\tests\unit\IdMapperTest();
	$test->run();
	$passed++;
	echo "PASSED\n";
}
catch (\Exception $e)
{
	$failed++;
	echo "FAILED: " . $e->getMessage() . "\n";
}

clean_test_db($test_db, $test_prefix);
// Test 5: Lock Manager Test
$total++;
echo "[RUN] LockManagerTest... ";
try
{
	$test = new \phpbbseo\migrationcenter\tests\unit\LockManagerTest();
	$test->run();
	$passed++;
	echo "PASSED\n";
}
catch (\Exception $e)
{
	$failed++;
	echo "FAILED: " . $e->getMessage() . "\n";
}

clean_test_db($test_db, $test_prefix);
// Test 6: XenForo Config Detector Test
$total++;
echo "[RUN] XfConfigDetectorTest... ";
try
{
	$test = new \phpbbseo\migrationcenter\tests\unit\XfConfigDetectorTest();
	$test->run();
	$passed++;
	echo "PASSED\n";
}
catch (\Exception $e)
{
	$failed++;
	echo "FAILED: " . $e->getMessage() . "\n";
}

clean_test_db($test_db, $test_prefix);
// Test 7: XenForo Preflight & Batch Integration Test
$total++;
echo "[RUN] XfPreflightTest... ";
try
{
	$test = new \phpbbseo\migrationcenter\tests\unit\XfPreflightTest();
	$test->run();
	$passed++;
	echo "PASSED\n";
}
catch (\Exception $e)
{
	$failed++;
	echo "FAILED: " . $e->getMessage() . "\n";
}

clean_test_db($test_db, $test_prefix);
// Test 8: XenForo User Normalization & Unicode Test
$total++;
echo "[RUN] XfUserNormalizationTest... ";
try
{
	$test = new \phpbbseo\migrationcenter\tests\unit\XfUserNormalizationTest();
	$test->run();
	$passed++;
	echo "PASSED\n";
}
catch (\Exception $e)
{
	$failed++;
	echo "FAILED: " . $e->getMessage() . "\n";
}

clean_test_db($test_db, $test_prefix);
// Test 9: XenForo Password Handler & Hash Test
$total++;
echo "[RUN] XfPasswordHandlerTest... ";
try
{
	$test = new \phpbbseo\migrationcenter\tests\unit\XfPasswordHandlerTest();
	$test->run();
	$passed++;
	echo "PASSED\n";
}
catch (\Exception $e)
{
	$failed++;
	echo "FAILED: " . $e->getMessage() . "\n";
}

clean_test_db($test_db, $test_prefix);
// Test 10: User Migration Vertical Slice Integration & Login Test
$total++;
echo "[RUN] UserMigrationIntegrationTest... ";
try
{
	$test = new \phpbbseo\migrationcenter\tests\unit\UserMigrationIntegrationTest();
	$test->run();
	$passed++;
	echo "PASSED\n";
}
catch (\Exception $e)
{
	$failed++;
	echo "FAILED: " . $e->getMessage() . "\n";
}

clean_test_db($test_db, $test_prefix);
// Test 11: XenForo Group Resolution & Unicode Test
$total++;
echo "[RUN] XfGroupResolutionTest... ";
try
{
	$test = new \phpbbseo\migrationcenter\tests\unit\XfGroupResolutionTest();
	$test->run();
	$passed++;
	echo "PASSED\n";
}
catch (\Exception $e)
{
	$failed++;
	echo "FAILED: " . $e->getMessage() . "\n";
}

clean_test_db($test_db, $test_prefix);
// Test 12: XenForo Permission Translator Test
$total++;
echo "[RUN] XfPermissionTranslatorTest... ";
try
{
	$test = new \phpbbseo\migrationcenter\tests\unit\XfPermissionTranslatorTest();
	$test->run();
	$passed++;
	echo "PASSED\n";
}
catch (\Exception $e)
{
	$failed++;
	echo "FAILED: " . $e->getMessage() . "\n";
}

clean_test_db($test_db, $test_prefix);
// Test 13: Group Membership & Permissions Integration Test
$total++;
echo "[RUN] GroupMembershipAndPermissionIntegrationTest... ";
try
{
	$test = new \phpbbseo\migrationcenter\tests\unit\GroupMembershipAndPermissionIntegrationTest();
	$test->run();
	$passed++;
	echo "PASSED\n";
}
catch (\Exception $e)
{
	$failed++;
	echo "FAILED: " . $e->getMessage() . "\n";
}

clean_test_db($test_db, $test_prefix);
// Test 14: XenForo Forum Tree Builder & Hierarchy Repair Test
$total++;
echo "[RUN] XfForumTreeBuilderTest... ";
try
{
	$test = new \phpbbseo\migrationcenter\tests\unit\XfForumTreeBuilderTest();
	$test->run();
	$passed++;
	echo "PASSED\n";
}
catch (\Exception $e)
{
	$failed++;
	echo "FAILED: " . $e->getMessage() . "\n";
}

clean_test_db($test_db, $test_prefix);
// Test 15: XenForo Node Permission Mapping & Scope Security Test
$total++;
echo "[RUN] XfNodePermissionTest... ";
try
{
	$test = new \phpbbseo\migrationcenter\tests\unit\XfNodePermissionTest();
	$test->run();
	$passed++;
	echo "PASSED\n";
}
catch (\Exception $e)
{
	$failed++;
	echo "FAILED: " . $e->getMessage() . "\n";
}

clean_test_db($test_db, $test_prefix);
// Test 16: Forum & Node Permission Integration Test
$total++;
echo "[RUN] ForumAndNodePermissionIntegrationTest... ";
try
{
	$test = new \phpbbseo\migrationcenter\tests\unit\ForumAndNodePermissionIntegrationTest();
	$test->run();
	$passed++;
	echo "PASSED\n";
}
catch (\Exception $e)
{
	$failed++;
	echo "FAILED: " . $e->getMessage() . "\n";
}

clean_test_db($test_db, $test_prefix);
// Test 17: XenForo Topic Normalizer, Types & Prefixes Test
$total++;
echo "[RUN] XfTopicNormalizerTest... ";
try
{
	$test = new \phpbbseo\migrationcenter\tests\unit\XfTopicNormalizerTest();
	$test->run();
	$passed++;
	echo "PASSED\n";
}
catch (\Exception $e)
{
	$failed++;
	echo "FAILED: " . $e->getMessage() . "\n";
}

clean_test_db($test_db, $test_prefix);
// Test 18: Topic Migration & Keyset Pagination Integration Test
$total++;
echo "[RUN] TopicMigrationIntegrationTest... ";
try
{
	$test = new \phpbbseo\migrationcenter\tests\unit\TopicMigrationIntegrationTest();
	$test->run();
	$passed++;
	echo "PASSED\n";
}
catch (\Exception $e)
{
	$failed++;
	echo "FAILED: " . $e->getMessage() . "\n";
}

clean_test_db($test_db, $test_prefix);
// Test 19: XenForo Message & BBCode Converter Test
$total++;
echo "[RUN] XfMessageConverterTest... ";
try
{
	$test = new \phpbbseo\migrationcenter\tests\unit\XfMessageConverterTest();
	$test->run();
	$passed++;
	echo "PASSED\n";
}
catch (\Exception $e)
{
	$failed++;
	echo "FAILED: " . $e->getMessage() . "\n";
}

clean_test_db($test_db, $test_prefix);
// Test 20: Post Migration, BBCode Storage & Topic/Forum Finalization Test
$total++;
echo "[RUN] PostMigrationIntegrationTest... ";
try
{
	$test = new \phpbbseo\migrationcenter\tests\unit\PostMigrationIntegrationTest();
	$test->run();
	$passed++;
	echo "PASSED\n";
}
catch (\Exception $e)
{
	$failed++;
	echo "FAILED: " . $e->getMessage() . "\n";
}

clean_test_db($test_db, $test_prefix);
// Test 21: XenForo Attachment Path Resolver & Traversal Safety Test
$total++;
echo "[RUN] XfAttachmentPathResolverTest... ";
try
{
	$test = new \phpbbseo\migrationcenter\tests\unit\XfAttachmentPathResolverTest();
	$test->run();
	$passed++;
	echo "PASSED\n";
}
catch (\Exception $e)
{
	$failed++;
	echo "FAILED: " . $e->getMessage() . "\n";
}

clean_test_db($test_db, $test_prefix);
// Test 22: Attachment Migration, Physical File Copy & Inline Ordering Test
$total++;
echo "[RUN] AttachmentMigrationIntegrationTest... ";
try
{
	$test = new \phpbbseo\migrationcenter\tests\unit\AttachmentMigrationIntegrationTest();
	$test->run();
	$passed++;
	echo "PASSED\n";
}
catch (\Exception $e)
{
	$failed++;
	echo "FAILED: " . $e->getMessage() . "\n";
}

clean_test_db($test_db, $test_prefix);
// Test 23: Attachment Policy, Multi-Instance Isolation & SHA-256 Collision Test
$total++;
echo "[RUN] AttachmentPolicyAndCollisionTest... ";
try
{
	$test = new \phpbbseo\migrationcenter\tests\unit\AttachmentPolicyAndCollisionTest();
	$test->run();
	$passed++;
	echo "PASSED\n";
}
catch (\Exception $e)
{
	$failed++;
	echo "FAILED: " . $e->getMessage() . "\n";
}

clean_test_db($test_db, $test_prefix);
// Test 24: XenForo Avatar Path Resolver & Size Fallback Test
$total++;
echo "[RUN] XfAvatarPathResolverTest... ";
try
{
	$test = new \phpbbseo\migrationcenter\tests\unit\XfAvatarPathResolverTest();
	$test->run();
	$passed++;
	echo "PASSED\n";
}
catch (\Exception $e)
{
	$failed++;
	echo "FAILED: " . $e->getMessage() . "\n";
}

clean_test_db($test_db, $test_prefix);
// Test 25: Avatar Migration, Resizing, Gravatar & Native Driver Integration Test
$total++;
echo "[RUN] AvatarMigrationIntegrationTest... ";
try
{
	$test = new \phpbbseo\migrationcenter\tests\unit\AvatarMigrationIntegrationTest();
	$test->run();
	$passed++;
	echo "PASSED\n";
}
catch (\Exception $e)
{
	$failed++;
	echo "FAILED: " . $e->getMessage() . "\n";
}

clean_test_db($test_db, $test_prefix);
// Test 26: XenForo Conversation & Message Normalizer Test
$total++;
echo "[RUN] XfConversationNormalizerTest... ";
try
{
	$test = new \phpbbseo\migrationcenter\tests\unit\XfConversationNormalizerTest();
	$test->run();
	$passed++;
	echo "PASSED\n";
}
catch (\Exception $e)
{
	$failed++;
	echo "FAILED: " . $e->getMessage() . "\n";
}

clean_test_db($test_db, $test_prefix);
// Test 27: Conversation & Private Message Migration Integration Test
$total++;
echo "[RUN] PrivateMessageMigrationIntegrationTest... ";
try
{
	$test = new \phpbbseo\migrationcenter\tests\unit\PrivateMessageMigrationIntegrationTest();
	$test->run();
	$passed++;
	echo "PASSED\n";
}
catch (\Exception $e)
{
	$failed++;
	echo "FAILED: " . $e->getMessage() . "\n";
}

clean_test_db($test_db, $test_prefix);
// Test 28: Phase 5B/5C Correctness, Privacy & Relationship Reconciliation Audit Test
$total++;
echo "[RUN] Phase5b5cCorrectnessAuditTest... ";
try
{
	$test = new \phpbbseo\migrationcenter\tests\unit\Phase5b5cCorrectnessAuditTest();
	$test->run();
	$passed++;
	echo "PASSED\n";
}
catch (\Exception $e)
{
	$failed++;
	echo "FAILED: " . $e->getMessage() . "\n";
}

clean_test_db($test_db, $test_prefix);
// Test 29: Private Message Attachment Migration & Privacy Integration Test
$total++;
echo "[RUN] PrivateMessageAttachmentMigrationIntegrationTest... ";
try
{
	$test = new \phpbbseo\migrationcenter\tests\unit\PrivateMessageAttachmentMigrationIntegrationTest();
	$test->run();
	$passed++;
	echo "PASSED\n";
}
catch (\Exception $e)
{
	$failed++;
	echo "FAILED: " . $e->getMessage() . "\n";
}

clean_test_db($test_db, $test_prefix);
// Test 30: Phase 5D Security & Phase 5E Thread Poll Migration Integration Test
$total++;
echo "[RUN] PollMigrationIntegrationTest... ";
try
{
	$test = new \phpbbseo\migrationcenter\tests\unit\PollMigrationIntegrationTest();
	$test->run();
	$passed++;
	echo "PASSED\n";
}
catch (\Exception $e)
{
	$failed++;
	echo "FAILED: " . $e->getMessage() . "\n";
}

clean_test_db($test_db, $test_prefix);
// Test 31: Phase 5D/5E Reconciliation Audit & Phase 5F Bans Integration Test
$total++;
echo "[RUN] BanMigrationIntegrationTest... ";
try
{
	$test = new \phpbbseo\migrationcenter\tests\unit\BanMigrationIntegrationTest();
	$test->run();
	$passed++;
	echo "PASSED\n";
}
catch (\Exception $e)
{
	$failed++;
	echo "FAILED: " . $e->getMessage() . "\n";
}

clean_test_db($test_db, $test_prefix);
// Test 32: Phase 6 Finalization, Recounts, Search Indexing & Verification Test
$total++;
echo "[RUN] FinalizationAndVerificationIntegrationTest... ";
try
{
	$test = new \phpbbseo\migrationcenter\tests\unit\FinalizationAndVerificationIntegrationTest();
	$test->run();
	$passed++;
	echo "PASSED\n";
}
catch (\Exception $e)
{
	$failed++;
	echo "FAILED: " . $e->getMessage() . "\n";
}

clean_test_db($test_db, $test_prefix);
// Test 33: Rollback, Fast Reset, Cancel & Progress Test
$total++;
echo "[RUN] RollbackAndSafetyTest... ";
try
{
	$test = new \phpbbseo\migrationcenter\tests\unit\RollbackAndSafetyTest();
	$test->run();
	$passed++;
	echo "PASSED\n";
}
catch (\Exception $e)
{
	$failed++;
	echo "FAILED: " . $e->getMessage() . "\n";
}

clean_test_db($test_db, $test_prefix);
// Test 34: Single Non-Terminal Run Guard & Concurrency Protection Test
$total++;
echo "[RUN] SingleRunGuardTest... ";
try
{
	$test = new \phpbbseo\migrationcenter\tests\unit\SingleRunGuardTest();
	$test->run();
	$passed++;
	echo "PASSED\n";
}
catch (\Exception $e)
{
	$failed++;
	echo "FAILED: " . $e->getMessage() . "\n";
}

clean_test_db($test_db, $test_prefix);
// Test 35: Stage Checkpoint, Invariant Reconciliation & Approval Lifecycle Test
$total++;
echo "[RUN] StageCheckpointTest... ";
try
{
	$test = new \phpbbseo\migrationcenter\tests\unit\StageCheckpointTest();
	$test->run();
	$passed++;
	echo "PASSED\n";
}
catch (\Exception $e)
{
	$failed++;
	echo "FAILED: " . $e->getMessage() . "\n";
}

clean_test_db($test_db, $test_prefix);
// Test 36: Wizard Totals, Denominator Reconciliation & Source Steps Test
$total++;
echo "[RUN] WizardTotalsTest... ";
try
{
	$test = new \phpbbseo\migrationcenter\tests\unit\WizardTotalsTest();
	$test->run();
	$passed++;
	echo "PASSED\n";
}
catch (\Exception $e)
{
	$failed++;
	echo "FAILED: " . $e->getMessage() . "\n";
}

clean_test_db($test_db, $test_prefix);
// Test 37: Start Mechanism, Ready State, First-Stage Batch & Isolation Test
$total++;
echo "[RUN] StartMechanismTest... ";
try
{
	$test = new \phpbbseo\migrationcenter\tests\unit\StartMechanismTest();
	$test->run();
	$passed++;
	echo "PASSED\n";
}
catch (\Exception $e)
{
	$failed++;
	echo "FAILED: " . $e->getMessage() . "\n";
}

clean_test_db($test_db, $test_prefix);
// Test 38: Unified Worker, Stage Ordering & Live Progress Architecture Test
$total++;
echo "[RUN] UnifiedWorkerAndStageOrderTest... ";
try
{
	$test = new \phpbbseo\migrationcenter\tests\unit\UnifiedWorkerAndStageOrderTest();
	$test->run();
	$passed++;
	echo "PASSED\n";
}
catch (\Exception $e)
{
	$failed++;
	echo "FAILED: " . $e->getMessage() . "\n";
}

clean_test_db($test_db, $test_prefix);
// Test 39: CLI Worker Start Semantics & State Machine Test
$total++;
echo "[RUN] CliWorkerStartTest... ";
try
{
	$test = new \phpbbseo\migrationcenter\tests\unit\CliWorkerStartTest();
	$test->run();
	$passed++;
	echo "PASSED\n";
}
catch (\Exception $e)
{
	$failed++;
	echo "FAILED: " . $e->getMessage() . "\n";
}

// Global Automated Test Teardown: ensure zero test fixtures remain in target DB
$test_db->sql_query("DELETE FROM {$test_prefix}migration_runs");
$test_db->sql_query("DELETE FROM {$test_prefix}migration_steps");
$test_db->sql_query("DELETE FROM {$test_prefix}migration_id_map");
$test_db->sql_query("DELETE FROM {$test_prefix}migration_errors");
$test_db->sql_query("DELETE FROM {$test_prefix}migration_locks");
$test_db->sql_query("DELETE FROM {$test_prefix}groups WHERE group_id > 7");

// Clean any test files generated during unit tests
foreach (glob($phpbb_root_path . 'files/*') as $f) {
	if (is_file($f) && !in_array(basename($f), ['.htaccess', 'index.htm'])) {
		@unlink($f);
	}
}
foreach (glob($phpbb_root_path . 'images/avatars/upload/*') as $a) {
	if (is_file($a) && !in_array(basename($a), ['.htaccess', 'index.htm'])) {
		@unlink($a);
	}
}

echo "\n-------------------------------------------\n";
echo "Summary: Total: {$total}, Passed: {$passed}, Failed: {$failed}\n";
echo "===========================================\n";

exit($failed > 0 ? 1 : 0);
