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
 * vBulletin 6.x Dedicated Source Provider (Node & Text Architecture)
 */
class vb6_source_provider implements source_provider_interface
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
		return 'vbulletin6';
	}

	/**
	 * Get human-readable source title
	 *
	 * @return string
	 */
	public function get_title(): string
	{
		return 'vBulletin 6.x';
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

		// Auto-detect config from local source path if missing
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
			$config_file = file_exists($source_path . '/core/includes/config.php') ? $source_path . '/core/includes/config.php' : $source_path . '/includes/config.php';
			if (file_exists($config_file) && is_readable($config_file))
			{
				$result->add_item('source_path', 'Source Root Path & Configuration', 'success', "vBulletin directory and config.php located at: {$config_file}");
			}
			else
			{
				$result->add_item('source_path', 'Source Root Path', 'warning', "vBulletin directory found, but config.php is missing or unreadable.");
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
			return $result;
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

		$result->add_item('vb_version', 'vBulletin Version Detection', 'success', "Detected {$version_info['variant']} (Version: {$version_info['version_string']}, Variant: {$version_info['variant']}, Detection: {$version_info['confidence']})");

		// 6. Required vB6 Node Architecture Tables Validation
		$common_tables = [
			'setting', 'usergroup', 'user', 'userfield', 'usertextfield',
			'node', 'text', 'channel', 'closure', 'routenew',
			'customavatar', 'userban'
		];

		$missing_tables = [];
		foreach ($common_tables as $tbl)
		{
			if (!$db->table_exists($tbl))
			{
				$missing_tables[] = $db->get_table_name($tbl);
			}
		}

		if (empty($missing_tables))
		{
			$result->add_item('required_tables', 'Core Database Tables', 'success', 'All required vBulletin 6 Node architecture tables exist.');
		}
		else
		{
			$result->add_item('required_tables', 'Core Database Tables', 'failure', 'Missing required vBulletin 6 tables: ' . implode(', ', $missing_tables));
		}

		// 7. Source Counts Overview
		try
		{
			$u_cnt = $this->get_total_records('users', $config);
			$f_cnt = $this->get_total_records('forums', $config);
			$t_cnt = $this->get_total_records('topics', $config);
			$p_cnt = $this->get_total_records('posts', $config);

			$result->add_item('source_counts', 'Source Data Counts', 'success', "Detected {$u_cnt} users, {$f_cnt} channels/forums, {$t_cnt} topics, and {$p_cnt} posts.");
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
				return (int)$db->fetch_one("SELECT MAX(userid) FROM " . $db->get_table_name('user'));

			case 'bans':
				return (int)$db->fetch_one("SELECT MAX(userid) FROM " . $db->get_table_name('userban'));

			case 'forums':
				return (int)$db->fetch_one("SELECT MAX(nodeid) FROM " . $db->get_table_name('node') . " WHERE contenttypeid = 23");

			case 'node_permissions':
				return (int)$db->fetch_one("SELECT MAX(permissionid) FROM " . $db->get_table_name('permission'));

			case 'topics':
				return (int)$db->fetch_one("SELECT MAX(nodeid) FROM " . $db->get_table_name('node') . " WHERE contenttypeid = 22 AND starter = nodeid");

			case 'posts':
				return (int)$db->fetch_one("SELECT MAX(nodeid) FROM " . $db->get_table_name('node') . " WHERE contenttypeid = 22");

			case 'attachments':
				$tbl_attach = $db->get_table_name('attach');
				$tbl_node = $db->get_table_name('node');
				return (int)$db->fetch_one("SELECT MAX(a.nodeid) FROM {$tbl_attach} a JOIN {$tbl_node} n ON n.nodeid = a.nodeid JOIN {$tbl_node} p ON p.nodeid = n.parentid WHERE p.contenttypeid != 27");

			case 'conversations':
			case 'conversation_messages':
				return (int)$db->fetch_one("SELECT MAX(nodeid) FROM " . $db->get_table_name('node') . " WHERE contenttypeid = 27");

			case 'conversation_attachments':
				$tbl_attach = $db->get_table_name('attach');
				$tbl_node = $db->get_table_name('node');
				return (int)$db->fetch_one("SELECT MAX(a.nodeid) FROM {$tbl_attach} a JOIN {$tbl_node} n ON n.nodeid = a.nodeid JOIN {$tbl_node} p ON p.nodeid = n.parentid WHERE p.contenttypeid = 27");

			case 'polls':
				return $db->table_exists('poll') ? (int)$db->fetch_one("SELECT MAX(nodeid) FROM " . $db->get_table_name('poll')) : 0;

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
				$tbl_node = $db->get_table_name('node');
				$tbl_closure = $db->get_table_name('closure');
				return (int)$db->fetch_one("SELECT COUNT(*) FROM {$tbl_node} n JOIN {$tbl_closure} c ON c.child = n.nodeid AND c.parent = 2 WHERE n.contenttypeid = 23");

			case 'node_permissions':
				return (int)$db->fetch_one("SELECT COUNT(*) FROM " . $db->get_table_name('permission'));

			case 'topics':
				return (int)$db->fetch_one("SELECT COUNT(*) FROM " . $db->get_table_name('node') . " WHERE contenttypeid = 22 AND starter = nodeid");

			case 'posts':
				return (int)$db->fetch_one("SELECT COUNT(*) FROM " . $db->get_table_name('node') . " WHERE contenttypeid = 22");

			case 'attachments':
				$tbl_attach = $db->get_table_name('attach');
				$tbl_node = $db->get_table_name('node');
				return (int)$db->fetch_one("SELECT COUNT(*) FROM {$tbl_attach} a JOIN {$tbl_node} n ON n.nodeid = a.nodeid JOIN {$tbl_node} p ON p.nodeid = n.parentid WHERE p.contenttypeid != 27");

			case 'avatars':
				return (int)$db->fetch_one("SELECT COUNT(*) FROM " . $db->get_table_name('customavatar'));

			case 'conversations':
				return (int)$db->fetch_one("SELECT COUNT(*) FROM " . $db->get_table_name('node') . " WHERE contenttypeid = 27 AND starter = nodeid");

			case 'conversation_messages':
				return (int)$db->fetch_one("SELECT COUNT(*) FROM " . $db->get_table_name('node') . " WHERE contenttypeid = 27");

			case 'conversation_attachments':
				$tbl_attach = $db->get_table_name('attach');
				$tbl_node = $db->get_table_name('node');
				return (int)$db->fetch_one("SELECT COUNT(*) FROM {$tbl_attach} a JOIN {$tbl_node} n ON n.nodeid = a.nodeid JOIN {$tbl_node} p ON p.nodeid = n.parentid WHERE p.contenttypeid = 27");

			case 'polls':
				return $db->table_exists('poll') ? (int)$db->fetch_one("SELECT COUNT(*) FROM " . $db->get_table_name('poll')) : 0;

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
			'node_architecture' => [
				'supported' => true,
				'reason'    => 'vBulletin 6 Node & Text unified architecture fully supported.',
			],
			'password_auth' => [
				'supported' => true,
				'reason'    => 'Argon2id and legacy hashes verified seamlessly upon login.',
			],
			'channels' => [
				'supported' => true,
				'reason'    => 'Hierarchical channels mapped to phpBB categories and forums.',
			],
		];
	}
}
