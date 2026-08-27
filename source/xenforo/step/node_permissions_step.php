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
 * XenForo Node Permissions Translation Step
 */
class node_permissions_step implements step_interface
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
		return 'node_permissions';
	}

	/**
	 * Human-readable step label
	 *
	 * @return string
	 */
	public function get_label(): string
	{
		return 'Node Permissions';
	}

	/**
	 * Step dependencies
	 *
	 * @return array
	 */
	public function get_dependencies(): array
	{
		return ['groups', 'forums'];
	}

	/**
	 * Process node permissions translation batch
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
		$result = new step_result_dto('node_permissions');
		$reader = new xf_permission_reader($config);

		$raw_entries = $reader->read_node_permissions();
		$result->read_count = count($raw_entries);

		if (empty($raw_entries))
		{
			$result->is_completed = true;
			$result->next_cursor = $cursor;
			return $result;
		}

		$to_write = [];

		foreach ($raw_entries as $entry)
		{
			$node_id = (int)($entry['content_id'] ?? 0);
			$perm_group = (string)($entry['permission_group_id'] ?? '');
			$perm_id = (string)($entry['permission_id'] ?? '');
			$val_type = (string)($entry['permission_value'] ?? 'unset');
			$val_int = (int)($entry['permission_value_int'] ?? 0);

			// Map local forum capabilities
			$phpbb_opt = null;
			switch ($perm_group . '.' . $perm_id)
			{
				case 'forum.viewNode':
					$phpbb_opt = 'f_list';
					break;
				case 'forum.viewContent':
					$phpbb_opt = 'f_read';
					break;
				case 'forum.postThread':
					$phpbb_opt = 'f_post';
					break;
				case 'forum.postReply':
					$phpbb_opt = 'f_reply';
					break;
				case 'forum.uploadAttachment':
					$phpbb_opt = 'f_attach';
					break;
				case 'forum.votePoll':
					$phpbb_opt = 'f_vote';
					break;
				case 'forum.createPoll':
					$phpbb_opt = 'f_poll';
					break;
				case 'forum.approveUnapprove':
					$phpbb_opt = 'm_approve';
					break;
				case 'forum.deleteAnyPost':
					$phpbb_opt = 'm_delete';
					break;
				case 'forum.editAnyPost':
					$phpbb_opt = 'm_edit';
					break;
				case 'forum.lockUnlockThread':
					$phpbb_opt = 'm_lock';
					break;
				case 'forum.stickUnstickThread':
					$phpbb_opt = 'f_sticky';
					break;
			}

			if (!$phpbb_opt)
			{
				$result->add_error(
					'UNSUPPORTED_NODE_PERMISSION',
					"Node permission '{$perm_group}.{$perm_id}' for Node {$node_id} has no direct local forum equivalent (no access granted).",
					'info',
					$node_id
				);
				continue;
			}

			$auth_setting = 0;
			if ($val_type === 'allow' || $val_type === 'content_allow')
			{
				$auth_setting = 1;
			}
			else if ($val_type === 'deny' || $val_type === 'never')
			{
				$auth_setting = -1;
			}
			else if ($val_type === 'use_int')
			{
				$auth_setting = ($val_int > 0 || $val_int === -1) ? 1 : 0;
			}

			$to_write[] = [
				'source_node_id'  => $node_id,
				'source_group_id' => (int)($entry['user_group_id'] ?? 0),
				'source_user_id'  => (int)($entry['user_id'] ?? 0),
				'phpbb_option'    => $phpbb_opt,
				'auth_setting'    => $auth_setting,
			];
		}

		$result->next_cursor = 1;
		$result->is_completed = true;

		// Dry-Run Handling
		if ($config->dry_run)
		{
			$result->imported_count = count($to_write);
			return $result;
		}

		$write_options = [
			'run_id'        => $run_id,
			'source_system' => $config->source_system ?: 'xenforo',
		];

		$write_results = $writer->write_node_permissions($to_write, $write_options);

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
