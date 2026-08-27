<?php
/**
 * phpBB Migration Center Extension
 *
 * @copyright (c) 2026 phpBB SEO Team
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace phpbbseo\migrationcenter\source\xenforo;

use phpbbseo\migrationcenter\core\contract\source_provider_interface;
use phpbbseo\migrationcenter\core\dto\migration_config_dto;
use phpbbseo\migrationcenter\core\dto\preflight_result_dto;
use phpbbseo\migrationcenter\source\xenforo\adapter\xf_db_adapter;
use phpbbseo\migrationcenter\source\xenforo\config\xf_config_detector;
use phpbbseo\migrationcenter\source\xenforo\version\xf_version_detector;
use phpbbseo\migrationcenter\source\xenforo\version\xf_base_adapter;

/**
 * XenForo 2.x Generic Source Provider
 */
class xenforo_source_provider implements source_provider_interface
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
	 * Get system name
	 *
	 * @return string
	 */
	public function get_system_name(): string
	{
		return 'xenforo';
	}

	/**
	 * Get title
	 *
	 * @return string
	 */
	public function get_title(): string
	{
		return 'XenForo 2.x';
	}

	/**
	 * Detect version
	 *
	 * @param migration_config_dto $config
	 * @return string
	 */
	public function detect_version(migration_config_dto $config): string
	{
		try
		{
			$db = new xf_db_adapter($config);
			$info = xf_version_detector::detect($db, $config->source_path);
			return $info['version_string'];
		}
		catch (\Throwable $e)
		{
			return 'Unknown';
		}
	}

	/**
	 * Run preflight checks
	 *
	 * @param migration_config_dto $config
	 * @return preflight_result_dto
	 */
	public function run_preflight(migration_config_dto $config): preflight_result_dto
	{
		$result = new preflight_result_dto();

		// Auto-detect config from local source path if DB credentials are empty
		if (!empty($config->source_path) && empty($config->db_name))
		{
			$detected = xf_config_detector::detect_from_path($config->source_path);
			if ($detected)
			{
				$config->db_host = $detected->db_host;
				$config->db_port = $detected->db_port;
				$config->db_name = $detected->db_name;
				$config->db_user = $detected->db_user;
				$config->db_password = $detected->db_password;
				$config->db_prefix = $detected->db_prefix;
			}
		}

		// 1. Check PHP Extensions
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

		// 2. Check Source Path
		$has_source_path = !empty($config->source_path) && is_dir($config->source_path);
		if ($has_source_path)
		{
			$source_path = rtrim(str_replace('\\', '/', $config->source_path), '/');
			$result->add_item('source_path', 'Source Root Path', 'success', "Source directory located at: {$source_path}");

			// Check data/ and internal_data/
			$data_dir = $source_path . '/data';
			$internal_data_dir = $source_path . '/internal_data';

			if (is_dir($data_dir) && is_readable($data_dir))
			{
				$result->add_item('source_data_dir', 'Source data/ Directory', 'success', 'data/ directory is accessible and readable.');
			}
			else
			{
				$result->add_item('source_data_dir', 'Source data/ Directory', 'warning', 'data/ directory not found or not readable. Avatars may not be copied.');
			}

			if (is_dir($internal_data_dir) && is_readable($internal_data_dir))
			{
				$result->add_item('source_internal_data_dir', 'Source internal_data/ Directory', 'success', 'internal_data/ directory is accessible and readable.');
			}
			else
			{
				$result->add_item('source_internal_data_dir', 'Source internal_data/ Directory', 'warning', 'internal_data/ directory not found or not readable. Attachments may not be copied.');
			}
		}
		else if (!empty($config->source_path))
		{
			$result->add_item('source_path', 'Source Root Path', 'failure', 'Specified source root path does not exist or is not a directory.');
		}
		else
		{
			$result->add_item('source_path', 'Source Root Path', 'warning', 'Source filesystem path not provided. Files (avatars/attachments) will not be copied.');
		}

		// 3. Check Source Database Connection & Version
		$db = null;
		$version_adapter = null;
		try
		{
			$db = new xf_db_adapter($config);
			$result->add_item('source_database', 'Source Database Connection', 'success', "Successfully connected to database '{$config->db_name}' on {$config->db_host}:{$config->db_port}.");

			// Check Charset
			$charset_res = $db->fetch_row("SHOW VARIABLES LIKE 'character_set_connection'");
			$charset_val = $charset_res['Value'] ?? 'unknown';
			$result->add_item('source_charset', 'Database Charset', 'success', "Connection character set is '{$charset_val}' (Unicode safe).");

			// Detect Version
			$version_info = xf_version_detector::detect($db, $config->source_path);
			$adapter_cls = $version_info['adapter_class'];
			$version_adapter = new $adapter_cls($db);

			$result->detected_meta['version_string'] = $version_info['version_string'];
			$result->detected_meta['version_id'] = $version_info['version_id'];

			$result->add_item('source_version', 'XenForo Version', 'success', "Detected XenForo {$version_info['version_string']} (ID: {$version_info['version_id']}).");

			// Check Required Tables
			$required_tables = $version_adapter->get_required_tables();
			$missing_tables = [];
			foreach ($required_tables as $tbl)
			{
				if (!$db->table_exists($tbl))
				{
					$missing_tables[] = $tbl;
				}
			}

			if (empty($missing_tables))
			{
				$result->add_item('source_tables', 'Required Tables', 'success', 'All core XenForo tables are present.');
			}
			else
			{
				$result->add_item('source_tables', 'Required Tables', 'warning', 'Some optional or expected tables were not found: ' . implode(', ', $missing_tables));
			}
		}
		catch (\Throwable $e)
		{
			$result->add_item('source_database', 'Source Database Connection', 'failure', 'Could not connect to XenForo database: ' . $e->getMessage());
		}

		// 4. Check Target phpBB Version & Writable Permissions
		$target_dirs = [
			'files'                => $this->phpbb_root_path . 'files',
			'images/avatars/upload'=> $this->phpbb_root_path . 'images/avatars/upload',
			'store'                => $this->phpbb_root_path . 'store',
			'cache'                => $this->phpbb_root_path . 'cache',
		];

		$unwritable_dirs = [];
		foreach ($target_dirs as $label => $dir_path)
		{
			if (!is_dir($dir_path) || !is_writable($dir_path))
			{
				$unwritable_dirs[] = $label;
			}
		}

		if (empty($unwritable_dirs))
		{
			$result->add_item('target_permissions', 'Target Write Permissions', 'success', 'All target phpBB storage directories (files/, avatars/, store/, cache/) are writable.');
		}
		else
		{
			$result->add_item('target_permissions', 'Target Write Permissions', 'failure', 'The following phpBB directories are not writable: ' . implode(', ', $unwritable_dirs));
		}

		return $result;
	}

	/**
	 * Get ordered list of supported steps
	 *
	 * @return array
	 */
	public function get_supported_steps(): array
	{
		$adapter = new xf_base_adapter();
		return $adapter->get_supported_steps();
	}

	/**
	 * Get maximum source ID for a step
	 *
	 * @param string $step_name
	 * @param migration_config_dto $config
	 * @return string|int
	 */
	public function get_max_source_id(string $step_name, migration_config_dto $config)
	{
		try
		{
			$db = new xf_db_adapter($config);
			switch ($step_name)
			{
				case 'groups':
					return $db->get_max_id('xf_user_group', 'user_group_id');
				case 'users':
					return $db->get_max_id('xf_user', 'user_id');
				case 'group_memberships':
					return $db->get_max_id('xf_user_group_relation', 'user_id');
				case 'global_permissions':
					return $db->get_max_id('xf_permission_entry', 'user_group_id');
				case 'forums':
					return $db->get_max_id('xf_node', 'node_id');
				case 'node_permissions':
					return $db->get_max_id('xf_permission_entry_content', 'content_id');
				case 'topics':
					return $db->get_max_id('xf_thread', 'thread_id');
				case 'posts':
					return $db->get_max_id('xf_post', 'post_id');
				case 'attachments':
					return (int)$db->fetch_one("SELECT MAX(attachment_id) FROM `{$db->get_prefix()}attachment` WHERE content_type = 'post'");
				case 'avatars':
					return (int)$db->fetch_one("SELECT MAX(user_id) FROM `{$db->get_prefix()}user` WHERE avatar_date > 0");
				case 'conversations':
				case 'pms':
					return $db->get_max_id('xf_conversation_master', 'conversation_id');
				case 'conversation_messages':
					return $db->get_max_id('xf_conversation_message', 'message_id');
				case 'conversation_attachments':
					return (int)$db->fetch_one("SELECT MAX(attachment_id) FROM `{$db->get_prefix()}attachment` WHERE content_type = 'conversation_message'");
				case 'polls':
					return $db->get_max_id('xf_poll', 'poll_id');
				case 'bans':
					return $db->get_max_id('xf_user_ban', 'user_id');
				case 'subscriptions':
					return $db->get_max_id('xf_thread_watch', 'user_id');
				default:
					return 0;
			}
		}
		catch (\Throwable $e)
		{
			return 0;
		}
	}

	/**
	 * Get total records for a step
	 *
	 * @param string $step_name
	 * @param migration_config_dto $config
	 * @return int
	 */
	public function get_total_records(string $step_name, migration_config_dto $config): int
	{
		try
		{
			$db = new xf_db_adapter($config);
			switch ($step_name)
			{
				case 'groups':
					return $db->get_count('xf_user_group');
				case 'users':
					return $db->get_count('xf_user');
				case 'group_memberships':
					return $db->get_count('xf_user_group_relation');
				case 'global_permissions':
					return $db->get_count('xf_permission_entry');
				case 'forums':
					return $db->get_count('xf_node');
				case 'node_permissions':
					return $db->get_count('xf_permission_entry_content');
				case 'topics':
					return $db->get_count('xf_thread');
				case 'posts':
					return $db->get_count('xf_post');
				case 'attachments':
					return (int)$db->fetch_one("SELECT COUNT(*) FROM `{$db->get_prefix()}attachment` WHERE content_type = 'post'");
				case 'avatars':
					return (int)$db->fetch_one("SELECT COUNT(*) FROM `{$db->get_prefix()}user` WHERE avatar_date > 0");
				case 'conversations':
				case 'pms':
					return $db->get_count('xf_conversation_master');
				case 'conversation_messages':
					return $db->get_count('xf_conversation_message');
				case 'conversation_attachments':
					return (int)$db->fetch_one("SELECT COUNT(*) FROM `{$db->get_prefix()}attachment` WHERE content_type = 'conversation_message'");
				case 'polls':
					return $db->get_count('xf_poll');
				case 'bans':
					return $db->get_count('xf_user_ban');
				case 'subscriptions':
					return $db->get_count('xf_thread_watch');
				default:
					return 0;
			}
		}
		catch (\Throwable $e)
		{
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
		$db = new xf_db_adapter($config);
		switch ($step_name)
		{
			case 'groups':
				return $db->fetch_keyset_batch('xf_user_group', 'user_group_id', $cursor, $batch_size);
			case 'users':
				return $db->fetch_keyset_batch('xf_user', 'user_id', $cursor, $batch_size);
			case 'forums':
				return $db->fetch_keyset_batch('xf_node', 'node_id', $cursor, $batch_size);
			case 'topics':
				return $db->fetch_keyset_batch('xf_thread', 'thread_id', $cursor, $batch_size);
			case 'posts':
				return $db->fetch_keyset_batch('xf_post', 'post_id', $cursor, $batch_size);
			case 'attachments':
				return $db->fetch_keyset_batch('xf_attachment', 'attachment_id', $cursor, $batch_size);
			default:
				return [];
		}
	}

	/**
	 * Get feature compatibility breakdown
	 *
	 * @return array
	 */
	public function get_feature_compatibility(): array
	{
		$adapter = new xf23_adapter(new class { public function __construct(){} });
		return $adapter->get_feature_compatibility();
	}
}
