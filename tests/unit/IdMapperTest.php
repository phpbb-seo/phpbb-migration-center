<?php
/**
 * ID Mapper Unit Test
 */

namespace phpbbseo\migrationcenter\tests\unit;

use phpbbseo\migrationcenter\core\mapping\id_mapper;

class IdMapperTest
{
	public function run()
	{
		list($db, $table_prefix) = get_test_db();
		$mapper = new id_mapper($db, $table_prefix);

		$test_run_id = 'test_unit_run_' . time();

		// Test 1: Single set
		$res = $mapper->set($test_run_id, 'test_sys', 'user', 99001, 1001, 'mapped', 'checksum1');
		if (!$res)
		{
			throw new \Exception("Single set failed");
		}

		// Test 2: Single get target
		$target_id = $mapper->get_target_id('test_sys', 'user', 99001);
		if ((string)$target_id !== '1001')
		{
			throw new \Exception("Target ID lookup failed. Got: " . var_export($target_id, true));
		}

		// Test 3: Single get source
		$source_id = $mapper->get_source_id('test_sys', 'user', 1001);
		if ((string)$source_id !== '99001')
		{
			throw new \Exception("Source ID lookup failed. Got: " . var_export($source_id, true));
		}

		// Test 4: Batch set
		$count = $mapper->set_batch($test_run_id, 'test_sys', 'topic', [
			['source_id' => 99101, 'target_id' => 2001, 'status' => 'mapped'],
			['source_id' => 99102, 'target_id' => 2002, 'status' => 'mapped'],
		]);
		if ($count !== 2)
		{
			throw new \Exception("Batch set failed. Expected 2, got: {$count}");
		}

		// Test 5: Bulk lookup
		$targets = $mapper->get_target_ids('test_sys', 'topic', [99101, 99102]);
		if (!isset($targets['99101']) || $targets['99101'] !== '2001' || !isset($targets['99102']) || $targets['99102'] !== '2002')
		{
			throw new \Exception("Bulk get_target_ids failed: " . json_encode($targets));
		}

		// Test 6: Structured metadata set and get
		$meta_payload = [
			'first_post_id'   => 5001,
			'last_post_id'    => 5010,
			'original_title'  => "XXXXX_XXXY_UnicodeRunner\xE2\x80\x8CXXX",
			'prefix_id'       => 2,
			'discussion_type' => 'discussion',
		];
		$mapper->set($test_run_id, 'test_sys', 'topic_meta', 99201, 3001, 'mapped', '', $meta_payload);
		$retrieved_meta = $mapper->get_metadata('test_sys', 'topic_meta', 99201);
		if (!isset($retrieved_meta['first_post_id']) || $retrieved_meta['first_post_id'] !== 5001 || $retrieved_meta['original_title'] !== $meta_payload['original_title'])
		{
			throw new \Exception("Metadata round trip failed: " . json_encode($retrieved_meta));
		}

		// Clean up test records
		$db->sql_query("DELETE FROM {$table_prefix}migration_id_map WHERE run_id = '{$test_run_id}'");

		return true;
	}
}
