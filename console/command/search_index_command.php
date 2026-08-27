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
use phpbbseo\migrationcenter\core\search\search_indexer;

/**
 * CLI Command: migrationcenter:search-index <run-id> [--batch-size=500] [--dry-run]
 */
class search_index_command extends Command
{
	/** @var search_indexer */
	protected $indexer;

	/**
	 * Constructor
	 *
	 * @param search_indexer $indexer
	 */
	public function __construct(search_indexer $indexer)
	{
		$this->indexer = $indexer;
		parent::__construct();
	}

	/**
	 * Configure command
	 */
	protected function configure()
	{
		$this
			->setName('migrationcenter:search-index')
			->setDescription('Incrementally index migration-owned posts into phpBB search backend')
			->addArgument('run-id', InputArgument::REQUIRED, 'Migration Run ID')
			->addOption('batch-size', null, InputOption::VALUE_OPTIONAL, 'Batch size for indexing', 500)
			->addOption('dry-run', null, InputOption::VALUE_NONE, 'Simulate indexing without altering search index');
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
		$batch_size = (int)$input->getOption('batch-size') ?: 500;
		$dry_run = $input->getOption('dry-run');

		$info = $this->indexer->get_backend_info();

		$io->title("phpBB Migration Center - Incremental Search Indexing" . ($dry_run ? ' [DRY-RUN]' : ''));
		$io->text("Detected Search Backend: {$info['name']} ({$info['class']})");

		$cursor = 0;
		$total_indexed = 0;
		$total_skipped = 0;
		$total_failed = 0;

		do {
			$res = $this->indexer->index_posts($run_id, $cursor, $batch_size, ['dry_run' => $dry_run]);
			$total_indexed += $res['indexed'];
			$total_skipped += $res['skipped'];
			$total_failed  += $res['failed'];
			$cursor         = $res['next_cursor'];

			$io->text("Indexed batch up to post ID {$cursor}... (Batch: {$res['indexed']} indexed, {$res['skipped']} skipped)");
		} while (!$res['is_completed']);

		$io->success("Search indexing completed: {$total_indexed} posts indexed, {$total_skipped} skipped, {$total_failed} failed.");

		return 0;
	}
}
