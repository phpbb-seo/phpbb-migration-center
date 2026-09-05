<?php
/**
 * phpBB Migration Center Extension
 *
 * @copyright (c) 2026 phpBB SEO Team
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace phpbbseo\migrationcenter\core\engine;

use phpbbseo\migrationcenter\core\contract\id_mapper_interface;
use phpbbseo\migrationcenter\core\contract\target_writer_interface;
use phpbbseo\migrationcenter\core\dto\migration_config_dto;
use phpbbseo\migrationcenter\core\dto\run_state_dto;
use phpbbseo\migrationcenter\core\dto\step_result_dto;
use phpbbseo\migrationcenter\core\state\lock_manager;
use phpbbseo\migrationcenter\core\state\state_manager;

/**
 * Shared Migration Engine for ACP & CLI
 */
class migration_engine
{
	/** @var provider_registry */
	protected $provider_registry;

	/** @var step_registry */
	protected $step_registry;

	/** @var state_manager */
	protected $state_manager;

	/** @var lock_manager */
	protected $lock_manager;

	/** @var id_mapper_interface */
	protected $id_mapper;

	/** @var target_writer_interface */
	protected $target_writer;

	/**
	 * Constructor
	 *
	 * @param provider_registry $provider_registry
	 * @param step_registry $step_registry
	 * @param state_manager $state_manager
	 * @param lock_manager $lock_manager
	 * @param id_mapper_interface $id_mapper
	 * @param target_writer_interface $target_writer
	 */
	public function __construct(
		provider_registry $provider_registry,
		step_registry $step_registry,
		state_manager $state_manager,
		lock_manager $lock_manager,
		id_mapper_interface $id_mapper,
		target_writer_interface $target_writer
	) {
		$this->provider_registry = $provider_registry;
		$this->step_registry = $step_registry;
		$this->state_manager = $state_manager;
		$this->lock_manager = $lock_manager;
		$this->id_mapper = $id_mapper;
		$this->target_writer = $target_writer;
	}

	/**
	 * Get state manager instance
	 *
	 * @return state_manager
	 */
	public function get_state_manager(): state_manager
	{
		return $this->state_manager;
	}

	/**
	 * Get lock manager instance
	 *
	 * @return lock_manager
	 */
	public function get_lock_manager(): lock_manager
	{
		return $this->lock_manager;
	}

	/**
	 * Start a new migration run
	 *
	 * @param string $source_system
	 * @param migration_config_dto $config
	 * @return run_state_dto
	 * @throws \RuntimeException
	 */
	public function start_run(string $source_system, migration_config_dto $config): run_state_dto
	{
		$provider = $this->provider_registry->get($source_system);
		if (!$provider)
		{
			throw new \RuntimeException("Unknown source provider: {$source_system}");
		}

		$source_version = $provider->detect_version($config);

		// Enforce single non-terminal run policy per board
		$active_run = $this->state_manager->get_active_non_terminal_run();
		if ($active_run !== null)
		{
			throw new \RuntimeException("A migration run is already active or incomplete (Run ID: {$active_run->run_id} - status: {$active_run->status}). Only one active migration run is permitted per board. Please resume, cancel, or roll back the current migration before starting a new run.");
		}

		$run_id = $this->state_manager->generate_run_id();

		// Check and acquire lock
		$lock_name = 'migration_' . $source_system;
		if (!$this->lock_manager->acquire($lock_name, $run_id))
		{
			throw new \RuntimeException("Another migration is already running for source: {$source_system}");
		}

		$run_state = $this->state_manager->create_run($run_id, $source_system, $source_version, $config);

		// Resolve ordered steps
		$requested_steps = !empty($config->selected_steps) ? $config->selected_steps : $provider->get_supported_steps();
		$ordered_steps = $this->step_registry->resolve_order($requested_steps);

		$steps_init = [];
		foreach ($ordered_steps as $order => $step_name)
		{
			$total = $provider->get_total_records($step_name, $config);
			$max_id = $provider->get_max_source_id($step_name, $config);
			$steps_init[] = [
				'step_name'     => $step_name,
				'step_order'    => $order + 1,
				'total_records' => $total,
				'max_source_id' => $max_id,
			];
		}

		$this->state_manager->init_steps($run_id, $steps_init);
		$this->state_manager->update_run_status($run_id, 'ready', $ordered_steps[0] ?? '');

		// Release initial creation lock so it is not held idly before batches start
		$this->lock_manager->release($lock_name, $run_id);

		$run_state = $this->state_manager->get_run($run_id);
		return $run_state;
	}

	/**
	 * Execute next batch for a run
	 *
	 * @param string $run_id
	 * @param string|int $worker_type_or_batch_size 'ajax', 'cli', or integer batch size
	 * @param int $batch_size_override
	 * @param string $worker_token
	 * @return array Batch execution result
	 * @throws \RuntimeException
	 */
	public function execute_next_batch(string $run_id, $worker_type_or_batch_size = 'ajax', int $batch_size_override = 0, string $worker_token = ''): array
	{
		$worker_type = 'ajax';
		if (is_int($worker_type_or_batch_size))
		{
			$batch_size_override = $worker_type_or_batch_size;
			$worker_type = 'ajax';
		}
		else if (is_string($worker_type_or_batch_size) && !empty($worker_type_or_batch_size))
		{
			$worker_type = $worker_type_or_batch_size;
		}

		$run = $this->state_manager->get_run($run_id);
		if (!$run)
		{
			throw new \RuntimeException("Migration run not found: {$run_id}");
		}

		if (in_array($run->status, ['abandoned', 'rolled_back', 'finalized'], true))
		{
			throw new \RuntimeException("Cannot execute batch for terminal run {$run_id} (status: {$run->status})");
		}

		if ($run->status === 'paused' || $run->status === 'completed')
		{
			return [
				'success'           => false,
				'run_id'            => $run_id,
				'status'            => $run->status,
				'run_status'        => $run->status,
				'worker_type'       => $worker_type,
				'step_name'         => $run->current_step ?: '',
				'stage_key'         => $run->current_step ?: '',
				'step_status'       => $run->status,
				'stage_status'      => $run->status,
				'cursor'            => '0',
				'processed'         => 0,
				'created'           => 0,
				'reused'            => 0,
				'updated'           => 0,
				'skipped'           => 0,
				'failed'            => 0,
				'total'             => 0,
				'percentage'        => 0.0,
				'rate'              => 0.0,
				'eta'               => '00:00:00',
				'heartbeat_at'      => time(),
				'message'           => "Run is currently {$run->status}",
				'next_action'       => 'none',
				'error_code'        => null,
				'completed'         => ($run->status === 'completed'),
				'stage_completed'   => false,
				'awaiting_approval' => false,
			];
		}

		if (in_array($run->status, ['awaiting_approval', 'stage_completed', 'stage_completed_with_warnings', 'stage_failed'], true))
		{
			$stage_report = $this->state_manager->get_stage_report($run_id);
			return [
				'success'           => true,
				'run_id'            => $run_id,
				'status'            => $run->status,
				'run_status'        => $run->status,
				'worker_type'       => $worker_type,
				'step_name'         => $run->current_step ?: '',
				'stage_key'         => $run->current_step ?: '',
				'step_status'       => 'completed',
				'stage_status'      => 'completed',
				'cursor'            => (string)($stage_report['current_cursor'] ?? '0'),
				'processed'         => (int)($stage_report['processed'] ?? 0),
				'created'           => (int)($stage_report['created'] ?? 0),
				'reused'            => (int)($stage_report['reused'] ?? 0),
				'updated'           => (int)($stage_report['updated'] ?? 0),
				'skipped'           => (int)($stage_report['skipped'] ?? 0),
				'failed'            => (int)($stage_report['permanently_failed'] ?? 0),
				'total'             => (int)($stage_report['source_total'] ?? 0),
				'percentage'        => 100.0,
				'rate'              => (float)($stage_report['processing_rate'] ?? 0),
				'eta'               => '00:00:00',
				'heartbeat_at'      => time(),
				'message'           => 'Waiting for administrator approval before proceeding to the next stage.',
				'next_action'       => 'await_approval',
				'error_code'        => null,
				'stage_completed'   => true,
				'awaiting_approval' => true,
				'stage_report'      => $stage_report,
				'next_stage'        => $stage_report['next_stage'] ?? null,
				'completed'         => false,
			];
		}

		$lock_name = 'migration_' . $run->source_system;
		if (!$this->lock_manager->acquire($lock_name, $run_id, $worker_type, $worker_token))
		{
			$lock_info = $this->lock_manager->get_lock_info($lock_name);
			$holder = $lock_info['worker_type'] ?? 'another';
			throw new \RuntimeException("Migration lock is currently held by active {$holder} worker (Run ID: {$run_id})");
		}

		$provider = $this->provider_registry->get($run->source_system);
		if (!$provider)
		{
			throw new \RuntimeException("Provider not found for system: {$run->source_system}");
		}

		$config = migration_config_dto::from_array($run->options);
		$steps = $this->state_manager->get_steps($run_id);

		// Find current incomplete step (in canonical sequence)
		$current_step_row = null;
		foreach ($steps as $step_row)
		{
			if ($step_row['status'] !== 'completed' && $step_row['status'] !== 'skipped')
			{
				$current_step_row = $step_row;
				break;
			}
		}

		// If all steps completed, finalize run
		if (!$current_step_row)
		{
			$this->target_writer->finalize(array_keys($steps));
			$this->state_manager->update_run_status($run_id, 'completed');
			$this->lock_manager->release($lock_name, $run_id);

			return [
				'success'           => true,
				'run_id'            => $run_id,
				'run_status'        => 'completed',
				'worker_type'       => $worker_type,
				'stage_key'         => $run->current_step ?: '',
				'stage_status'      => 'completed',
				'cursor'            => '0',
				'processed'         => 0,
				'created'           => 0,
				'reused'            => 0,
				'updated'           => 0,
				'skipped'           => 0,
				'failed'            => 0,
				'total'             => 0,
				'percentage'        => 100.0,
				'rate'              => 0.0,
				'eta'               => '00:00:00',
				'heartbeat_at'      => time(),
				'message'           => 'Migration completed successfully.',
				'next_action'       => 'finalize',
				'error_code'        => null,
				'stage_completed'   => true,
				'awaiting_approval' => false,
				'completed'         => true,
			];
		}

		$step_name = $current_step_row['step_name'];
		$step = $this->step_registry->get($step_name, $run->source_system);
		if (!$step)
		{
			throw new \RuntimeException("Step handler not registered for: {$step_name}");
		}

		$batch_size = $batch_size_override > 0 ? $batch_size_override : ($config->batch_size ?: 500);
		$cursor = $current_step_row['current_cursor'];

		// Mark step running and run running
		$this->state_manager->update_step($run_id, $step_name, 'running');
		$this->state_manager->update_run_status($run_id, 'running', $step_name);

		// Process batch
		$result = $step->process_batch($run_id, $cursor, $batch_size, $config, $provider, $this->target_writer);

		// Log errors if any
		foreach ($result->errors as $err)
		{
			$this->state_manager->log_error(
				$run_id,
				$step_name,
				$err['code'],
				$err['message'],
				$err['severity'],
				$step_name,
				$err['source_id'],
				$err['context']
			);
		}

		$new_status = $result->is_completed ? 'completed' : 'running';
		$this->state_manager->update_step(
			$run_id,
			$step_name,
			$new_status,
			$result->next_cursor,
			$result->imported_count,
			$result->skipped_count,
			$result->failed_count,
			$result->metrics
		);

		// Refresh updated step row for exact counters
		$updated_step = $this->state_manager->get_step($run_id, $step_name);
		$tot = (int)($updated_step['total_records'] ?? 0);
		$imp = (int)($updated_step['imported_records'] ?? 0);
		$skp = (int)($updated_step['skipped_records'] ?? 0);
		$fld = (int)($updated_step['failed_records'] ?? 0);
		$proc = $imp + $skp + $fld;
		$pct = ($tot > 0) ? min(100.0, round(($proc / $tot) * 100, 1)) : ($result->is_completed ? 100.0 : 0.0);

		$started_at = (int)($updated_step['started_at'] ?? time());
		$active_elapsed = max(1, time() - $started_at);
		$rate = round($proc / $active_elapsed, 1);
		$remaining = max(0, $tot - $proc);
		$eta_formatted = ($rate > 0 && $remaining > 0) ? sprintf('%02d:%02d:%02d', ($remaining/$rate/3600), ($remaining/$rate/60%60), ($remaining/$rate%60)) : '00:00:00';

		// If stage completed, complete stage, generate report, and halt for approval
		if ($result->is_completed)
		{
			$stage_report = $this->state_manager->complete_stage($run_id, $step_name, $result->metrics);
			$this->lock_manager->release($lock_name, $run_id);

			return [
				'success'           => true,
				'run_id'            => $run_id,
				'run_status'        => 'awaiting_approval',
				'worker_type'       => $worker_type,
				'stage_key'         => $step_name,
				'stage_status'      => 'completed',
				'cursor'            => (string)$result->next_cursor,
				'processed'         => $proc,
				'created'           => (int)($stage_report['created'] ?? $imp),
				'reused'            => (int)($stage_report['reused'] ?? 0),
				'updated'           => (int)($stage_report['updated'] ?? 0),
				'skipped'           => $skp,
				'failed'            => $fld,
				'total'             => $tot,
				'percentage'        => 100.0,
				'rate'              => $rate,
				'eta'               => '00:00:00',
				'heartbeat_at'      => time(),
				'message'           => "Stage {$step_name} completed. Waiting for administrator approval.",
				'next_action'       => 'await_approval',
				'error_code'        => null,
				'stage_completed'   => true,
				'awaiting_approval' => true,
				'stage_report'      => $stage_report,
				'next_stage'        => $stage_report['next_stage'],
				'completed'         => ($stage_report['next_stage'] === null),
				'read_count'        => $result->read_count,
				'imported_count'    => $result->imported_count,
				'skipped_count'     => $result->skipped_count,
				'failed_count'      => $result->failed_count,
				'next_cursor'       => $result->next_cursor,
				'step_name'         => $step_name,
				'step_status'       => 'completed',
			];
		}

		$step_stats = !empty($updated_step['stats_json']) ? (json_decode($updated_step['stats_json'], true) ?: []) : [];
		$reused_c = (int)($step_stats['reused'] ?? 0);
		$created_c = isset($step_stats['created']) ? (int)$step_stats['created'] : max(0, $imp - $reused_c);
		$updated_c = (int)($step_stats['updated'] ?? 0);

		return [
			'success'           => true,
			'run_id'            => $run_id,
			'run_status'        => 'running',
			'worker_type'       => $worker_type,
			'stage_key'         => $step_name,
			'stage_status'      => 'running',
			'cursor'            => (string)$result->next_cursor,
			'processed'         => $proc,
			'created'           => $created_c,
			'reused'            => $reused_c,
			'updated'           => $updated_c,
			'skipped'           => $skp,
			'failed'            => $fld,
			'total'             => $tot,
			'percentage'        => $pct,
			'rate'              => $rate,
			'eta'               => $eta_formatted,
			'heartbeat_at'      => time(),
			'message'           => "Processing {$step_name}...",
			'next_action'       => 'continue',
			'error_code'        => null,
			'stage_completed'   => false,
			'awaiting_approval' => false,
			'completed'         => false,
			'read_count'        => $result->read_count,
			'imported_count'    => $result->imported_count,
			'skipped_count'     => $result->skipped_count,
			'failed_count'      => $result->failed_count,
			'next_cursor'       => $result->next_cursor,
			'step_name'         => $step_name,
			'step_status'       => 'running',
		];
	}

	/**
	 * Explicitly approve continuation to the next stage
	 *
	 * @param string $run_id
	 * @param string $expected_next_stage
	 * @return void
	 */
	public function approve_stage_continuation(string $run_id, string $expected_next_stage): void
	{
		$run = $this->state_manager->get_run($run_id);
		if (!$run)
		{
			throw new \RuntimeException("Migration run not found: {$run_id}");
		}

		$this->state_manager->approve_next_stage($run_id, $expected_next_stage);
	}

	/**
	 * Pause migration run
	 *
	 * @param string $run_id
	 * @return bool
	 */
	public function pause_run(string $run_id): bool
	{
		$run = $this->state_manager->get_run($run_id);
		if (!$run || $run->status === 'completed')
		{
			return false;
		}

		$this->state_manager->update_run_status($run_id, 'paused');
		$this->lock_manager->release('migration_' . $run->source_system, $run_id);
		return true;
	}

	/**
	 * Resume migration run
	 *
	 * @param string $run_id
	 * @return bool
	 */
	public function resume_run(string $run_id): bool
	{
		$run = $this->state_manager->get_run($run_id);
		if (!$run || $run->status === 'completed')
		{
			return false;
		}

		$this->state_manager->update_run_status($run_id, 'running');
		return true;
	}

	/**
	 * Cancel / Abort migration run without deleting imported records
	 *
	 * @param string $run_id
	 * @return bool
	 */
	public function cancel_run(string $run_id): bool
	{
		$run = $this->state_manager->get_run($run_id);
		if (!$run || in_array($run->status, ['completed', 'rolled_back', 'abandoned'], true))
		{
			return false;
		}

		$this->state_manager->update_run_status($run_id, 'cancelled');
		$this->lock_manager->force_release('migration_' . $run->source_system);
		return true;
	}

	/**
	 * Prepare migration run for CLI execution
	 *
	 * @param string $run_id
	 * @param string $expected_stage
	 * @return void
	 */
	public function prepare_cli_run(string $run_id, string $expected_stage = ''): void
	{
		$this->state_manager->prepare_cli_run($run_id, $expected_stage);
	}

	/**
	 * Cancel CLI preparation and return to ready state
	 *
	 * @param string $run_id
	 * @return void
	 */
	public function cancel_cli_prep(string $run_id): void
	{
		$run = $this->state_manager->get_run($run_id);
		if ($run)
		{
			$lock_name = 'migration_' . $run->source_system;
			if ($this->lock_manager->is_locked($lock_name))
			{
				throw new \RuntimeException("Cannot cancel CLI preparation: worker lock is currently held.");
			}
		}
		$this->state_manager->cancel_cli_prep($run_id);
	}

	/**
	 * Get full status of run
	 *
	 * @param string $run_id
	 * @return array
	 */
	public function get_status(string $run_id): array
	{
		$run = $this->state_manager->get_run($run_id);
		if (!$run)
		{
			return ['error' => 'Run not found'];
		}

		$steps = $this->state_manager->get_steps($run_id);
		return [
			'run'   => $run,
			'steps' => $steps,
		];
	}
}
