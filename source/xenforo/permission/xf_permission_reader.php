<?php
/**
 * phpBB Migration Center Extension
 *
 * @copyright (c) 2026 phpBB SEO Team
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace phpbbseo\migrationcenter\source\xenforo\permission;

use phpbbseo\migrationcenter\core\dto\migration_config_dto;
use phpbbseo\migrationcenter\source\xenforo\adapter\xf_db_adapter;

/**
 * XenForo Permission Reader
 */
class xf_permission_reader
{
	/** @var xf_db_adapter */
	protected $db_adapter;

	/**
	 * Constructor
	 *
	 * @param migration_config_dto $config
	 */
	public function __construct(migration_config_dto $config)
	{
		$this->db_adapter = new xf_db_adapter($config);
	}

	/**
	 * Read all global permission entries for user groups
	 *
	 * @return array
	 */
	public function read_global_group_permissions(): array
	{
		$prefix = $this->db_adapter->get_prefix();
		$sql = "SELECT 
					permission_entry_id,
					user_group_id,
					user_id,
					permission_group_id,
					permission_id,
					permission_value,
					permission_value_int
				FROM `{$prefix}permission_entry`
				WHERE user_group_id > 0
				ORDER BY user_group_id ASC, permission_group_id ASC, permission_id ASC";

		return $this->db_adapter->fetch_all($sql);
	}

	/**
	 * Read all node/forum-specific permission entries (for Phase 4B preparation)
	 *
	 * @return array
	 */
	public function read_node_permissions(): array
	{
		$prefix = $this->db_adapter->get_prefix();
		$sql = "SELECT 
					permission_entry_id,
					content_type,
					content_id,
					user_group_id,
					user_id,
					permission_group_id,
					permission_id,
					permission_value,
					permission_value_int
				FROM `{$prefix}permission_entry_content`
				WHERE content_type = 'node'
				ORDER BY content_id ASC, user_group_id ASC";

		return $this->db_adapter->fetch_all($sql);
	}
}
