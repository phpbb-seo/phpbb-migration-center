<?php
/**
 * phpBB Migration Center Extension
 *
 * @copyright (c) 2026 phpBB SEO Team
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace phpbbseo\migrationcenter\source\xenforo\step;

use phpbbseo\migrationcenter\core\contract\step_interface;
use phpbbseo\migrationcenter\core\contract\source_provider_interface;
use phpbbseo\migrationcenter\core\contract\target_writer_interface;
use phpbbseo\migrationcenter\core\dto\migration_config_dto;
use phpbbseo\migrationcenter\core\dto\step_result_dto;
use phpbbseo\migrationcenter\source\xenforo\permission\xf_permission_reader;
use phpbbseo\migrationcenter\source\xenforo\permission\xf_permission_translator;

/**
 * XenForo Global Permissions Translation Step
 */
class global_permissions_step implements step_interface
{
	/** @var xf_permission_translator */
	protected $translator;

	/**
	 * Constructor
	 *
	 * @param xf_permission_translator|null $translator
	 */
	public function __construct(?xf_permission_translator $translator = null)
	{
		$this->translator = $translator ?: new xf_permission_translator();
	}

	/**
	 * Unique step identifier
	 *
	 * @return string
	 */
	public function get_name(): string
	{
		return 'global_permissions';
	}

	/**
	 * Human-readable step label
	 *
	 * @return string
	 */
	public function get_label(): string
	{
		return 'Global Permissions';
	}

	/**
	 * Step dependencies
	 *
	 * @return array
	 */
	public function get_dependencies(): array
	{
		return ['groups'];
	}

	/**
	 * Process global permissions translation
	 *
	 * @param string $run_id
	 * @param string|int $cursor
	 * @param int $batch_size
	 * @param migration_config_dto $config
	 * @param source_provider_interface $provider
	 * @param target_writer_interface $writer
	 * @return step_result_dto
	 */
	public function process_batch(
		string $run_id,
		$cursor,
		int $batch_size,
		migration_config_dto $config,
		source_provider_interface $provider,
		target_writer_interface $writer
	): step_result_dto {
		$result = new step_result_dto('global_permissions');
		$reader = new xf_permission_reader($config);

		$raw_entries = $reader->read_global_group_permissions();
		$result->read_count = count($raw_entries);

		if (empty($raw_entries))
		{
			$result->is_completed = true;
			$result->next_cursor = $cursor;
			return $result;
		}

		$to_write = [];
		$exact_count = 0;
		$reduced_count = 0;
		$unsupported_count = 0;
		$deferred_count = 0;

		foreach ($raw_entries as $entry)
		{
			$trans = $this->translator->translate_entry($entry);

			if ($trans['status'] === 'deferred_node')
			{
				$deferred_count++;
				continue;
			}

			if ($trans['status'] === 'mapped' && !empty($trans['phpbb_option']))
			{
				$to_write[] = [
					'group_source_id' => (int)$entry['user_group_id'],
					'phpbb_option'    => $trans['phpbb_option'],
					'auth_setting'    => $trans['auth_setting'],
					'confidence'      => $trans['confidence'],
				];

				if ($trans['confidence'] === 'exact')
				{
					$exact_count++;
				}
				else
				{
					$reduced_count++;
				}
			}
			else
			{
				$unsupported_count++;
				$result->add_error(
					'UNSUPPORTED_PERMISSION',
					"Permission '{$trans['perm_key']}' for Group {$entry['user_group_id']} cannot be mapped directly to phpBB global ACL. (No access granted).",
					'info',
					$entry['user_group_id']
				);
			}
		}

		$result->next_cursor = 1;
		$result->is_completed = true;

		// Dry-run handling
		if ($config->dry_run)
		{
			$result->imported_count = count($to_write);
			return $result;
		}

		$write_options = [
			'run_id'        => $run_id,
			'source_system' => $config->source_system ?: 'xenforo',
		];

		$write_results = $writer->write_global_permissions($to_write, $write_options);

		// Account for evaluated unsupported and deferred rules in processed count
		$result->skipped_count += ($unsupported_count + $deferred_count);

		foreach ($write_results as $idx => $res)
		{
			if ($res['status'] === 'success')
			{
				$result->imported_count++;
			}
			else if ($res['status'] === 'skipped')
			{
				$result->skipped_count++;
			}
			else
			{
				$result->failed_count++;
			}
		}

		return $result;
	}
}
