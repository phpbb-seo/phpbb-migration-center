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
use phpbbseo\migrationcenter\core\engine\provider_registry;
use phpbbseo\migrationcenter\core\dto\migration_config_dto;

/**
 * CLI Command: migrationcenter:check <source>
 */
class check_command extends Command
{
	/** @var provider_registry */
	protected $provider_registry;

	/**
	 * Constructor
	 *
	 * @param provider_registry $provider_registry
	 */
	public function __construct(provider_registry $provider_registry)
	{
		$this->provider_registry = $provider_registry;
		parent::__construct();
	}

	/**
	 * Configure command
	 */
	protected function configure()
	{
		$this
			->setName('migrationcenter:check')
			->setDescription('Run preflight validation checks for a source provider')
			->addArgument('source', InputArgument::REQUIRED, 'Source system (e.g. xenforo)')
			->addOption('path', null, InputOption::VALUE_OPTIONAL, 'Path to source forum root directory')
			->addOption('db-host', null, InputOption::VALUE_OPTIONAL, 'Database host', 'localhost')
			->addOption('db-port', null, InputOption::VALUE_OPTIONAL, 'Database port', 3306)
			->addOption('db-name', null, InputOption::VALUE_OPTIONAL, 'Database name', '')
			->addOption('db-user', null, InputOption::VALUE_OPTIONAL, 'Database username', '')
			->addOption('db-pass', null, InputOption::VALUE_OPTIONAL, 'Database password', '')
			->addOption('db-prefix', null, InputOption::VALUE_OPTIONAL, 'Database prefix', 'xf_');
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

		$provider = $this->provider_registry->get($source);
		if (!$provider)
		{
			$io->error("Unknown source provider: {$source}");
			return 1;
		}

		$io->title("phpBB Migration Center - Preflight Check ({$provider->get_title()})");

		$config = new migration_config_dto();
		$config->source_system = $source;
		$config->source_path = (string)$input->getOption('path');
		$config->db_host = (string)$input->getOption('db-host');
		$config->db_port = (int)$input->getOption('db-port');
		$config->db_name = (string)$input->getOption('db-name');
		$config->db_user = (string)$input->getOption('db-user');
		$config->db_password = (string)$input->getOption('db-pass');
		$config->db_prefix = (string)$input->getOption('db-prefix');

		$result = $provider->run_preflight($config);

		$rows = [];
		foreach ($result->items as $item)
		{
			$rows[] = [$item->label, strtoupper($item->status), $item->message];
		}

		$io->table(['Check Item', 'Status', 'Message'], $rows);

		if ($result->passed)
		{
			$io->success("Preflight checks passed for {$source}!");
			return 0;
		}
		else
		{
			$io->error("Preflight checks failed. Please resolve the errors above.");
			return 1;
		}
	}
}
