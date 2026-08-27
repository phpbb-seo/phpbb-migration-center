<?php
/**
 * Hardened XenForo Attachment Path Resolver, Storage Root Containment & Version Variants Unit Test
 */

namespace phpbbseo\migrationcenter\tests\unit;

use phpbbseo\migrationcenter\source\xenforo\storage\xf_attachment_path_resolver;
use phpbbseo\migrationcenter\source\xenforo\normalizer\xf_attachment_normalizer;
use phpbbseo\migrationcenter\core\dto\migration_config_dto;

class XfAttachmentPathResolverTest
{
	public function run()
	{
		// Create a temporary mock XenForo storage root
		$temp_root = sys_get_temp_dir() . '/xf_harden_test_' . uniqid();
		$attach_dir = $temp_root . '/internal_data/attachments/0';
		$evil_dir = $temp_root . '/internal_data/attachments_evil';
		$src_dir = $temp_root . '/src';

		@mkdir($attach_dir, 0777, true);
		@mkdir($evil_dir, 0777, true);
		@mkdir($src_dir, 0777, true);

		// Sensitive files under XenForo root that must NEVER be readable as attachments
		$config_file = $src_dir . '/config.php';
		file_put_contents($config_file, "<?php \$config['db']['password'] = 'SECRET_DB_PASS';");

		$evil_file = $evil_dir . '/0-malicious.data';
		file_put_contents($evil_file, "MALICIOUS_DATA_OUTSIDE_ATTACHMENT_ROOT");

		// Valid test attachment files
		$xf23_file = $attach_dir . '/101-xf23keyabcdef.data';
		file_put_contents($xf23_file, "VALID_XF23_ATTACHMENT_DATA");

		$xf20_file = $attach_dir . '/102-d41d8cd98f00b204e9800998ecf8427e.data';
		file_put_contents($xf20_file, "VALID_XF20_ATTACHMENT_DATA");

		// Test 1: Exact XenForo 2.3.12 / 2.1+ path resolution with file_key
		$resolved_23 = xf_attachment_path_resolver::resolve_path($temp_root, 101, 'xf23keyabcdef');
		if (!$resolved_23 || str_replace('\\', '/', $resolved_23) !== str_replace('\\', '/', $xf23_file))
		{
			throw new \Exception("XenForo 2.3 path resolution failed. Got: " . var_export($resolved_23, true));
		}

		// Test 2: XenForo 2.0 path resolution with file_hash
		$resolved_20 = xf_attachment_path_resolver::resolve_path($temp_root, 102, '', 'd41d8cd98f00b204e9800998ecf8427e');
		if (!$resolved_20 || str_replace('\\', '/', $resolved_20) !== str_replace('\\', '/', $xf20_file))
		{
			throw new \Exception("XenForo 2.0 path resolution failed. Got: " . var_export($resolved_20, true));
		}

		// Test 3: Storage Root Containment — Sensitive src/config.php must be REJECTED
		$leak_attempt = xf_attachment_path_resolver::resolve_path($temp_root, 0, '', '', '%INTERNAL%../../src/config.php');
		if ($leak_attempt !== null)
		{
			throw new \Exception("CRITICAL SECURITY VIOLATION: src/config.php was read as an attachment!");
		}

		// Test 4: Prefix Confusion Rejection — attachments_evil must be REJECTED
		$prefix_confusion = xf_attachment_path_resolver::resolve_path($temp_root, 0, 'malicious', '', 'internal_data/attachments_evil/0-malicious.data');
		if ($prefix_confusion !== null)
		{
			throw new \Exception("CRITICAL SECURITY VIOLATION: Prefix confusion path attachments_evil was accepted!");
		}

		// Test 5: Path Traversal Rejection
		$traversal = xf_attachment_path_resolver::resolve_path($temp_root, 101, '../../../../windows/system32/cmd.exe');
		if ($traversal !== null)
		{
			throw new \Exception("CRITICAL SECURITY VIOLATION: Traversal was not rejected!");
		}

		// Test 6: Windows Case-Insensitive Containment
		$resolved_ci = xf_attachment_path_resolver::resolve_path(strtoupper($temp_root), 101, 'xf23keyabcdef');
		if (!$resolved_ci && DIRECTORY_SEPARATOR === '\\')
		{
			throw new \Exception("Windows case-insensitive storage root comparison failed");
		}

		// Test 7: Normalizer Unicode & Persian Filename
		$normalizer = new xf_attachment_normalizer();
		$config = new migration_config_dto();
		$config->source_path = $temp_root;

		$row = [
			'attachment_id' => 8801,
			'data_id'       => 101,
			'content_type'  => 'post',
			'content_id'    => 500,
			'user_id'       => 10,
			'filename'      => "XYXXX_XXXY_UnicodeRunner\xE2\x80\x8CXXX.png",
			'file_size'     => strlen("VALID_XF23_ATTACHMENT_DATA"),
			'file_key'      => 'xf23keyabcdef',
			'attach_date'   => 1785000000,
			'view_count'    => 15,
		];

		$dto = $normalizer->normalize_attachment($row, $config);
		if ($dto->source_id !== 8801 || $dto->real_filename !== "XYXXX_XXXY_UnicodeRunner\xE2\x80\x8CXXX.png" || $dto->extension !== 'png')
		{
			throw new \Exception("Normalizer failed for Persian filename");
		}

		// Cleanup mock storage
		@unlink($xf23_file);
		@unlink($xf20_file);
		@unlink($evil_file);
		@unlink($config_file);
		@rmdir($attach_dir);
		@rmdir($temp_root . '/internal_data/attachments');
		@rmdir($evil_dir);
		@rmdir($temp_root . '/internal_data');
		@rmdir($src_dir);
		@rmdir($temp_root);

		return true;
	}
}
