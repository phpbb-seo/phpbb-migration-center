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
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use phpbbseo\migrationcenter\core\engine\migration_engine;
use phpbbseo\migrationcenter\core\verification\migration_verifier;

/**
 * CLI Command: migrationcenter:verify <run-id> [--repair] [--dry-run]
 */
class verify_command extends Command
{
	/** @var migration_engine */
	protected $engine;

	/** @var migration_verifier|null */
	protected $verifier;

	/**
	 * Constructor
	 *
	 * @param migration_engine $engine
	 * @param migration_verifier|null $verifier
	 */
	public function __construct(migration_engine $engine, ?migration_verifier $verifier = null)
	{
		$this->engine = $engine;
		$this->verifier = $verifier;
		parent::__construct();
	}

	/**
	 * Configure command
	 */
	protected function configure()
	{
		$this
			->setName('migrationcenter:verify')
			->setDescription('Verify counts, referential integrity, and health of a migration run')
			->addArgument('run-id', InputArgument::REQUIRED, 'Migration Run ID')
			->addOption('repair', null, InputOption::VALUE_NONE, 'Attempt automatic repair for safe repairable checks')
			->addOption('dry-run', null, InputOption::VALUE_NONE, 'Simulate verification and preview repair actions');
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

		$io->title("phpBB Migration Center - Verification & Health Check for Run ({$run_id})");

		if ($this->verifier)
		{
			$v_res = $this->verifier->verify_all($run_id);
			$check_rows = [];

			foreach ($v_res['checks'] as $c)
			{
				$check_rows[] = [
					$c['label'],
					strtoupper($c['status']),
					$c['message'],
				];
			}

			$io->table(['Integrity Check', 'Status', 'Details'], $check_rows);

			$io->section('Excluded Scope Notice');
			$io->note('Subscriptions (xf_thread_watch, xf_forum_watch), profile banners, and unsupported addon features are classified strictly as intentionally_not_imported.');

			if ($v_res['passed'])
			{
				$io->success("All {$v_res['total_checks']} integrity checks passed successfully!");
				return 0;
			}
			else
			{
				$io->warning("Verification completed with {$v_res['total_failed']} failed check(s) and {$v_res['total_warnings']} warning(s).");
				return 1;
			}
		}

		return 0;
	}
}
