<?php
/**
 * phpBB Migration Center Extension
 *
 * @copyright (c) 2026 phpBB SEO Team
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace phpbbseo\migrationcenter\console\command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use phpbbseo\migrationcenter\core\engine\migration_engine;

/**
 * CLI Command: migrationcenter:resume <run-id>
 */
class resume_command extends Command
{
	/** @var migration_engine */
	protected $engine;

	/**
	 * Constructor
	 *
	 * @param migration_engine $engine
	 */
	public function __construct(migration_engine $engine)
	{
		$this->engine = $engine;
		parent::__construct();
	}

	/**
	 * Configure command
	 */
	protected function configure()
	{
		$this
			->setName('migrationcenter:resume')
			->setDescription('Resume a paused or interrupted migration run')
			->addArgument('run-id', InputArgument::REQUIRED, 'Migration Run ID');
	}

	/**
	 * Execute command
	 *
	 * @param InputInterface $input
	 * @param OutputInterface $output
	 * @return int
	 */
	protected function execute(InputInterface $input, OutputInterface $output)
	{
		$io = new SymfonyStyle($input, $output);
		$run_id = trim((string)$input->getArgument('run-id'));
		$state_manager = $this->engine->get_state_manager();
		$lock_manager = $this->engine->get_lock_manager();

		try
		{
			$run = $state_manager->get_run($run_id);
			if (!$run)
			{
				$io->error("Migration run {$run_id} not found.");
				return 1;
			}

			$current_stage = $run->current_step ?: 'groups';
			$stage_name = ucfirst(str_replace('_', ' ', $current_stage));

			// Output standard startup banner immediately
			$io->writeln("Migration Center CLI Worker");
			$io->writeln("Run: {$run_id}");
			$io->writeln("Stage: {$stage_name}");
			$io->writeln("Validating migration...");

			if (in_array($run->status, ['abandoned', 'rolled_back', 'finalized', 'cancelled', 'failed'], true))
			{
				$errMsg = "Cannot resume terminal run (status: {$run->status}). This migration is no longer running.";
				$state_manager->set_startup_error($run_id, $errMsg, 'TERMINAL_RUN');
				$io->error($errMsg);
				return 1;
			}

			if (in_array($run->status, ['awaiting_approval', 'stage_completed', 'stage_completed_with_warnings', 'stage_failed'], true))
			{
				$stage_report = $state_manager->get_stage_report($run_id);
				$next_stage = $stage_report['next_stage'] ?? 'next stage';
				$next_stage_title = ucfirst(str_replace('_', ' ', $next_stage));
				$errMsg = sprintf(
					"Migration %s is currently awaiting administrator approval before starting %s.\nPlease approve the stage transition in the ACP interface before running CLI.",
					$run_id,
					$next_stage_title
				);
				$state_manager->set_startup_error($run_id, $errMsg, 'AWAITING_APPROVAL');
				$io->warning($errMsg);
				return 0;
			}

			$lock_name = 'migration_' . $run->source_system;
			if ($lock_manager->is_locked($lock_name))
			{
				$lock_info = $lock_manager->get_lock_info($lock_name);
				$holder = $lock_info['worker_type'] ?? 'another';
				$errMsg = "Migration lock is currently held by active {$holder} worker (Run ID: {$run_id})";
				$state_manager->set_startup_error($run_id, $errMsg, 'LOCK_BUSY');
				$io->error($errMsg);
				return 1;
			}

			$io->writeln("Lock acquired.");
			$io->writeln("Source connection verified.");

			// Get initial count
			$steps = $state_manager->get_steps($run_id);
			$initial_total = 0;
			$initial_imported = 0;
			foreach ($steps as $s)
			{
				if ($s['step_name'] === $current_stage)
				{
					$initial_total = (int)$s['total_records'];
					$initial_imported = (int)$s['imported_records'] + (int)$s['skipped_records'] + (int)$s['failed_records'];
					break;
				}
			}

			$io->writeln("Starting {$stage_name}: {$initial_imported} / {$initial_total}");
			$io->newLine();

			$worker_token = 'cli_' . getmypid() . '_' . substr(md5(uniqid('', true)), 0, 8);
			$start_time = time();

			// Clear startup error since CLI is actively starting
			$state_manager->set_startup_error($run_id, '');

			while (true)
			{
				$res = $this->engine->execute_next_batch($run_id, 'cli', 0, $worker_token);

				$elapsed_seconds = max(1, time() - $start_time);
				$elapsed_fmt = sprintf('%02d:%02d:%02d', ($elapsed_seconds / 3600), ($elapsed_seconds / 60 % 60), $elapsed_seconds % 60);

				$cur_stage_name = ucfirst(str_replace('_', ' ', $res['stage_key'] ?: $current_stage));
				$pct = number_format((float)($res['percentage'] ?? 0), 1);
				$rate = number_format((float)($res['rate'] ?? 0), 1);
				$eta = $res['eta'] ?? '00:00:00';

				$io->writeln(sprintf(
					"[%s] %d / %d (%s%%)",
					$cur_stage_name,
					$res['processed'] ?? 0,
					$res['total'] ?? 0,
					$pct
				));
				$io->writeln(sprintf(
					"Created: %d | Reused: %d | Updated: %d | Skipped: %d | Failed: %d",
					$res['created'] ?? 0,
					$res['reused'] ?? 0,
					$res['updated'] ?? 0,
					$res['skipped'] ?? 0,
					$res['failed'] ?? 0
				));
				$io->writeln(sprintf(
					"Rate: %s records/sec | Elapsed: %s | ETA: %s",
					$rate,
					$elapsed_fmt,
					$eta
				));
				$io->writeln("Heartbeat: active");
				$io->newLine();

				if (!empty($res['stage_completed']))
				{
					$io->writeln("{$cur_stage_name} completed successfully.");
					$io->newLine();
					$io->writeln("Processed: " . (int)($res['processed'] ?? 0));
					$io->writeln("Created: " . (int)($res['created'] ?? 0));
					$io->writeln("Reused: " . (int)($res['reused'] ?? 0));
					$io->writeln("Updated: " . (int)($res['updated'] ?? 0));
					$io->writeln("Skipped: " . (int)($res['skipped'] ?? 0));
					$io->writeln("Failed: " . (int)($res['failed'] ?? 0));
					$io->newLine();

					if (!empty($res['completed']))
					{
						$io->success("All migration stages completed successfully! You may finalize the migration in ACP.");
					}
					else
					{
						$next_stage = !empty($res['next_stage']) ? ucfirst(str_replace('_', ' ', $res['next_stage'])) : 'Next Stage';
						$io->writeln("Migration paused at the stage checkpoint.");
						$io->writeln("Return to ACP and approve the next stage: {$next_stage}.");
					}

					$io->writeln("Exit code: 0");
					break;
				}

				if (!empty($res['completed']))
				{
					$io->success("Migration completed successfully.");
					$io->writeln("Exit code: 0");
					break;
				}
			}

			return 0;
		}
		catch (\Exception $e)
		{
			$state_manager->set_startup_error($run_id, $e->getMessage(), 'CLI_EXCEPTION');
			$io->error("CLI execution error: " . $e->getMessage());
			return 1;
		}
	}
}
