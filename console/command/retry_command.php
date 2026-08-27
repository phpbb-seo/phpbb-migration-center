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
 * CLI Command: migrationcenter:retry <run-id>
 */
class retry_command extends Command
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
			->setName('migrationcenter:retry')
			->setDescription('Retry failed items or resume a failed migration run')
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
		$run_id = $input->getArgument('run-id');

		$io->title("phpBB Migration Center - Retrying Run ({$run_id})");
		try
		{
			$this->engine->resume_run($run_id);
			$io->success("Retrying remaining batches...");

			$is_complete = false;
			while (!$is_complete)
			{
				$batch_res = $this->engine->execute_next_batch($run_id);
				if ($batch_res['completed'])
				{
					$is_complete = true;
					$io->success($batch_res['message'] ?? 'Migration completed.');
				}
				else
				{
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
		catch (\Exception $e)
		{
			$io->error("Retry failed: " . $e->getMessage());
			return 1;
		}
	}
}
