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
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use phpbbseo\migrationcenter\core\engine\migration_engine;
use phpbbseo\migrationcenter\core\dto\migration_config_dto;

/**
 * CLI Command: migrationcenter:run <source>
 */
class run_command extends Command
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
			->setName('migrationcenter:run')
			->setDescription('Execute a migration run from a source system')
			->addArgument('source', InputArgument::REQUIRED, 'Source system (e.g. xenforo)')
			->addOption('path', null, InputOption::VALUE_OPTIONAL, 'Path to source forum root directory')
			->addOption('db-host', null, InputOption::VALUE_OPTIONAL, 'Database host', 'localhost')
			->addOption('db-port', null, InputOption::VALUE_OPTIONAL, 'Database port', 3306)
			->addOption('db-name', null, InputOption::VALUE_OPTIONAL, 'Database name', '')
			->addOption('db-user', null, InputOption::VALUE_OPTIONAL, 'Database username', '')
			->addOption('db-pass', null, InputOption::VALUE_OPTIONAL, 'Database password (discouraged, prefer --db-pass-env)')
			->addOption('db-pass-env', null, InputOption::VALUE_OPTIONAL, 'Environment variable name holding database password')
			->addOption('db-prefix', null, InputOption::VALUE_OPTIONAL, 'Database prefix', 'xf_')
			->addOption('batch-size', null, InputOption::VALUE_REQUIRED, 'Batch size for processing', 500)
			->addOption('step', null, InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED, 'Select specific steps to run')
			->addOption('dry-run', null, InputOption::VALUE_NONE, 'Perform a dry-run without writing data to target')
			->addOption('limit', null, InputOption::VALUE_OPTIONAL, 'Limit total records for testing')
			->addOption('min-id', null, InputOption::VALUE_OPTIONAL, 'Minimum source ID to process')
			->addOption('max-id', null, InputOption::VALUE_OPTIONAL, 'Maximum source ID to process')
			->addOption('dup-user', null, InputOption::VALUE_OPTIONAL, 'Duplicate username policy (rename, skip, merge, stop)', 'rename')
			->addOption('dup-email', null, InputOption::VALUE_OPTIONAL, 'Duplicate email policy (keep, replace_placeholder, skip, stop)', 'keep')
			->addOption('auto-approve', null, InputOption::VALUE_NONE, 'Automatically approve stage transitions and run all stages to completion');
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
		$source = $input->getArgument('source');
		$batch_size = (int)$input->getOption('batch-size');
		$steps = (array)$input->getOption('step');
		$dry_run = (bool)$input->getOption('dry-run');

		$io->title("phpBB Migration Center - Starting Migration ({$source})" . ($dry_run ? ' [DRY-RUN]' : ''));

		$config = new migration_config_dto();
		$config->source_system = $source;
		$config->source_path = (string)$input->getOption('path');
		$config->db_host = (string)$input->getOption('db-host');
		$config->db_port = (int)$input->getOption('db-port');
		$config->db_name = (string)$input->getOption('db-name');
		$config->db_user = (string)$input->getOption('db-user');
		$config->batch_size = $batch_size;
		$is_vb = in_array(strtolower($source), ['vbulletin', 'vbulletin3', 'vbulletin4', 'vb3', 'vb4'], true);
		$config->db_prefix = ($is_vb && $input->getOption('db-prefix') === 'xf_') ? '' : (string)$input->getOption('db-prefix');
		$config->dry_run   = (bool)$input->getOption('dry-run');
		$config->duplicate_username_policy = (string)$input->getOption('dup-user');
		$config->duplicate_email_policy = (string)$input->getOption('dup-email');

		// Resolve database password securely
		$pass_env = (string)$input->getOption('db-pass-env');
		$raw_pass = (string)$input->getOption('db-pass');

		if (!empty($pass_env))
		{
			$env_val = getenv($pass_env);
			if ($env_val === false || $env_val === '')
			{
				$io->error("Environment variable '{$pass_env}' is not set or empty.");
				return 1;
			}
			$config->db_password = $env_val;
		}
		else if (!empty($raw_pass))
		{
			$config->db_password = $raw_pass;
			$io->warning('Passing plaintext passwords directly via --db-pass is discouraged as command line arguments may be logged in shell history. Prefer using --db-pass-env=VARIABLE_NAME or automatic configuration detection.');
		}
		else if (!empty($config->source_path) && empty($config->db_password))
		{
			if ($is_vb)
			{
				$detected = \phpbbseo\migrationcenter\source\vbulletin\config\vb_config_detector::detect_from_path($config->source_path);
			}
			else
			{
				$detected = \phpbbseo\migrationcenter\source\xenforo\config\xf_config_detector::detect_from_path($config->source_path);
			}

			if ($detected)
			{
				if (empty($config->db_host)) $config->db_host = $detected->db_host;
				if (empty($config->db_port) || $config->db_port === 3306) $config->db_port = $detected->db_port;
				if (empty($config->db_name)) $config->db_name = $detected->db_name;
				if (empty($config->db_user)) $config->db_user = $detected->db_user;
				if (empty($config->db_password)) $config->db_password = $detected->db_password;
				if (empty($config->db_prefix) || $config->db_prefix === 'xf_') $config->db_prefix = $detected->db_prefix;
			}
		}

		if (!empty($steps))
		{
			$config->selected_steps = $steps;
		}

		try
		{
			$run = $this->engine->start_run($source, $config);
			$io->success("Migration run created with Run ID: {$run->run_id}");

			// Process continuous batches
			$is_complete = false;
			$total_processed = 0;
			$worker_token = 'cli_' . getmypid() . '_' . substr(md5(uniqid('', true)), 0, 8);

			while (!$is_complete)
			{
				$batch_res = $this->engine->execute_next_batch($run->run_id, 'cli', $batch_size, $worker_token);

				if (!$batch_res['success'])
				{
					$io->error("Batch execution error: " . ($batch_res['error'] ?? 'Unknown error'));
					return 1;
				}

				$total_processed = $batch_res['processed'] ?? 0;
				$stage_name = ucfirst(str_replace('_', ' ', $batch_res['stage_key'] ?? $batch_res['step_name'] ?? 'groups'));

				$io->writeln(sprintf(
					"[%s] Processed: %d / %d | Created: %d | Reused: %d | Skipped: %d | Failed: %d",
					$stage_name,
					$total_processed,
					$batch_res['total'] ?? $total_processed,
					$batch_res['created'] ?? 0,
					$batch_res['reused'] ?? 0,
					$batch_res['skipped'] ?? 0,
					$batch_res['failed'] ?? 0
				));

				if (!empty($batch_res['completed']))
				{
					$is_complete = true;
					$io->success("Migration run {$run->run_id} completed successfully.");
				}
				else if (!empty($batch_res['stage_completed']) || !empty($batch_res['awaiting_approval']))
				{
					$next_stage = $batch_res['next_stage'] ?? null;
					$auto_approve = (bool)$input->getOption('auto-approve');

					if ($auto_approve && !empty($next_stage))
					{
						$io->success("Stage [{$stage_name}] completed successfully.");
						$this->engine->approve_stage_continuation($run->run_id, $next_stage);
						$io->section("Starting stage: " . ucfirst(str_replace('_', ' ', $next_stage)));
					}
					else if (empty($next_stage))
					{
						$is_complete = true;
						$io->success("All stages completed successfully.");
					}
					else
					{
						$is_complete = true;
						$io->success("Stage [{$stage_name}] completed successfully. Run state: awaiting_approval.");
					}
				}
			}

			return 0;
		}
		catch (\Throwable $e)
		{
			$io->error("Migration failed: " . $e->getMessage());
			return 1;
		}
	}
}
