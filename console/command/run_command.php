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
			->addOption('db-pass', null, InputOption::VALUE_OPTIONAL, 'Database password', '')
			->addOption('db-prefix', null, InputOption::VALUE_OPTIONAL, 'Database prefix', 'xf_')
			->addOption('batch-size', null, InputOption::VALUE_REQUIRED, 'Batch size for processing', 500)
			->addOption('step', null, InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED, 'Select specific steps to run')
			->addOption('dry-run', null, InputOption::VALUE_NONE, 'Perform a dry-run without writing data to target')
			->addOption('limit', null, InputOption::VALUE_OPTIONAL, 'Limit total records for testing')
			->addOption('min-id', null, InputOption::VALUE_OPTIONAL, 'Minimum source ID to process')
			->addOption('max-id', null, InputOption::VALUE_OPTIONAL, 'Maximum source ID to process')
			->addOption('dup-user', null, InputOption::VALUE_OPTIONAL, 'Duplicate username policy (rename, skip, merge, stop)', 'rename')
			->addOption('dup-email', null, InputOption::VALUE_OPTIONAL, 'Duplicate email policy (keep, replace_placeholder, skip, stop)', 'keep');
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
		$config->db_password = (string)$input->getOption('db-pass');
		$config->db_prefix = (string)$input->getOption('db-prefix');
		$config->batch_size = $batch_size;
		$config->dry_run = $dry_run;
		$config->duplicate_username_policy = (string)$input->getOption('dup-user');
		$config->duplicate_email_policy = (string)$input->getOption('dup-email');

		if (!empty($config->source_path) && empty($config->db_name))
		{
			$detected = \phpbbseo\migrationcenter\source\xenforo\config\xf_config_detector::detect_from_path($config->source_path);
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
			$total_imported = 0;
			$total_skipped = 0;
			$total_failed = 0;

			while (!$is_complete)
			{
				$batch_res = $this->engine->execute_next_batch($run->run_id, $batch_size);
				if ($batch_res['completed'])
				{
					$is_complete = true;
					$io->success($batch_res['message'] ?? 'Migration completed.');
				}
				else
				{
					$total_imported += $batch_res['imported_count'];
					$total_skipped += $batch_res['skipped_count'];
					$total_failed += $batch_res['failed_count'];

					$io->writeln(sprintf(
						"Step [%s]: Read %d, Imported %d, Skipped %d, Failed %d (Cursor: %s)",
						$batch_res['step_name'],
						$batch_res['read_count'],
						$batch_res['imported_count'],
						$batch_res['skipped_count'],
						$batch_res['failed_count'],
						$batch_res['next_cursor']
					));
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
