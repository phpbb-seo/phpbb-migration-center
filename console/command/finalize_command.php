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
use phpbbseo\migrationcenter\core\finalization\phpbb_finalizer;

/**
 * CLI Command: migrationcenter:finalize <run-id> [--dry-run] [--batch-size=...] [--strict]
 */
class finalize_command extends Command
{
	/** @var phpbb_finalizer */
	protected $finalizer;

	/**
	 * Constructor
	 *
	 * @param phpbb_finalizer $finalizer
	 */
	public function __construct(phpbb_finalizer $finalizer)
	{
		$this->finalizer = $finalizer;
		parent::__construct();
	}

	/**
	 * Configure command
	 */
	protected function configure()
	{
		$this
			->setName('migrationcenter:finalize')
			->setDescription('Finalize and synchronize topics, forums, users, attachments, and board statistics')
			->addArgument('run-id', InputArgument::REQUIRED, 'Migration Run ID')
			->addOption('dry-run', null, InputOption::VALUE_NONE, 'Preview finalization calculations without modifying target data')
			->addOption('batch-size', null, InputOption::VALUE_OPTIONAL, 'Batch size for keyset operations', 500)
			->addOption('strict', null, InputOption::VALUE_NONE, 'Enforce strict completion checks');
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
		$dry_run = $input->getOption('dry-run');

		$io->title("phpBB Migration Center - Finalization & Synchronization" . ($dry_run ? ' [DRY-RUN]' : ''));

		$results = $this->finalizer->run_all_finalizers($run_id, [
			'dry_run' => $dry_run,
		]);

		$io->section('Topic Synchronization');
		$io->text("Topics Finalized: " . ($results['topics']['topics_finalized'] ?? 0));

		$io->section('Forum Synchronization');
		$io->text("Forums Finalized: " . ($results['forums']['forums_finalized'] ?? 0));

		$io->section('User Synchronization');
		$io->text("Users Finalized: " . ($results['users']['users_finalized'] ?? 0));

		$io->section('Global Board Statistics');
		$io->listing([
			"Total Posts: " . ($results['stats']['num_posts'] ?? 0),
			"Total Topics: " . ($results['stats']['num_topics'] ?? 0),
			"Total Users: " . ($results['stats']['num_users'] ?? 0),
			"Newest User: " . ($results['stats']['newest_user'] ?? 'None'),
		]);

		$io->success('Board finalization completed successfully.');

		return 0;
	}
}
