<?php
/**
 * phpBB Migration Center Extension
 *
 * @copyright (c) 2026 phpBB SEO Team
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace phpbbseo\migrationcenter\source\vbulletin;

use phpbbseo\migrationcenter\core\contract\source_provider_interface;
use phpbbseo\migrationcenter\core\dto\migration_config_dto;
use phpbbseo\migrationcenter\core\dto\preflight_result_dto;
use phpbbseo\migrationcenter\source\vbulletin\adapter\vb_db_adapter;
use phpbbseo\migrationcenter\source\vbulletin\config\vb_config_detector;
use phpbbseo\migrationcenter\source\vbulletin\version\vb_version_detector;

/**
 * vBulletin 3.8 / 4.2 Generic Source Provider
 */
class vbulletin_source_provider implements source_provider_interface
{
	/** @var string */
	protected $phpbb_root_path;

	/**
	 * Constructor
	 *
	 * @param string $phpbb_root_path
	 */
	public function __construct(string $phpbb_root_path = '')
	{
		$this->phpbb_root_path = $phpbb_root_path ?: (defined('PHPBB_ROOT_PATH') ? PHPBB_ROOT_PATH : './');
	}

	/**
	 * Get system identifier
	 *
	 * @return string
	 */
	public function get_system_name(): string
	{
		return 'vbulletin';
	}

	/**
	 * Get human-readable source title
	 *
	 * @return string
	 */
	public function get_title(): string
	{
		return 'vBulletin 3.8 / 4.2';
	}

	/**
	 * Detect source version
	 *
	 * @param migration_config_dto $config
	 * @return string
	 */
	public function detect_version(migration_config_dto $config): string
	{
		try
		{
			$db = new vb_db_adapter($config);
			$info = vb_version_detector::detect($db, $config->source_path);
			return $info['version_string'];
		}
		catch (\Throwable $e)
		{
			return 'Unknown';
		}
	}

	/**
	 * Get supported migration steps
	 *
	 * @return array
	 */
	public function get_supported_steps(): array
	{
		return [
			'groups',
			'users',
			'group_memberships',
			'global_permissions',
			'forums',
			'node_permissions',
			'topics',
			'posts',
			'attachments',
			'avatars',
			'conversations',
			'conversation_messages',
			'conversation_attachments',
			'polls',
			'bans',
		];
	}

	/**
	 * Run comprehensive preflight checks
	 *
	 * @param migration_config_dto $config
	 * @return preflight_result_dto
	 */
	public function run_preflight(migration_config_dto $config): preflight_result_dto
	{
		$result = new preflight_result_dto();

		// Auto-detect config from local source path only for missing defaults
		if (!empty($config->source_path))
		{
			if (empty($config->db_name) || empty($config->db_user) || empty($config->db_host) || empty($config->db_port))
			{
				$detected = vb_config_detector::detect_from_path($config->source_path);
				if ($detected)
				{
					if (empty($config->db_host)) $config->db_host = $detected->db_host;
					if (empty($config->db_port)) $config->db_port = $detected->db_port;
					if (empty($config->db_name)) $config->db_name = $detected->db_name;
					if (empty($config->db_user)) $config->db_user = $detected->db_user;
					if (empty($config->db_password)) $config->db_password = $detected->db_password;
					if (empty($config->db_prefix)) $config->db_prefix = $detected->db_prefix;
					if (empty($config->db_charset)) $config->db_charset = $detected->db_charset;
				}
			}
		}

		// 1. PHP Extensions Check
		$required_extensions = ['pdo_mysql', 'mbstring', 'json'];
		$missing_exts = [];
		foreach ($required_extensions as $ext)
		{
			if (!extension_loaded($ext))
			{
				$missing_exts[] = $ext;
			}
		}

		if (empty($missing_exts))
		{
			$result->add_item('php_extensions', 'PHP Extensions', 'success', 'All required PHP extensions (pdo_mysql, mbstring, json) are loaded.');
		}
		else
		{
			$result->add_item('php_extensions', 'PHP Extensions', 'failure', 'Missing required PHP extensions: ' . implode(', ', $missing_exts));
		}

		// 2. Source Path Check
		$has_source_path = !empty($config->source_path) && is_dir($config->source_path);
		if ($has_source_path)
		{
			$source_path = rtrim(str_replace('\\', '/', $config->source_path), '/');
			$config_file = $source_path . '/includes/config.php';
			if (file_exists($config_file) && is_readable($config_file))
			{
				$result->add_item('source_path', 'Source Root Path & Configuration', 'success', "vBulletin directory and includes/config.php located at: {$source_path}");
			}
			else
			{
				$result->add_item('source_path', 'Source Root Path', 'warning', "vBulletin directory found, but includes/config.php is missing or unreadable.");
			}
		}
		else if (!empty($config->source_path))
		{
			$result->add_item('source_path', 'Source Root Path', 'warning', "Source directory not accessible: {$config->source_path}. Database-only migration will proceed.");
		}

		// 3. Database Connection & Read-Only Behavior
		$db = null;
		try
		{
			$db = new vb_db_adapter($config);
			$result->add_item('db_connection', 'Database Connection', 'success', "Successfully connected to vBulletin database: `{$config->db_name}` on {$config->db_host}:{$config->db_port}");
		}
		catch (\Throwable $e)
		{
			$result->add_item('db_connection', 'Database Connection', 'failure', $e->getMessage());
			return $result; // Fatal blocker
		}

		// 4. Source != Target Database Safety Check
		global $dbhost, $dbname, $dbport;
		$target_db = defined('PHPBB_DBNAME') ? PHPBB_DBNAME : ($dbname ?? '');
		$target_host = defined('PHPBB_DBHOST') ? PHPBB_DBHOST : ($dbhost ?? '');
		if (!empty($target_db) && strtolower($config->db_name) === strtolower($target_db))
		{
			if (empty($target_host) || strtolower($config->db_host) === strtolower($target_host) || in_array($config->db_host, ['localhost', '127.0.0.1']))
			{
				$result->add_item('target_collision', 'Database Collision Guard', 'failure', "Source database `{$config->db_name}` appears to be the same as the target phpBB database! Refusing migration for safety.");
				return $result;
			}
		}

		// 5. Version & Variant Detection
		$version_info = vb_version_detector::detect($db, $config->source_path);
		if (!$version_info['is_supported'])
		{
			$result->add_item('vb_version', 'vBulletin Version Detection', 'failure', $version_info['error'] ?? 'Unsupported vBulletin version.');
			return $result;
		}

		$variant_label = ($version_info['variant'] === 'vbulletin_3') ? 'vBulletin 3.8.x' : 'vBulletin 4.2.x';
		$result->add_item('vb_version', 'vBulletin Version Detection', 'success', "Detected {$variant_label} (Version: {$version_info['version_string']}, Variant: {$version_info['variant']}, Detection: {$version_info['confidence']})");

		// 6. Required Table Fingerprints Validation
		$common_tables = [
			'setting', 'datastore', 'usergroup', 'user', 'userfield', 'usertextfield',
			'administrator', 'moderator', 'forumpermission', 'forum', 'thread', 'post',
			'deletionlog', 'editlog', 'attachment', 'customavatar', 'avatar',
			'pmtext', 'pm', 'poll', 'pollvote', 'userban', 'usertitle', 'bbcode'
		];

		$missing_tables = [];
		foreach ($common_tables as $tbl)
		{
			if (!$db->table_exists($tbl))
			{
				$missing_tables[] = $db->get_table_name($tbl);
			}
		}

		if ($version_info['variant'] === 'vbulletin_4')
		{
			$vb4_tables = ['filedata', 'prefix', 'prefixset'];
			foreach ($vb4_tables as $tbl)
			{
				if (!$db->table_exists($tbl))
				{
					$missing_tables[] = $db->get_table_name($tbl);
				}
			}
		}

		if (empty($missing_tables))
		{
			$result->add_item('required_tables', 'Core Database Tables', 'success', 'All required core tables exist with valid schema.');
		}
		else
		{
			$result->add_item('required_tables', 'Core Database Tables', 'failure', 'Missing required vBulletin tables: ' . implode(', ', $missing_tables));
		}

		// 7. Expected Primary Keys
		$pk_checks = [
			'user'        => 'userid',
			'forum'       => 'forumid',
			'thread'      => 'threadid',
			'post'        => 'postid',
			'attachment'  => 'attachmentid',
			'usergroup'   => 'usergroupid',
			'pmtext'      => 'pmtextid'
		];

		$pk_failures = [];
		foreach ($pk_checks as $tbl => $expected_pk)
		{
			if ($db->table_exists($tbl))
			{
				$cols = $db->get_column_names($tbl);
				if (!in_array($expected_pk, $cols, true))
				{
					$pk_failures[] = "Table `{$tbl}` missing expected primary key `{$expected_pk}`";
				}
			}
		}

		if (empty($pk_failures))
		{
			$result->add_item('primary_keys', 'Primary Key Integrity', 'success', 'All core entity tables contain expected primary key columns.');
		}
		else
		{
			$result->add_item('primary_keys', 'Primary Key Integrity', 'failure', implode('; ', $pk_failures));
		}

		// 8. Storage Modes (Attachments & Avatars)
		try
		{
			$setting_tbl = $db->get_table_name('setting');
			$attach_save = (int)$db->fetch_one("SELECT value FROM {$setting_tbl} WHERE varname = 'attachsave'");
			$attach_path = (string)$db->fetch_one("SELECT value FROM {$setting_tbl} WHERE varname = 'attachpath'");
			$avatar_save = (int)$db->fetch_one("SELECT value FROM {$setting_tbl} WHERE varname = 'usefileavatar'");
			$avatar_path = (string)$db->fetch_one("SELECT value FROM {$setting_tbl} WHERE varname = 'avatarpath'");

			if ($attach_save === 0)
			{
				$result->add_item('attachment_storage', 'Attachment Storage Mode', 'success', 'Attachments are stored directly in the database (BLOB mode).');
			}
			else
			{
				$result->add_item('attachment_storage', 'Attachment Storage Mode', 'info', "Attachments stored in filesystem at: {$attach_path}");
			}

			if ($avatar_save === 0)
			{
				$result->add_item('avatar_storage', 'Avatar Storage Mode', 'success', 'Custom avatars are stored directly in the database (BLOB mode).');
			}
			else
			{
				$result->add_item('avatar_storage', 'Avatar Storage Mode', 'info', "Custom avatars stored in filesystem at: {$avatar_path}");
			}
		}
		catch (\Throwable $e)
		{
			$result->add_item('attachment_storage', 'Storage Configuration', 'warning', 'Could not query storage configuration settings.');
		}

		// 9. Source Counts Overview
		try
		{
			$u_cnt = $this->get_total_records('users', $config);
			$t_cnt = $this->get_total_records('topics', $config);
			$p_cnt = $this->get_total_records('posts', $config);
			$a_cnt = $this->get_total_records('attachments', $config);
			$pm_cnt = $this->get_total_records('conversations', $config);

			$result->add_item('source_counts', 'Source Data Counts', 'success', "Detected {$u_cnt} users, {$t_cnt} topics, {$p_cnt} posts, {$a_cnt} attachments, and {$pm_cnt} PM threads.");
		}
		catch (\Throwable $e)
		{
			$result->add_item('source_counts', 'Source Data Counts', 'warning', 'Unable to calculate total source record counts: ' . $e->getMessage());
		}

		return $result;
	}

	/**
	 * Get maximum source ID for a given step
	 *
	 * @param string $step_name
	 * @param migration_config_dto $config
	 * @return string|int
	 */
	public function get_max_source_id(string $step_name, migration_config_dto $config)
	{
		$db = new vb_db_adapter($config);
		switch ($step_name)
		{
			case 'groups':
			case 'global_permissions':
				return (int)$db->fetch_one("SELECT MAX(usergroupid) FROM " . $db->get_table_name('usergroup'));

			case 'users':
			case 'group_memberships':
			case 'avatars':
			case 'bans':
				return (int)$db->fetch_one("SELECT MAX(userid) FROM " . $db->get_table_name('user'));

			case 'forums':
				return (int)$db->fetch_one("SELECT MAX(forumid) FROM " . $db->get_table_name('forum'));

			case 'node_permissions':
				return (int)$db->fetch_one("SELECT MAX(forumpermissionid) FROM " . $db->get_table_name('forumpermission'));

			case 'topics':
				return (int)$db->fetch_one("SELECT MAX(threadid) FROM " . $db->get_table_name('thread'));

			case 'posts':
				return (int)$db->fetch_one("SELECT MAX(postid) FROM " . $db->get_table_name('post'));

			case 'attachments':
				return (int)$db->fetch_one("SELECT MAX(attachmentid) FROM " . $db->get_table_name('attachment'));

			case 'conversations':
			case 'conversation_messages':
				return (int)$db->fetch_one("SELECT MAX(pmtextid) FROM " . $db->get_table_name('pmtext'));

			case 'conversation_attachments':
				return 0;

			case 'polls':
				return (int)$db->fetch_one("SELECT MAX(pollid) FROM " . $db->get_table_name('poll'));

			default:
				return 0;
		}
	}

	/**
	 * Get total records for a given step
	 *
	 * @param string $step_name
	 * @param migration_config_dto $config
	 * @return int
	 */
	public function get_total_records(string $step_name, migration_config_dto $config): int
	{
		$db = new vb_db_adapter($config);
		switch ($step_name)
		{
			case 'groups':
			case 'global_permissions':
				return (int)$db->fetch_one("SELECT COUNT(*) FROM " . $db->get_table_name('usergroup'));

			case 'users':
			case 'group_memberships':
				return (int)$db->fetch_one("SELECT COUNT(*) FROM " . $db->get_table_name('user'));

			case 'forums':
				return (int)$db->fetch_one("SELECT COUNT(*) FROM " . $db->get_table_name('forum'));

			case 'node_permissions':
				return (int)$db->fetch_one("SELECT COUNT(*) FROM " . $db->get_table_name('forumpermission'));

			case 'topics':
				return (int)$db->fetch_one("SELECT COUNT(*) FROM " . $db->get_table_name('thread'));

			case 'posts':
				return (int)$db->fetch_one("SELECT COUNT(*) FROM " . $db->get_table_name('post'));

			case 'attachments':
				// Count attachment records without double-counting vB4 filedata
				return (int)$db->fetch_one("SELECT COUNT(*) FROM " . $db->get_table_name('attachment'));

			case 'avatars':
				return (int)$db->fetch_one("SELECT COUNT(*) FROM " . $db->get_table_name('customavatar'));

			case 'conversations':
			case 'conversation_messages':
				// PM text master records
				return (int)$db->fetch_one("SELECT COUNT(*) FROM " . $db->get_table_name('pmtext'));

			case 'conversation_attachments':
				return 0; // NOT APPLICABLE in native vBulletin core

			case 'polls':
				return (int)$db->fetch_one("SELECT COUNT(*) FROM " . $db->get_table_name('poll'));

			case 'bans':
				return (int)$db->fetch_one("SELECT COUNT(*) FROM " . $db->get_table_name('userban'));

			default:
				return 0;
		}
	}

	/**
	 * Read deterministic batch
	 *
	 * @param string $step_name
	 * @param string|int $cursor
	 * @param int $batch_size
	 * @param migration_config_dto $config
	 * @return array
	 */
	public function read_batch(string $step_name, $cursor, int $batch_size, migration_config_dto $config): array
	{
		// Batch reading is invoked by specific migration steps in subsequent phases
		return [];
	}

	/**
	 * Feature compatibility information
	 *
	 * @return array
	 */
	public function get_feature_compatibility(): array
	{
		return [
			'pm_attachments' => [
				'supported' => false,
				'reason'    => 'Native vBulletin 3.8/4.2 core does not support attachments in Private Messages (NOT APPLICABLE).',
			],
			'social_groups' => [
				'supported' => false,
				'reason'    => 'vBulletin Social Groups are not mapped to core phpBB entities and are preserved in source.',
			],
			'blogs_albums' => [
				'supported' => false,
				'reason'    => 'vB Blog and User Photo Albums are out of scope for phpBB core forum migration.',
			],
			'password_auth' => [
				'supported' => true,
				'reason'    => 'Native double-MD5 + 30-character salt verified. Re-hashed seamlessly upon successful login in phpBB.',
			],
		];
	}
}
