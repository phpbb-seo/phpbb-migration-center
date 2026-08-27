<?php
/**
 * phpBB Migration Center Extension
 *
 * @copyright (c) 2026 phpBB SEO Team
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace phpbbseo\migrationcenter\migrations;

/**
 * Migration to install migration framework tables
 */
class install_schema extends \phpbb\db\migration\migration
{
	/**
	 * Define migration dependencies
	 *
	 * @return array
	 */
	public static function depends_on()
	{
		return array('\phpbb\db\migration\data\v330\v330');
	}



	/**
	 * Schema updates
	 *
	 * @return array
	 */
	public function update_schema()
	{
		return array(
			'add_tables' => array(
				// Table: migration_runs
				$this->table_prefix . 'migration_runs' => array(
					'COLUMNS' => array(
						'run_id'         => array('VCHAR:36', ''),
						'source_system'  => array('VCHAR:50', ''),
						'source_version' => array('VCHAR:50', ''),
						'status'         => array('VCHAR:30', 'pending'),
						'current_step'   => array('VCHAR:100', ''),
						'options_json'   => array('MTEXT', ''),
						'stats_json'     => array('MTEXT', ''),
						'started_at'     => array('TIMESTAMP', 0),
						'paused_at'      => array('TIMESTAMP', 0),
						'completed_at'   => array('TIMESTAMP', 0),
						'created_at'     => array('TIMESTAMP', 0),
						'updated_at'     => array('TIMESTAMP', 0),
					),
					'PRIMARY_KEY' => 'run_id',
					'KEYS' => array(
						'status_idx'     => array('INDEX', 'status'),
						'created_at_idx' => array('INDEX', 'created_at'),
					),
				),

				// Table: migration_steps
				$this->table_prefix . 'migration_steps' => array(
					'COLUMNS' => array(
						'run_id'           => array('VCHAR:36', ''),
						'step_name'        => array('VCHAR:100', ''),
						'status'           => array('VCHAR:30', 'pending'),
						'current_cursor'   => array('VCHAR:255', '0'),
						'max_source_id'    => array('VCHAR:255', '0'),
						'total_records'    => array('UINT:10', 0),
						'imported_records' => array('UINT:10', 0),
						'skipped_records'  => array('UINT:10', 0),
						'failed_records'   => array('UINT:10', 0),
						'step_order'       => array('UINT:4', 0),
						'started_at'       => array('TIMESTAMP', 0),
						'completed_at'     => array('TIMESTAMP', 0),
						'stats_json'       => array('MTEXT', ''),
					),
					'PRIMARY_KEY' => array('run_id', 'step_name'),
					'KEYS' => array(
						'run_order_idx'  => array('INDEX', array('run_id', 'step_order')),
						'status_idx'     => array('INDEX', 'status'),
					),
				),

				// Table: migration_id_map
				$this->table_prefix . 'migration_id_map' => array(
					'COLUMNS' => array(
						'id'            => array('UINT:10', null, 'auto_increment'),
						'run_id'        => array('VCHAR:36', ''),
						'source_system' => array('VCHAR:50', ''),
						'content_type'  => array('VCHAR:50', ''),
						'source_id'     => array('VCHAR:100', ''),
						'target_id'     => array('VCHAR:100', ''),
						'status'        => array('VCHAR:30', 'mapped'),
						'checksum'      => array('VCHAR:64', ''),
						'metadata_json' => array('MTEXT', ''),
						'created_at'    => array('TIMESTAMP', 0),
						'updated_at'    => array('TIMESTAMP', 0),
					),
					'PRIMARY_KEY' => 'id',
					'KEYS' => array(
						'source_lookup' => array('INDEX', array('source_system', 'content_type', 'source_id')),
						'run_lookup'    => array('INDEX', array('run_id', 'content_type', 'source_id')),
						'target_lookup' => array('INDEX', array('source_system', 'content_type', 'target_id')),
					),
				),

				// Table: migration_errors
				$this->table_prefix . 'migration_errors' => array(
					'COLUMNS' => array(
						'id'           => array('UINT:10', null, 'auto_increment'),
						'run_id'       => array('VCHAR:36', ''),
						'step_name'    => array('VCHAR:100', ''),
						'content_type' => array('VCHAR:50', ''),
						'source_id'    => array('VCHAR:100', ''),
						'severity'     => array('VCHAR:20', 'error'),
						'error_code'   => array('VCHAR:50', ''),
						'message'      => array('TEXT', ''),
						'context_json' => array('MTEXT', ''),
						'created_at'   => array('TIMESTAMP', 0),
					),
					'PRIMARY_KEY' => 'id',
					'KEYS' => array(
						'run_step_idx' => array('INDEX', array('run_id', 'step_name')),
						'severity_idx' => array('INDEX', 'severity'),
					),
				),

				// Table: migration_settings
				$this->table_prefix . 'migration_settings' => array(
					'COLUMNS' => array(
						'setting_name'  => array('VCHAR:100', ''),
						'setting_value' => array('MTEXT', ''),
						'updated_at'    => array('TIMESTAMP', 0),
					),
					'PRIMARY_KEY' => 'setting_name',
				),

				// Table: migration_locks
				$this->table_prefix . 'migration_locks' => array(
					'COLUMNS' => array(
						'lock_name'    => array('VCHAR:100', ''),
						'run_id'       => array('VCHAR:36', ''),
						'locked_at'    => array('TIMESTAMP', 0),
						'heartbeat_at' => array('TIMESTAMP', 0),
						'worker_id'    => array('VCHAR:100', ''),
					),
					'PRIMARY_KEY' => 'lock_name',
					'KEYS' => array(
						'heartbeat_idx' => array('INDEX', 'heartbeat_at'),
					),
				),
			),
		);
	}

	/**
	 * Data updates
	 *
	 * @return array
	 */
	public function update_data()
	{
		return array(
			array('permission.add', array('a_migrationcenter', true)),
			array('permission.permission_set', array('ROLE_ADMIN_FULL', 'a_migrationcenter')),
		);
	}

	/**
	 * Revert schema updates
	 *
	 * @return array
	 */
	public function revert_schema()
	{
		return array(
			'drop_tables' => array(
				$this->table_prefix . 'migration_locks',
				$this->table_prefix . 'migration_settings',
				$this->table_prefix . 'migration_errors',
				$this->table_prefix . 'migration_id_map',
				$this->table_prefix . 'migration_steps',
				$this->table_prefix . 'migration_runs',
			),
		);
	}
}
