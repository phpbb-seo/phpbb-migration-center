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
 * CLI Command: migrationcenter:status <run-id>
 */
class status_command extends Command
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
			->setName('migrationcenter:status')
			->setDescription('Check status and progress of a migration run')
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

		$info = $this->engine->get_status($run_id);
		if (isset($info['error']))
		{
			$io->error($info['error']);
			return 1;
		}

		$run = $info['run'];
		$steps = $info['steps'];

		$io->title("Migration Run Status: {$run_id}");
		$io->writeln("Source: <info>{$run->source_system}</info> (v{$run->source_version})");
		$io->writeln("Overall Status: <info>{$run->status}</info>");
		$io->writeln("Current Step: <info>{$run->current_step}</info>");

		$step_rows = [];
		foreach ($steps as $st)
		{
			$step_rows[] = [
				$st['step_name'],
				strtoupper($st['status']),
				$st['total_records'],
				$st['imported_records'],
				$st['skipped_records'],
				$st['failed_records'],
				$st['current_cursor'],
			];
		}

		$io->table(
			['Step', 'Status', 'Total', 'Imported', 'Skipped', 'Failed', 'Cursor'],
			$step_rows
		);

		return 0;
	}
}
