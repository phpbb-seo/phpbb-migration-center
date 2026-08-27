<?php
/**
 * phpBB Migration Center Extension
 *
 * @copyright (c) 2026 phpBB SEO Team
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace phpbbseo\migrationcenter\core\state;

use phpbb\db\driver\driver_interface;
use phpbbseo\migrationcenter\core\dto\migration_config_dto;
use phpbbseo\migrationcenter\core\dto\run_state_dto;

/**
 * Migration State Manager
 */
class state_manager
{
	public const STATUS_PENDING          = 'pending';
	public const STATUS_READY            = 'ready';
	public const STATUS_AWAITING_WORKER  = 'awaiting_worker';
	public const STATUS_RUNNING          = 'running';
	public const STATUS_PAUSED           = 'paused';
	public const STATUS_INTERRUPTED      = 'interrupted';
	public const STATUS_AWAITING_APPROVAL = 'awaiting_approval';
	public const STATUS_STAGE_COMPLETED  = 'stage_completed';
	public const STATUS_STAGE_COMPLETED_WITH_WARNINGS = 'stage_completed_with_warnings';
	public const STATUS_STAGE_FAILED     = 'stage_failed';
	public const STATUS_COMPLETED        = 'completed';
	public const STATUS_FINALIZED        = 'finalized';
	public const STATUS_CANCELLED        = 'cancelled';
	public const STATUS_FAILED           = 'failed';
	public const STATUS_ROLLED_BACK      = 'rolled_back';
	public const STATUS_ABANDONED        = 'abandoned';

	public const NON_TERMINAL_STATUSES = [
		'pending',
		'ready',
		'awaiting_worker',
		'running',
		'paused',
		'interrupted',
		'awaiting_approval',
		'stage_completed',
		'stage_completed_with_warnings',
		'stage_failed',
		'completed',
	];

	public const TERMINAL_STATUSES = [
		'finalized',
		'cancelled',
		'failed',
		'rolled_back',
		'abandoned',
	];

	/** @var driver_interface */
	protected $db;

	/** @var string */
	protected $table_runs;

	/** @var string */
	protected $table_steps;

	/** @var string */
	protected $table_errors;

	/** @var string */
	protected $table_settings;

	/** @var string */
	protected $table_id_map;

	/**
	 * Constructor
	 *
	 * @param driver_interface $db
	 * @param string $table_prefix
	 */
	public function __construct(driver_interface $db, string $table_prefix)
	{
		$this->db = $db;
		$this->table_runs = $table_prefix . 'migration_runs';
		$this->table_steps = $table_prefix . 'migration_steps';
		$this->table_errors = $table_prefix . 'migration_errors';
		$this->table_settings = $table_prefix . 'migration_settings';
		$this->table_id_map = $table_prefix . 'migration_id_map';
	}

	/**
	 * Generate a unique run ID (UUID v4 format)
	 *
	 * @return string
	 */
	public function generate_run_id(): string
	{
		$data = random_bytes(16);
		$data[6] = chr(ord($data[6]) & 0x0f | 0x40); // set version to 0100
		$data[8] = chr(ord($data[8]) & 0x3f | 0x80); // set bits 6-7 to 10
		return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
	}

	/**
	 * Create a new migration run
	 *
	 * @param string $run_id
	 * @param string $source_system
	 * @param string $source_version
	 * @param migration_config_dto $config
	 * @return run_state_dto
	 */
	public function create_run(string $run_id, string $source_system, string $source_version, migration_config_dto $config): run_state_dto
	{
		$now = time();
		$options = $config->to_array(false); // safe, no passwords

		$data = [
			'run_id'         => $run_id,
			'source_system'  => $source_system,
			'source_version' => $source_version,
			'status'         => 'pending',
			'current_step'   => '',
			'options_json'   => json_encode($options, JSON_UNESCAPED_UNICODE),
			'stats_json'     => json_encode([], JSON_UNESCAPED_UNICODE),
			'started_at'     => 0,
			'paused_at'      => 0,
			'completed_at'   => 0,
			'created_at'     => $now,
			'updated_at'     => $now,
		];

		$sql = 'INSERT INTO ' . $this->table_runs . ' ' . $this->db->sql_build_array('INSERT', $data);
		$this->db->sql_query($sql);

		return run_state_dto::from_row($data);
	}

	/**
	 * Get run state by run ID
	 *
	 * @param string $run_id
	 * @return run_state_dto|null
	 */
	public function get_run(string $run_id): ?run_state_dto
	{
		$sql = 'SELECT * FROM ' . $this->table_runs . '
			WHERE run_id = ' . "'" . $this->db->sql_escape($run_id) . "'";
		$result = $this->db->sql_query($sql);
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		if ($row)
		{
			return run_state_dto::from_row($row);
		}

		return null;
	}

	/**
	 * Update run status
	 *
	 * @param string $run_id
	 * @param string $status
	 * @param string $current_step
	 * @param array $stats
	 * @return void
	 */
	public function update_run_status(string $run_id, string $status, string $current_step = '', array $stats = []): void
	{
		$now = time();
		$data = [
			'status'     => $status,
			'updated_at' => $now,
		];

		if ($current_step !== '')
		{
			$data['current_step'] = $current_step;
		}

		if (!empty($stats))
		{
			$data['stats_json'] = json_encode($stats, JSON_UNESCAPED_UNICODE);
		}

		if ($status === 'running' && empty($data['started_at']))
		{
			$data['started_at'] = $now;
		}
		else if ($status === 'paused')
		{
			$data['paused_at'] = $now;
		}
		else if (in_array($status, ['completed', 'failed', 'rolled_back', 'abandoned'], true))
		{
			$data['completed_at'] = $now;
		}

		$sql = 'UPDATE ' . $this->table_runs . '
			SET ' . $this->db->sql_build_array('UPDATE', $data) . '
			WHERE run_id = ' . "'" . $this->db->sql_escape($run_id) . "'";
		$this->db->sql_query($sql);
	}

	/**
	 * Update run stats metadata
	 *
	 * @param string $run_id
	 * @param array $stats
	 * @return void
	 */
	public function update_run_stats(string $run_id, array $stats): void
	{
		$data = [
			'stats_json' => json_encode($stats, JSON_UNESCAPED_UNICODE),
			'updated_at' => time(),
		];
		$sql = 'UPDATE ' . $this->table_runs . '
			SET ' . $this->db->sql_build_array('UPDATE', $data) . '
			WHERE run_id = ' . "'" . $this->db->sql_escape($run_id) . "'";
		$this->db->sql_query($sql);
	}

	/**
	 * Update run options configuration
	 *
	 * @param string $run_id
	 * @param array $options
	 * @return void
	 */
	public function update_run_options(string $run_id, array $options): void
	{
		$data = [
			'options_json' => json_encode($options, JSON_UNESCAPED_UNICODE),
			'updated_at'   => time(),
		];
		$sql = 'UPDATE ' . $this->table_runs . '
			SET ' . $this->db->sql_build_array('UPDATE', $data) . '
			WHERE run_id = ' . "'" . $this->db->sql_escape($run_id) . "'";
		$this->db->sql_query($sql);
	}

	/**
	 * Initialize steps for a run
	 *
	 * @param string $run_id
	 * @param array $steps Array of ['step_name' => string, 'step_order' => int, 'total_records' => int, 'max_source_id' => string|int]
	 * @return void
	 */
	public function init_steps(string $run_id, array $steps): void
	{
		$rows = [];
		foreach ($steps as $order => $step)
		{
			$rows[] = [
				'run_id'           => $run_id,
				'step_name'        => (string)$step['step_name'],
				'status'           => 'pending',
				'current_cursor'   => '0',
				'max_source_id'    => (string)($step['max_source_id'] ?? '0'),
				'total_records'    => (int)($step['total_records'] ?? 0),
				'imported_records' => 0,
				'skipped_records'  => 0,
				'failed_records'   => 0,
				'step_order'       => (int)($step['step_order'] ?? ($order + 1)),
				'started_at'       => 0,
				'completed_at'     => 0,
				'stats_json'       => json_encode([], JSON_UNESCAPED_UNICODE),
			];
		}

		if (!empty($rows))
		{
			$this->db->sql_multi_insert($this->table_steps, $rows);
		}
	}

	/**
	 * Get steps for a run
	 *
	 * @param string $run_id
	 * @return array
	 */
	public function get_steps(string $run_id): array
	{
		$sql = 'SELECT * FROM ' . $this->table_steps . '
			WHERE run_id = ' . "'" . $this->db->sql_escape($run_id) . "'
			ORDER BY step_order ASC";
		$result = $this->db->sql_query($sql);
		$steps = [];
		while ($row = $this->db->sql_fetchrow($result))
		{
			$steps[$row['step_name']] = $row;
		}
		$this->db->sql_freeresult($result);
		return $steps;
	}

	/**
	 * Get a single step for a run
	 *
	 * @param string $run_id
	 * @param string $step_name
	 * @return array|null
	 */
	public function get_step(string $run_id, string $step_name): ?array
	{
		$sql = 'SELECT * FROM ' . $this->table_steps . '
			WHERE run_id = ' . "'" . $this->db->sql_escape($run_id) . "'
				AND step_name = '" . $this->db->sql_escape($step_name) . "'";
		$result = $this->db->sql_query($sql);
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);
		return $row ?: null;
	}

	/**
	 * Update step progress
	 *
	 * @param string $run_id
	 * @param string $step_name
	 * @param string $status
	 * @param string|int $cursor
	 * @param int $imported_delta
	 * @param int $skipped_delta
	 * @param int $failed_delta
	 * @param array $stats
	 * @return void
	 */
	public function update_step(
		string $run_id,
		string $step_name,
		string $status,
		$cursor = null,
		int $imported_delta = 0,
		int $skipped_delta = 0,
		int $failed_delta = 0,
		array $stats = []
	): void {
		$now = time();
		$sql = 'SELECT * FROM ' . $this->table_steps . '
			WHERE run_id = ' . "'" . $this->db->sql_escape($run_id) . "'
				AND step_name = '" . $this->db->sql_escape($step_name) . "'";
		$result = $this->db->sql_query($sql);
		$step = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		if (!$step)
		{
			return;
		}

		$data = [
			'status'           => $status,
			'imported_records' => (int)$step['imported_records'] + $imported_delta,
			'skipped_records'  => (int)$step['skipped_records'] + $skipped_delta,
			'failed_records'   => (int)$step['failed_records'] + $failed_delta,
		];

		if ($cursor !== null)
		{
			$data['current_cursor'] = (string)$cursor;
		}

		if ($status === 'running' && empty($step['started_at']))
		{
			$data['started_at'] = $now;
		}
		else if ($status === 'completed' || $status === 'failed')
		{
			$data['completed_at'] = $now;
		}

		if (!empty($stats))
		{
			$existing_stats = !empty($step['stats_json']) ? (json_decode($step['stats_json'], true) ?: []) : [];
			$data['stats_json'] = json_encode(array_merge($existing_stats, $stats), JSON_UNESCAPED_UNICODE);
		}

		$sql = 'UPDATE ' . $this->table_steps . '
			SET ' . $this->db->sql_build_array('UPDATE', $data) . '
			WHERE run_id = ' . "'" . $this->db->sql_escape($run_id) . "'
				AND step_name = '" . $this->db->sql_escape($step_name) . "'";
		$this->db->sql_query($sql);
	}

	/**
	 * Log an error/warning safely (sanitizing secrets)
	 *
	 * @param string $run_id
	 * @param string $step_name
	 * @param string $code
	 * @param string $message
	 * @param string $severity
	 * @param string $content_type
	 * @param string|int $source_id
	 * @param array $context
	 * @return void
	 */
	public function log_error(
		string $run_id,
		string $step_name,
		string $code,
		string $message,
		string $severity = 'error',
		string $content_type = '',
		$source_id = '',
		array $context = []
	): void {
		// Sanitize context: remove any passwords or sensitive credentials
		unset($context['password'], $context['db_password'], $context['hash'], $context['token']);

		$data = [
			'run_id'       => $run_id,
			'step_name'    => $step_name,
			'content_type' => $content_type,
			'source_id'    => (string)$source_id,
			'severity'     => $severity,
			'error_code'   => $code,
			'message'      => $message,
			'context_json' => !empty($context) ? json_encode($context, JSON_UNESCAPED_UNICODE) : '',
			'created_at'   => time(),
		];

		$sql = 'INSERT INTO ' . $this->table_errors . ' ' . $this->db->sql_build_array('INSERT', $data);
		$this->db->sql_query($sql);
	}

	public const STAGE_SEQUENCE = [
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
		'finalize_recounts',
		'search_index',
		'final_verify',
	];

	/**
	 * Get the next stage in the logical sequence
	 *
	 * @param string $current_stage
	 * @return string|null
	 */
	public function get_next_stage(string $current_stage): ?string
	{
		$idx = array_search($current_stage, self::STAGE_SEQUENCE, true);
		if ($idx !== false && isset(self::STAGE_SEQUENCE[$idx + 1]))
		{
			return self::STAGE_SEQUENCE[$idx + 1];
		}
		return null;
	}

	/**
	 * Complete a stage, build the reconciliation report, and pause for admin approval
	 *
	 * @param string $run_id
	 * @param string $stage_name
	 * @param array $extra_stats
	 * @return array The stage report
	 */
	public function complete_stage(string $run_id, string $stage_name, array $extra_stats = []): array
	{
		$steps = $this->get_steps($run_id);
		$step = $steps[$stage_name] ?? null;

		$sql = 'SELECT COUNT(*) as cnt FROM ' . $this->table_errors . '
			WHERE run_id = ' . "'" . $this->db->sql_escape($run_id) . "'
				AND step_name = '" . $this->db->sql_escape($stage_name) . "'
				AND severity = 'warning'";
		$res = $this->db->sql_query($sql);
		$warnings_count = (int)$this->db->sql_fetchfield('cnt');
		$this->db->sql_freeresult($res);

		$sql = 'SELECT COUNT(*) as cnt FROM ' . $this->table_errors . '
			WHERE run_id = ' . "'" . $this->db->sql_escape($run_id) . "'
				AND step_name = '" . $this->db->sql_escape($stage_name) . "'
				AND severity = 'error'";
		$res = $this->db->sql_query($sql);
		$errors_count = (int)$this->db->sql_fetchfield('cnt');
		$this->db->sql_freeresult($res);

		$sql = 'SELECT COUNT(*) as cnt FROM ' . $this->table_id_map . '
			WHERE run_id = ' . "'" . $this->db->sql_escape($run_id) . "'";
		$res = $this->db->sql_query($sql);
		$mappings_count = (int)$this->db->sql_fetchfield('cnt');
		$this->db->sql_freeresult($res);

		$imported = $step ? (int)$step['imported_records'] : 0;
		$skipped = $step ? (int)$step['skipped_records'] : 0;
		$failed = $step ? (int)$step['failed_records'] : 0;
		$total = $step ? (int)$step['total_records'] : 0;
		$cursor = $step ? (string)$step['current_cursor'] : '0';
		$started_at = $step && $step['started_at'] ? (int)$step['started_at'] : time();
		$completed_at = time();
		$elapsed = max(1, $completed_at - $started_at);

		$reused = isset($extra_stats['reused']) ? (int)$extra_stats['reused'] : 0;
		$created = isset($extra_stats['created']) ? (int)$extra_stats['created'] : max(0, $imported - $reused);
		$updated = isset($extra_stats['updated']) ? (int)$extra_stats['updated'] : 0;
		$permanently_failed = $failed;
		$retryable_failures = isset($extra_stats['retryable_failures']) ? (int)$extra_stats['retryable_failures'] : 0;

		$processed = $created + $reused + $updated + $skipped + $permanently_failed;
		$rate = round($processed / $elapsed, 2);

		$next_stage = $this->get_next_stage($stage_name);
		$stage_status = ($errors_count > 0 || ($step && $step['status'] === 'failed')) ? 'stage_failed' : (($warnings_count > 0 || $skipped > 0) ? 'stage_completed_with_warnings' : 'stage_completed');

		$report = [
			'stage_name'          => $stage_name,
			'stage_status'        => $stage_status,
			'source_total'        => $total,
			'processed'           => $processed,
			'created'             => $created,
			'reused'              => $reused,
			'updated'             => $updated,
			'skipped'             => $skipped,
			'warnings'            => $warnings_count,
			'permanently_failed'  => $permanently_failed,
			'retryable_failures'  => $retryable_failures,
			'elapsed_time'        => $elapsed,
			'processing_rate'     => $rate,
			'mapping_count'       => $mappings_count,
			'current_cursor'      => $cursor,
			'started_at'          => $started_at,
			'completed_at'        => $completed_at,
			'next_stage'          => $next_stage,
		];

		// Persist report in run stats_json
		$run = $this->get_run($run_id);
		$existing_stats = $run && !empty($run->stats) ? $run->stats : [];
		$stage_history = $existing_stats['stage_history'] ?? [];
		$stage_history[$stage_name] = $report;
		$existing_stats['stage_history'] = $stage_history;
		$existing_stats['last_completed_stage'] = $stage_name;
		$existing_stats['next_stage'] = $next_stage;
		$existing_stats['last_stage_report'] = $report;

		$run_status = ($stage_status === 'stage_failed') ? 'stage_failed' : 'awaiting_approval';
		$this->update_run_status($run_id, $run_status, $stage_name, $existing_stats);

		return $report;
	}

	/**
	 * Explicitly approve continuation to the next stage
	 *
	 * @param string $run_id
	 * @param string $expected_next_stage
	 * @return void
	 * @throws \RuntimeException
	 */
	public function approve_next_stage(string $run_id, string $expected_next_stage): void
	{
		$run = $this->get_run($run_id);
		if (!$run)
		{
			throw new \RuntimeException("Migration run {$run_id} not found");
		}

		if (!in_array($run->status, ['awaiting_approval', 'stage_completed', 'stage_completed_with_warnings', 'ready', 'paused'], true))
		{
			throw new \RuntimeException("Cannot approve stage transition from run status: {$run->status}");
		}

		// Update run to running next stage
		$this->update_run_status($run_id, 'running', $expected_next_stage);
	}

	/**
	 * Get the latest stage reconciliation report for a run
	 *
	 * @param string $run_id
	 * @param string $stage_name
	 * @return array|null
	 */
	public function get_stage_report(string $run_id, string $stage_name = ''): ?array
	{
		$sql = 'SELECT stats_json FROM ' . $this->table_runs . '
			WHERE run_id = ' . "'" . $this->db->sql_escape($run_id) . "'";
		$res = $this->db->sql_query($sql);
		$stats_json = (string)$this->db->sql_fetchfield('stats_json');
		$this->db->sql_freeresult($res);

		$stats = !empty($stats_json) ? json_decode($stats_json, true) : [];
		if ($stage_name !== '')
		{
			return $stats['stage_history'][$stage_name] ?? null;
		}

		return $stats['last_stage_report'] ?? null;
	}

	/**
	 * Prepare a run for CLI execution
	 * Transitions run status to awaiting_worker, sets execution_mode to cli,
	 * records preparation timestamp, but does NOT acquire a lock or mark steps running.
	 *
	 * @param string $run_id
	 * @param string $expected_stage
	 * @return void
	 * @throws \RuntimeException
	 */
	public function prepare_cli_run(string $run_id, string $expected_stage = ''): void
	{
		$run = $this->get_run($run_id);
		if (!$run)
		{
			throw new \RuntimeException("Migration run {$run_id} not found");
		}

		if (in_array($run->status, self::TERMINAL_STATUSES, true))
		{
			throw new \RuntimeException("Cannot prepare CLI execution for terminal run {$run_id} (status: {$run->status})");
		}

		$options = $run->options;
		$options['worker_mode'] = 'cli';
		if ($expected_stage !== '')
		{
			$options['expected_stage'] = $expected_stage;
		}

		$stats = $run->stats;
		$stats['cli_prepared_at'] = time();
		$stats['expected_stage'] = $expected_stage ?: ($run->current_step ?: 'groups');
		unset($stats['startup_error']);

		$now = time();
		$data = [
			'status'       => self::STATUS_AWAITING_WORKER,
			'options_json' => json_encode($options, JSON_UNESCAPED_UNICODE),
			'stats_json'   => json_encode($stats, JSON_UNESCAPED_UNICODE),
			'updated_at'   => $now,
		];

		if ($expected_stage !== '')
		{
			$data['current_step'] = $expected_stage;
		}

		$sql = 'UPDATE ' . $this->table_runs . '
			SET ' . $this->db->sql_build_array('UPDATE', $data) . '
			WHERE run_id = ' . "'" . $this->db->sql_escape($run_id) . "'";
		$this->db->sql_query($sql);
	}

	/**
	 * Cancel CLI preparation and revert run status to ready
	 *
	 * @param string $run_id
	 * @return void
	 * @throws \RuntimeException
	 */
	public function cancel_cli_prep(string $run_id): void
	{
		$run = $this->get_run($run_id);
		if (!$run)
		{
			throw new \RuntimeException("Migration run {$run_id} not found");
		}

		if ($run->status !== self::STATUS_AWAITING_WORKER)
		{
			throw new \RuntimeException("Cannot cancel CLI preparation: run is currently in status '{$run->status}' (expected 'awaiting_worker')");
		}

		$options = $run->options;
		$options['worker_mode'] = 'ajax';

		$stats = $run->stats;
		unset($stats['cli_prepared_at']);
		unset($stats['startup_error']);

		$now = time();
		$data = [
			'status'       => self::STATUS_READY,
			'options_json' => json_encode($options, JSON_UNESCAPED_UNICODE),
			'stats_json'   => json_encode($stats, JSON_UNESCAPED_UNICODE),
			'updated_at'   => $now,
		];

		$sql = 'UPDATE ' . $this->table_runs . '
			SET ' . $this->db->sql_build_array('UPDATE', $data) . '
			WHERE run_id = ' . "'" . $this->db->sql_escape($run_id) . "'";
		$this->db->sql_query($sql);
	}

	/**
	 * Set or clear sanitized startup error in run metadata
	 *
	 * @param string $run_id
	 * @param string $error_message
	 * @param string $error_code
	 * @return void
	 */
	public function set_startup_error(string $run_id, string $error_message = '', string $error_code = ''): void
	{
		$run = $this->get_run($run_id);
		if (!$run)
		{
			return;
		}

		$stats = $run->stats;
		if ($error_message === '')
		{
			unset($stats['startup_error']);
		}
		else
		{
			$stats['startup_error'] = [
				'message' => $error_message,
				'code'    => $error_code ?: 'STARTUP_ERROR',
				'time'    => time(),
			];
		}

		$data = [
			'stats_json' => json_encode($stats, JSON_UNESCAPED_UNICODE),
			'updated_at' => time(),
		];

		$sql = 'UPDATE ' . $this->table_runs . '
			SET ' . $this->db->sql_build_array('UPDATE', $data) . '
			WHERE run_id = ' . "'" . $this->db->sql_escape($run_id) . "'";
		$this->db->sql_query($sql);
	}

	/**
	 * SQL clause to exclude automated test runs from real ACP queries
	 */
	protected function get_test_run_exclusion_sql(): string
	{
		return "run_id NOT LIKE 'test_%' 
			AND run_id NOT LIKE 'fixture_%' 
			AND run_id NOT LIKE 'real_fixture_%' 
			AND run_id NOT LIKE 'fast_reset_%' 
			AND run_id NOT LIKE 'rollback_%'";
	}

	/**
	 * Get the current active non-terminal run, if any exists
	 *
	 * @return run_state_dto|null
	 */
	public function get_active_non_terminal_run(): ?run_state_dto
	{
		$sql = 'SELECT * FROM ' . $this->table_runs . '
			WHERE ' . $this->db->sql_in_set('status', self::NON_TERMINAL_STATUSES) . '
				AND ' . $this->get_test_run_exclusion_sql() . '
			ORDER BY created_at DESC';
		$result = $this->db->sql_query_limit($sql, 1);
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		return $row ? run_state_dto::from_row($row) : null;
	}

	/**
	 * Get terminal historical runs
	 *
	 * @param int $limit
	 * @return array
	 */
	public function get_terminal_runs(int $limit = 20): array
	{
		$sql = 'SELECT * FROM ' . $this->table_runs . '
			WHERE ' . $this->db->sql_in_set('status', self::TERMINAL_STATUSES) . '
				AND ' . $this->get_test_run_exclusion_sql() . '
			ORDER BY created_at DESC';
		$result = $this->db->sql_query_limit($sql, $limit);
		$runs = $this->db->sql_fetchrowset($result);
		$this->db->sql_freeresult($result);
		return $runs;
	}

	/**
	 * Get recent runs
	 *
	 * @param int $limit
	 * @return array
	 */
	public function get_recent_runs(int $limit = 10): array
	{
		$sql = 'SELECT * FROM ' . $this->table_runs . '
			WHERE ' . $this->get_test_run_exclusion_sql() . '
			ORDER BY created_at DESC';
		$result = $this->db->sql_query_limit($sql, $limit);
		$runs = $this->db->sql_fetchrowset($result);
		$this->db->sql_freeresult($result);
		return $runs;
	}
}
