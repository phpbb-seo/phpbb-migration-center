<?php
/**
 * phpBB Migration Center Extension
 *
 * @copyright (c) 2026 phpBB SEO Team
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace phpbbseo\migrationcenter\core\state;

use phpbb\db\driver\driver_interface;

/**
 * Migration Lock Manager with Stale-Lock Recovery
 */
class lock_manager
{
	/** @var driver_interface */
	protected $db;

	/** @var string */
	protected $table_name;

	/** @var int Stale lock timeout in seconds (default 300s = 5m) */
	protected $timeout;

	/**
	 * Constructor
	 *
	 * @param driver_interface $db
	 * @param string $table_prefix
	 * @param int $timeout
	 */
	public function __construct(driver_interface $db, string $table_prefix, int $timeout = 300)
	{
		$this->db = $db;
		$this->table_name = $table_prefix . 'migration_locks';
		$this->timeout = $timeout;
	}

	/**
	 * Attempt to acquire migration lock
	 *
	 * @param string $lock_name
	 * @param string $run_id
	 * @param string $worker_type 'ajax' or 'cli'
	 * @param string $worker_id
	 * @return bool True if acquired, False if locked by active worker
	 */
	public function acquire(string $lock_name, string $run_id, string $worker_type = 'ajax', string $worker_id = ''): bool
	{
		$now = time();
		$stale_threshold = $now - $this->timeout;
		$worker_token = $worker_id ?: ('worker_' . getmypid() . '_' . substr(md5(uniqid('', true)), 0, 8));
		$full_worker_id = $worker_type . ':' . $worker_token;

		// Check current lock status
		$sql = 'SELECT * FROM ' . $this->table_name . '
			WHERE lock_name = ' . "'" . $this->db->sql_escape($lock_name) . "'";
		$result = $this->db->sql_query($sql);
		$existing_lock = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		if (!$existing_lock)
		{
			// Try to insert new lock
			$data = [
				'lock_name'    => $lock_name,
				'run_id'       => $run_id,
				'locked_at'    => $now,
				'heartbeat_at' => $now,
				'worker_id'    => $full_worker_id,
			];
			try
			{
				$sql = 'INSERT INTO ' . $this->table_name . ' ' . $this->db->sql_build_array('INSERT', $data);
				$this->db->sql_query($sql);
				return true;
			}
			catch (\Exception $e)
			{
				return false;
			}
		}

		// Check if previous run is completed or failed
		$prev_run_status = '';
		if (!empty($existing_lock['run_id']))
		{
			$runs_table = str_replace('migration_locks', 'migration_runs', $this->table_name);
			$sql = 'SELECT status FROM ' . $runs_table . ' WHERE run_id = ' . "'" . $this->db->sql_escape($existing_lock['run_id']) . "'";
			$r_res = $this->db->sql_query($sql);
			$prev_run_status = (string)$this->db->sql_fetchfield('status');
			$this->db->sql_freeresult($r_res);
		}

		$is_stale = ((int)$existing_lock['heartbeat_at'] < $stale_threshold);
		$is_finished = in_array($prev_run_status, ['completed', 'finalized', 'rolled_back', 'abandoned', 'failed'], true);

		// If the lock is held by the EXACT same full worker_id, refresh heartbeat
		if ($existing_lock['worker_id'] === $full_worker_id && $existing_lock['run_id'] === $run_id)
		{
			$this->heartbeat($lock_name, $run_id);
			return true;
		}

		// If current worker heartbeat is active, refuse concurrent acquisition
		if (!$is_stale && !$is_finished)
		{
			return false;
		}

		// Stale or finished lock takeover
		$data = [
			'run_id'       => $run_id,
			'locked_at'    => $now,
			'heartbeat_at' => $now,
			'worker_id'    => $full_worker_id,
		];
		$sql = 'UPDATE ' . $this->table_name . '
			SET ' . $this->db->sql_build_array('UPDATE', $data) . '
			WHERE lock_name = ' . "'" . $this->db->sql_escape($lock_name) . "'";
		$this->db->sql_query($sql);
		return $this->db->sql_affectedrows() >= 0;
	}

	/**
	 * Send heartbeat to keep lock alive
	 *
	 * @param string $lock_name
	 * @param string $run_id
	 * @return bool
	 */
	public function heartbeat(string $lock_name, string $run_id): bool
	{
		$now = time();
		$sql = 'UPDATE ' . $this->table_name . '
			SET heartbeat_at = ' . $now . '
			WHERE lock_name = ' . "'" . $this->db->sql_escape($lock_name) . "'
				AND run_id = " . "'" . $this->db->sql_escape($run_id) . "'";
		$this->db->sql_query($sql);
		return $this->db->sql_affectedrows() > 0;
	}

	/**
	 * Release lock
	 *
	 * @param string $lock_name
	 * @param string $run_id
	 * @return bool
	 */
	public function release(string $lock_name, string $run_id): bool
	{
		$sql = 'DELETE FROM ' . $this->table_name . '
			WHERE lock_name = ' . "'" . $this->db->sql_escape($lock_name) . "'
				AND run_id = " . "'" . $this->db->sql_escape($run_id) . "'";
		$this->db->sql_query($sql);
		return $this->db->sql_affectedrows() > 0;
	}

	/**
	 * Force release lock regardless of run_id
	 *
	 * @param string $lock_name
	 * @return bool
	 */
	public function force_release(string $lock_name): bool
	{
		$sql = 'DELETE FROM ' . $this->table_name . '
			WHERE lock_name = ' . "'" . $this->db->sql_escape($lock_name) . "'";
		$this->db->sql_query($sql);
		return $this->db->sql_affectedrows() > 0;
	}

	/**
	 * Check if lock is active
	 *
	 * @param string $lock_name
	 * @return array|null Lock info if locked and active, null otherwise
	 */
	public function is_locked(string $lock_name): ?array
	{
		$stale_threshold = time() - $this->timeout;
		$sql = 'SELECT * FROM ' . $this->table_name . '
			WHERE lock_name = ' . "'" . $this->db->sql_escape($lock_name) . "'";
		$result = $this->db->sql_query($sql);
		$lock = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		if ($lock && (int)$lock['heartbeat_at'] >= $stale_threshold)
		{
			return $this->format_lock_info($lock);
		}

		return null;
	}

	/**
	 * Get detailed lock information regardless of stale status
	 *
	 * @param string $lock_name
	 * @return array|null
	 */
	public function get_lock_info(string $lock_name): ?array
	{
		$sql = 'SELECT * FROM ' . $this->table_name . '
			WHERE lock_name = ' . "'" . $this->db->sql_escape($lock_name) . "'";
		$result = $this->db->sql_query($sql);
		$lock = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		if ($lock)
		{
			return $this->format_lock_info($lock);
		}

		return null;
	}

	/**
	 * Format lock database row with worker_type and stale status
	 *
	 * @param array $lock
	 * @return array
	 */
	protected function format_lock_info(array $lock): array
	{
		$now = time();
		$heartbeat = (int)($lock['heartbeat_at'] ?? 0);
		$worker_id = (string)($lock['worker_id'] ?? '');
		$worker_type = 'unknown';

		if (strpos($worker_id, 'cli:') === 0 || $worker_id === 'cli')
		{
			$worker_type = 'cli';
		}
		else if (strpos($worker_id, 'ajax:') === 0 || $worker_id === 'ajax')
		{
			$worker_type = 'ajax';
		}

		$lock['worker_type'] = $worker_type;
		$lock['heartbeat_age'] = max(0, $now - $heartbeat);
		$lock['expires_at'] = $heartbeat + $this->timeout;
		$lock['is_stale'] = ($now - $heartbeat) > $this->timeout;

		return $lock;
	}
}
