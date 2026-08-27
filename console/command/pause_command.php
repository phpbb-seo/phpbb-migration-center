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
 * CLI Command: migrationcenter:pause <run-id>
 */
class pause_command extends Command
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
			->setName('migrationcenter:pause')
			->setDescription('Pause an active migration run')
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

		$success = $this->engine->pause_run($run_id);
		if ($success)
		{
			$io->success("Migration run {$run_id} has been paused.");
			return 0;
		}
		else
		{
			$io->error("Could not pause run {$run_id}.");
			return 1;
		}
	}
}
