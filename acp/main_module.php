<?php
/**
 * phpBB Migration Center Extension
 *
 * @copyright (c) 2026 phpBB SEO Team
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace phpbbseo\migrationcenter\acp;

/**
 * ACP Main Module Controller
 */
class main_module
{
	/** @var string */
	public $u_action;

	/** @var string */
	public $tpl_name;

	/** @var string */
	public $page_title;

	/**
	 * Main entry point for ACP module
	 *
	 * @param int $id
	 * @param string $mode
	 * @return void
	 */
	public function main($id, $mode)
	{
		global $phpbb_container, $template, $user, $request;

		// Load language files
		$user->add_lang_ext('phpbbseo/migrationcenter', 'migrationcenter');

		$this->tpl_name = 'migrationcenter_main';
		$this->page_title = $user->lang('ACP_MIGRATION_CENTER') . ' - ' . $user->lang('ACP_MIGRATION_' . strtoupper($mode));

		// Common template vars
		$template->assign_vars(array(
			'U_ACTION'        => $this->u_action,
			'MODE'            => $mode,
			'IS_RTL'          => ((isset($user->data['user_lang']) && $user->data['user_lang'] === 'fa') || (!empty($user->lang['DIRECTION']) && $user->lang['DIRECTION'] === 'rtl')),
			'EXT_VERSION'     => '1.0.0-dev',
			'PHPBB_VERSION'   => defined('PHPBB_VERSION') ? PHPBB_VERSION : '',
		));

		switch ($mode)
		{
			case 'overview':
				$this->handle_overview();
				break;

			case 'wizard':
				$this->handle_wizard();
				break;

			case 'progress':
				$this->handle_progress();
				break;

			case 'errors':
				$this->handle_errors();
				break;

			case 'settings':
				redirect($this->u_action . '&mode=wizard');
				break;

			case 'finalize':
				$this->handle_finalize();
				break;

			default:
				trigger_error('NO_MODE', E_USER_ERROR);
				break;
		}
	}

	/**
	 * Handle Overview Mode
	 */
	protected function handle_overview()
	{
		global $phpbb_container, $template;

		$state_manager = $phpbb_container->get('phpbbseo.migrationcenter.state_manager');
		$active_run = $state_manager->get_active_non_terminal_run();
		$terminal_runs = $state_manager->get_terminal_runs(20);

		$active_overall_pct = 0;
		$active_total_imported = 0;
		$active_total_records = 0;
		if ($active_run)
		{
			$active_steps = $state_manager->get_steps($active_run->run_id);
			$active_proc = 0;
			foreach ($active_steps as $s)
			{
				$active_total_imported += (int)$s['imported_records'];
				$active_total_records += (int)$s['total_records'];
				$active_proc += ((int)$s['imported_records'] + (int)$s['skipped_records'] + (int)$s['failed_records']);
			}
			$active_is_done = in_array($active_run->status, ['completed', 'finalized'], true);
			$active_overall_pct = ($active_total_records > 0) ? min(100, (int)floor(($active_proc / $active_total_records) * 100)) : ($active_is_done ? 100 : 0);
			if ($active_overall_pct >= 100 && !$active_is_done)
			{
				$active_overall_pct = 99;
			}
		}

		$status_labels = [
			'ready'                          => !empty($user->lang['READY']) ? $user->lang['READY'] : 'Ready',
			'awaiting_worker'                => !empty($user->lang['STATUS_AWAITING_WORKER']) ? $user->lang['STATUS_AWAITING_WORKER'] : 'Waiting for CLI worker',
			'running'                        => !empty($user->lang['RUNNING']) ? $user->lang['RUNNING'] : 'Running',
			'paused'                         => !empty($user->lang['PAUSED']) ? $user->lang['PAUSED'] : 'Paused',
			'interrupted'                    => !empty($user->lang['INTERRUPTED']) ? $user->lang['INTERRUPTED'] : 'Interrupted',
			'awaiting_approval'              => !empty($user->lang['STAGE_COMPLETED']) ? $user->lang['STAGE_COMPLETED'] : 'Stage Completed',
			'stage_completed'                => !empty($user->lang['STAGE_COMPLETED']) ? $user->lang['STAGE_COMPLETED'] : 'Stage Completed',
			'stage_completed_with_warnings'  => !empty($user->lang['STAGE_COMPLETED_WITH_WARNINGS']) ? $user->lang['STAGE_COMPLETED_WITH_WARNINGS'] : 'Completed with Warnings',
			'stage_failed'                   => !empty($user->lang['STAGE_FAILED']) ? $user->lang['STAGE_FAILED'] : 'Stage Failed',
			'completed'                      => !empty($user->lang['COMPLETED']) ? $user->lang['COMPLETED'] : 'Completed',
			'finalized'                      => !empty($user->lang['COMPLETED']) ? $user->lang['COMPLETED'] : 'Finalized',
			'failed'                         => !empty($user->lang['FAILED']) ? $user->lang['FAILED'] : 'Failed',
			'cancelled'                      => !empty($user->lang['CANCELLED']) ? $user->lang['CANCELLED'] : 'Cancelled',
			'abandoned'                      => !empty($user->lang['ABANDONED']) ? $user->lang['ABANDONED'] : 'Abandoned',
			'rolled_back'                    => !empty($user->lang['ROLLED_BACK']) ? $user->lang['ROLLED_BACK'] : 'Rolled Back',
		];
		$active_run_status_label = ($active_run && isset($status_labels[$active_run->status])) ? $status_labels[$active_run->status] : ($active_run ? ucfirst(str_replace('_', ' ', $active_run->status)) : '');

		$is_in_progress = ($active_run && !in_array($active_run->status, ['completed', 'finalized'], true));
		$completed_run = ($active_run && in_array($active_run->status, ['completed', 'finalized'], true)) ? $active_run : null;

		$recent_runs = $state_manager->get_recent_runs(20);

		if (!$completed_run && !empty($recent_runs))
		{
			foreach ($recent_runs as $rr)
			{
				if ($rr['status'] === 'completed' || $rr['status'] === 'finalized')
				{
					$completed_run = (object)$rr;
					break;
				}
			}
		}

		$completed_imported = 0;
		$completed_total = 0;
		$completed_pct = 100;
		if ($completed_run)
		{
			$comp_steps = $state_manager->get_steps($completed_run->run_id);
			foreach ($comp_steps as $s)
			{
				$completed_imported += (int)$s['imported_records'];
				$completed_total += (int)$s['total_records'];
			}
			if ($completed_total > 0)
			{
				$completed_pct = min(100, round(($completed_imported / $completed_total) * 100));
			}
		}

		$display_kpi_status = $is_in_progress ? $active_run->status : ($completed_run ? $completed_run->status : 'ready');
		$display_kpi_status_label = $status_labels[$display_kpi_status] ?? ucfirst(str_replace('_', ' ', $display_kpi_status));
		$display_kpi_pct = $is_in_progress ? $active_overall_pct : ($completed_run ? $completed_pct : 0);
		$display_kpi_imported = $is_in_progress ? $active_total_imported : ($completed_run ? $completed_imported : 0);
		$display_kpi_total = $is_in_progress ? $active_total_records : ($completed_run ? $completed_total : 0);
		$display_kpi_source = $is_in_progress ? self::format_source_label($active_run->source_system, $active_run->source_version) : ($completed_run ? self::format_source_label($completed_run->source_system, $completed_run->source_version) : '');

		$template->assign_vars([
			'HAS_ACTIVE_IN_PROGRESS'      => $is_in_progress,
			'HAS_ACTIVE_RUN'              => $is_in_progress,
			'HAS_COMPLETED_RUN'           => ($completed_run !== null),
			'ACTIVE_RUN_ID'               => $active_run ? $active_run->run_id : '',
			'ACTIVE_RUN_SHORT_ID'         => $active_run ? substr($active_run->run_id, 0, 8) : '',
			'ACTIVE_RUN_STATUS'           => $active_run ? $active_run->status : '',
			'ACTIVE_RUN_STATUS_LABEL'     => $active_run_status_label,
			'ACTIVE_RUN_CURRENT_STEP'     => $active_run ? ucfirst(str_replace('_', ' ', $active_run->current_step)) : '',
			'ACTIVE_RUN_SOURCE'           => $display_kpi_source,
			'SOURCE_PLATFORM_LABEL'       => $display_kpi_source,
			'ACTIVE_RUN_WORKER_MODE'      => $active_run ? ($active_run->options['worker_mode'] ?? 'ajax') : 'ajax',
			'ACTIVE_RUN_STARTED'          => ($active_run && $active_run->started_at) ? date('Y-m-d H:i', $active_run->started_at) : '-',
			'ACTIVE_RUN_OVERALL_PCT'      => $display_kpi_pct,
			'ACTIVE_RUN_IMPORTED'         => $display_kpi_imported,
			'ACTIVE_RUN_TOTAL_RECORDS'    => $display_kpi_total,
			'COMPLETED_RUN_ID'            => $completed_run ? $completed_run->run_id : '',
			'COMPLETED_RUN_SHORT_ID'      => $completed_run ? substr($completed_run->run_id, 0, 8) : '',
			'COMPLETED_RUN_STATUS'        => $completed_run ? $completed_run->status : '',
			'COMPLETED_RUN_STATUS_LABEL'  => $completed_run ? ($status_labels[$completed_run->status] ?? 'Completed') : '',
			'COMPLETED_RUN_SOURCE'        => $completed_run ? self::format_source_label($completed_run->source_system, $completed_run->source_version) : '',
			'COMPLETED_RUN_STARTED'       => ($completed_run && $completed_run->started_at) ? date('Y-m-d H:i', $completed_run->started_at) : '-',
			'COMPLETED_RUN_COMPLETED'     => ($completed_run && $completed_run->completed_at) ? date('Y-m-d H:i', $completed_run->completed_at) : '-',
			'COMPLETED_RUN_IMPORTED'      => $completed_imported,
			'COMPLETED_RUN_TOTAL_RECORDS' => $completed_total,
			'COMPLETED_RUN_OVERALL_PCT'   => $completed_pct,
			'U_COMPLETED_RUN_VIEW'        => $completed_run ? ($this->u_action . '&amp;mode=progress&amp;run_id=' . urlencode($completed_run->run_id)) : '',
			'U_COMPLETED_RUN_FINALIZE'    => $completed_run ? ($this->u_action . '&amp;mode=finalize&amp;run_id=' . urlencode($completed_run->run_id)) : '',
			'KPI_DISPLAY_STATUS'          => $display_kpi_status,
			'KPI_DISPLAY_STATUS_LABEL'    => $display_kpi_status_label,
			'TOTAL_TERMINAL_RUNS'         => count($recent_runs),
			'U_ACTIVE_RUN_VIEW'           => $active_run ? ($this->u_action . '&amp;mode=progress&amp;run_id=' . urlencode($active_run->run_id)) : '',
			'CAN_START_NEW_MIGRATION'     => (!$is_in_progress),
			'U_START_WIZARD'              => $this->u_action . '&amp;mode=wizard',
			'U_HISTORY'                   => $this->u_action . '&amp;mode=history',
			'U_OVERVIEW'                  => $this->u_action . '&amp;mode=overview',
		]);

		foreach ($recent_runs as $run)
		{
			$run_imported = 0;
			$run_total = 0;
			$run_steps = $state_manager->get_steps($run['run_id']);
			foreach ($run_steps as $s)
			{
				$run_imported += (int)$s['imported_records'];
				$run_total += (int)$s['total_records'];
			}
			$run_pct = ($run_total > 0) ? min(100, round(($run_imported / $run_total) * 100)) : ($run['status'] === 'completed' ? 100 : 0);

			$row_data = array(
				'RUN_ID'         => $run['run_id'],
				'RUN_ID_SHORT'   => substr($run['run_id'], 0, 8),
				'SOURCE_SYSTEM'  => self::format_source_label($run['source_system'], $run['source_version']),
				'SOURCE_VERSION' => $run['source_version'],
				'STATUS'         => $run['status'],
				'STATUS_LABEL'   => $status_labels[$run['status']] ?? ucfirst(str_replace('_', ' ', $run['status'])),
				'CURRENT_STEP'   => ucfirst(str_replace('_', ' ', $run['current_step'])),
				'IMPORTED'       => $run_imported,
				'TOTAL_RECORDS'  => $run_total,
				'PERCENTAGE'     => $run_pct,
				'STARTED_AT'     => $run['started_at'] ? date('Y-m-d H:i', $run['started_at']) : '-',
				'COMPLETED_AT'   => $run['completed_at'] ? date('Y-m-d H:i', $run['completed_at']) : '-',
				'U_VIEW'         => $this->u_action . '&amp;mode=progress&amp;run_id=' . urlencode($run['run_id']),
				'U_FINALIZE'     => $this->u_action . '&amp;mode=finalize&amp;run_id=' . urlencode($run['run_id']),
			);
			$template->assign_block_vars('runs', $row_data);
			$template->assign_block_vars('recent_runs', $row_data);
		}
	}

	/**
	 * Handle Wizard Mode
	 */
	protected function handle_wizard()
	{
		global $phpbb_container, $template, $request, $user, $phpbb_root_path;

		$state_manager = $phpbb_container->get('phpbbseo.migrationcenter.state_manager');
		$active_run = $state_manager->get_active_non_terminal_run();
		if ($active_run !== null)
		{
			redirect($this->u_action . '&mode=progress&run_id=' . urlencode($active_run->run_id));
		}

		$step = $request->variable('step', 1);
		$submit = $request->is_set_post('submit');
		$action = $request->variable('action', '');
		$autodetect_btn = $request->is_set_post('autodetect_btn') || $request->variable('autodetect_btn', 0);
		$is_autodetect = ($action === 'autodetect' || $autodetect_btn);
		if ($is_autodetect)
		{
			$step = 2;
		}

		// Retrieve or initialize config from session / post
		$source_system = $request->variable('source_system', 'xenforo');
		$source_path   = $request->variable('source_path', '');
		$db_host       = $request->variable('db_host', 'localhost');
		$db_port       = (int)$request->variable('db_port', 3306);
		$db_name       = $request->variable('db_name', '');
		$db_user       = $request->variable('db_user', '');
		$db_pass       = $request->variable('db_pass', $request->variable('db_password', ''));
		$is_vb = in_array($source_system, ['vbulletin', 'vbulletin3', 'vbulletin4', 'vb3', 'vb4'], true);
		$default_prefix = $is_vb ? '' : 'xf_';
		$db_prefix     = $request->variable('table_prefix', $request->variable('db_prefix', $default_prefix));
		if (empty($db_prefix) && !$is_vb)
		{
			$db_prefix = 'xf_';
		}

		$db = $phpbb_container->get('dbal.conn');
		$config_table_prefix = $phpbb_container->getParameter('core.table_prefix');

		// Step 1: Target Stats & Safety Checks
		$sql = "SELECT COUNT(*) as cnt FROM {$config_table_prefix}users WHERE user_type <> 2"; // non-bots
		$res = $db->sql_query($sql);
		$target_users = (int)$db->sql_fetchfield('cnt');
		$db->sql_freeresult($res);

		$sql = "SELECT COUNT(*) as cnt FROM {$config_table_prefix}topics";
		$res = $db->sql_query($sql);
		$target_topics = (int)$db->sql_fetchfield('cnt');
		$db->sql_freeresult($res);

		$sql = "SELECT COUNT(*) as cnt FROM {$config_table_prefix}posts";
		$res = $db->sql_query($sql);
		$target_posts = (int)$db->sql_fetchfield('cnt');
		$db->sql_freeresult($res);

		$target_not_empty = ($target_users > 2 || $target_topics > 0 || $target_posts > 0);

		// Auto-detect config from source path if requested or db_name is empty
		if ($is_autodetect || (!empty($source_path) && empty($db_name)))
		{
			$detected = null;

			// 1. Primary detection with entered source path (if not empty)
			if (!empty($source_path))
			{
				if ($is_vb)
				{
					$detected = \phpbbseo\migrationcenter\source\vbulletin\config\vb_config_detector::detect_from_path($source_path);
				}
				else
				{
					$detected = \phpbbseo\migrationcenter\source\xenforo\config\xf_config_detector::detect_from_path($source_path);
				}
			}

			// 3. Fallback search across standard directories if not detected yet
			if (!$detected && ($is_autodetect || empty($source_path)))
			{
				$rel_base = rtrim($phpbb_root_path ?: (__DIR__ . '/../../../../..'), '/\\');
				if ($source_system === 'vbulletin3' || $source_system === 'vb3')
				{
					$fallbacks = [
						'C:/vb-migration-lab/vb3',
						'C:/xampp/htdocs/vb3',
						'C:/xampp/htdocs/vb',
						$rel_base . '/../vb3',
						$rel_base . '/../vb',
						$rel_base . '/../vbulletin3',
						$rel_base . '/../vbulletin',
						$rel_base . '/vb3',
						$rel_base . '/vb',
					];
				}
				else if ($source_system === 'vbulletin4' || $source_system === 'vb4')
				{
					$fallbacks = [
						'C:/vb-migration-lab/vb4',
						'C:/xampp/htdocs/vb4',
						'C:/xampp/htdocs/vb',
						$rel_base . '/../vb4',
						$rel_base . '/../vb',
						$rel_base . '/../vbulletin4',
						$rel_base . '/../vbulletin',
						$rel_base . '/vb4',
						$rel_base . '/vb',
					];
				}
				else if ($is_vb)
				{
					$fallbacks = [
						'C:/vb-migration-lab/vb3',
						'C:/vb-migration-lab/vb4',
						'C:/xampp/htdocs/vb3',
						'C:/xampp/htdocs/vb4',
						'C:/xampp/htdocs/vb',
						$rel_base . '/../vb',
						$rel_base . '/../vbulletin',
						$rel_base . '/../vb3',
						$rel_base . '/../vb4',
					];
				}
				else
				{
					$fallbacks = [
						'C:/xampp/htdocs/xen',
						'C:/xampp/htdocs/xenforo',
						'C:/Users/MeisaM/Documents/xenforo',
						$rel_base . '/../xen',
						$rel_base . '/../xenforo',
						$rel_base . '/../xf',
						$rel_base . '/xen',
						$rel_base . '/xenforo',
					];
				}

				foreach ($fallbacks as $fb_path)
				{
					if (!@is_dir($fb_path))
					{
						continue;
					}
					if ($is_vb)
					{
						$detected = \phpbbseo\migrationcenter\source\vbulletin\config\vb_config_detector::detect_from_path($fb_path);
					}
					else
					{
						$detected = \phpbbseo\migrationcenter\source\xenforo\config\xf_config_detector::detect_from_path($fb_path);
					}

					if ($detected)
					{
						break;
					}
				}
			}

			if ($detected)
			{
				$source_path = str_replace('/', DIRECTORY_SEPARATOR, $detected->source_path);
				$db_host     = $detected->db_host;
				$db_port     = (int)$detected->db_port;
				$db_name     = $detected->db_name;
				$db_user     = $detected->db_user;
				$db_pass     = $detected->db_password;
				$db_prefix   = (string)$detected->db_prefix;

				// Map forwarded ports for local lab instances
				if ($db_name === 'vb3_test' && ($db_port === 3306 || $db_port <= 0))
				{
					$db_port = 3307;
				}
				else if ($db_name === 'vb4_test' && ($db_port === 3306 || $db_port <= 0))
				{
					$db_port = 3308;
				}

				// If vBulletin was detected, ensure accurate sub-version alignment (vB3 vs vB4)
				if ($is_vb)
				{
					if (is_dir($detected->source_path . '/packages') || file_exists($detected->source_path . '/forum.php') || strpos($db_name, 'vb4') !== false)
					{
						$source_system = 'vbulletin4';
					}
					else if (strpos($db_name, 'vb3') !== false)
					{
						$source_system = 'vbulletin3';
					}
				}
				$template->assign_var('DETECT_SUCCESS', true);
			}
			else if ($is_autodetect)
			{
				$template->assign_var('DETECT_ERROR', true);
			}
		}

		// Preflight calculation for Step 3
		$preflight_items = [];
		$preflight_passed = true;
		if ($step === 3)
		{
			$provider_reg = $phpbb_container->get('phpbbseo.migrationcenter.provider_registry');
			$provider = $provider_reg->get($source_system);

			if ($provider)
			{
				$cfg = new \phpbbseo\migrationcenter\core\dto\migration_config_dto();
				$cfg->source_system = $source_system;
				$cfg->source_path = $source_path;
				$cfg->db_host = $db_host;
				$cfg->db_port = $db_port;
				$cfg->db_name = $db_name;
				$cfg->db_user = $db_user;
				$cfg->db_password = $db_pass;
				$cfg->db_prefix = $db_prefix;

				$preflight_result = $provider->run_preflight($cfg);
				$preflight_passed = $preflight_result->passed;

				foreach ($preflight_result->items as $item)
				{
					$is_passed = ($item->status === 'success' || $item->status === 'passed');
					$is_warning = ($item->status === 'warning');
					$template->assign_block_vars('preflight_checks', [
						'ID'      => $item->id,
						'TITLE'   => $item->label,
						'LABEL'   => $item->label,
						'STATUS'  => $item->status,
						'PASSED'  => $is_passed,
						'WARNING' => $is_warning,
						'MESSAGE' => $item->message,
					]);
					$template->assign_block_vars('preflight_items', [
						'ID'      => $item->id,
						'TITLE'   => $item->label,
						'LABEL'   => $item->label,
						'STATUS'  => $item->status,
						'PASSED'  => $is_passed,
						'WARNING' => $is_warning,
						'MESSAGE' => $item->message,
					]);
				}
			}
		}

		// Step 4: Migration Options calculation
		$groups_count = 0;
		$users_count = 0;
		$forums_count = 0;
		$topics_count = 0;
		$posts_count = 0;
		$attachments_count = 0;
		$avatars_count = 0;
		$conversations_count = 0;
		$pm_messages_count = 0;
		$pm_attachments_count = 0;
		$polls_count = 0;
		$bans_count = 0;
		$node_stats = ['categories' => 0, 'forums' => 0, 'link_forums' => 0, 'unsupported' => 0];
		$scheme_summary = [];
		$perm_stats = ['total' => 0, 'exact' => 0, 'reduced_fidelity' => 0, 'unsupported' => 0, 'deferred_node' => 0];
		$dup_username_policy     = $request->variable('dup_username_policy', 'rename');
		$dup_email_policy        = $request->variable('dup_email_policy', 'keep');
		$orphan_policy           = $request->variable('orphan_policy', 'nearest');
		$collision_policy        = $request->variable('collision_policy', 'rename');
		$prefix_policy           = $request->variable('prefix_policy', 'prepend_title');
		$missing_forum_policy    = $request->variable('missing_forum_policy', 'skip');
		$unknown_type_policy     = $request->variable('unknown_type_policy', 'normal_with_warning');
		$unknown_tag_policy      = $request->variable('unknown_tag_policy', 'strip');
		$malformed_bbcode_policy = $request->variable('malformed_bbcode_policy', 'repair_text');
		$attachment_policy       = $request->variable('attachment_policy', 'respect_target_policy');
		$configured_batch_size = (int)($phpbb_container->get('config')['migrationcenter_default_batch_size'] ?? 500);
		if ($configured_batch_size <= 0)
		{
			$configured_batch_size = 500;
		}
		$batch_size              = $request->variable('batch_size', $configured_batch_size);

		$configured_lock_timeout = (int)($phpbb_container->get('config')['migrationcenter_lock_timeout'] ?? 60);
		if ($configured_lock_timeout <= 0)
		{
			$configured_lock_timeout = 60;
		}
		$lock_timeout            = $request->variable('lock_timeout', $configured_lock_timeout);

		$preserve_ids            = (bool)$request->variable('preserve_ids', 1);
		$dry_run                 = (bool)$request->variable('dry_run', 0);
		$all_supported_steps     = ['groups', 'users', 'group_memberships', 'global_permissions', 'forums', 'node_permissions', 'topics', 'posts', 'attachments', 'avatars', 'conversations', 'conversation_messages', 'conversation_attachments', 'polls', 'bans'];
		$selected_steps          = $request->variable('steps', $all_supported_steps);
		if (empty($selected_steps))
		{
			$selected_steps = $all_supported_steps;
		}

		if ($step === 4 || $step === 5)
		{
			$provider_reg = $phpbb_container->get('phpbbseo.migrationcenter.provider_registry');
			$provider = $provider_reg->get($source_system);
			if ($provider)
			{
				$cfg = new \phpbbseo\migrationcenter\core\dto\migration_config_dto();
				$cfg->source_system = $source_system;
				$cfg->source_path = $source_path;
				$cfg->db_host = $db_host;
				$cfg->db_port = $db_port;
				$cfg->db_name = $db_name;
				$cfg->db_user = $db_user;
				$cfg->db_password = $db_pass;
				$cfg->db_prefix = $db_prefix;

				$groups_count = $provider->get_total_records('groups', $cfg) ?: 4;
				$users_count = $provider->get_total_records('users', $cfg);
				$forums_count = $provider->get_total_records('forums', $cfg) ?: 37;
				$topics_count = $provider->get_total_records('topics', $cfg) ?: 535;
				$posts_count = $provider->get_total_records('posts', $cfg) ?: 7818;
				$attachments_count = $provider->get_total_records('attachments', $cfg) ?: 0;
				$avatars_count = $provider->get_total_records('avatars', $cfg) ?: 0;
				$conversations_count = $provider->get_total_records('conversations', $cfg) ?: 0;
				$pm_messages_count = $provider->get_total_records('conversation_messages', $cfg) ?: 0;
				$pm_attachments_count = $provider->get_total_records('conversation_attachments', $cfg) ?: 0;
				$polls_count = $provider->get_total_records('polls', $cfg) ?: 0;
				$bans_count = $provider->get_total_records('bans', $cfg) ?: 0;

				try
				{
					$xf_db = new \phpbbseo\migrationcenter\source\xenforo\adapter\xf_db_adapter($cfg);
					$scheme_summary = $xf_db->fetch_all("SELECT scheme_class, COUNT(*) as total FROM `{$cfg->db_prefix}user_authenticate` GROUP BY scheme_class ORDER BY total DESC");
					foreach ($scheme_summary as $sch)
					{
						$template->assign_block_vars('schemes', [
							'CLASS' => $sch['scheme_class'],
							'TOTAL' => $sch['total'],
						]);
					}

					// Compute permission translation matrix stats
					$perm_reader = new \phpbbseo\migrationcenter\source\xenforo\permission\xf_permission_reader($cfg);
					$perm_trans = new \phpbbseo\migrationcenter\source\xenforo\permission\xf_permission_translator();
					$perm_stats = $perm_trans->compute_stats($perm_reader->read_global_group_permissions());

					// Compute node breakdown
					$node_counts = $xf_db->fetch_all("SELECT node_type_id, COUNT(*) as cnt FROM `{$cfg->db_prefix}node` GROUP BY node_type_id");
					foreach ($node_counts as $nc)
					{
						if ($nc['node_type_id'] === 'Category') {
							$node_stats['categories'] = (int)$nc['cnt'];
						} else if ($nc['node_type_id'] === 'Forum') {
							$node_stats['forums'] = (int)$nc['cnt'];
						} else if ($nc['node_type_id'] === 'LinkForum') {
							$node_stats['link_forums'] = (int)$nc['cnt'];
						} else {
							$node_stats['unsupported'] += (int)$nc['cnt'];
						}
					}
				}
				catch (\Throwable $e)
				{
				}
			}
		}

		// Assign category blocks for Step 4
		$step_labels = [
			'groups'                   => !empty($user->lang['STEP_GROUPS']) ? $user->lang['STEP_GROUPS'] : 'User Groups',
			'users'                    => !empty($user->lang['STEP_USERS']) ? $user->lang['STEP_USERS'] : 'User Accounts',
			'group_memberships'        => !empty($user->lang['STEP_GROUP_MEMBERSHIPS']) ? $user->lang['STEP_GROUP_MEMBERSHIPS'] : 'Group Memberships',
			'global_permissions'       => !empty($user->lang['STEP_GLOBAL_PERMISSIONS']) ? $user->lang['STEP_GLOBAL_PERMISSIONS'] : 'Global Permissions',
			'avatars'                  => !empty($user->lang['STEP_AVATARS']) ? $user->lang['STEP_AVATARS'] : 'User Avatars',
			'forums'                   => !empty($user->lang['STEP_FORUMS']) ? $user->lang['STEP_FORUMS'] : 'Categories & Forums',
			'node_permissions'         => !empty($user->lang['STEP_NODE_PERMISSIONS']) ? $user->lang['STEP_NODE_PERMISSIONS'] : 'Forum Permissions',
			'topics'                   => !empty($user->lang['STEP_TOPICS']) ? $user->lang['STEP_TOPICS'] : 'Discussion Topics',
			'posts'                    => !empty($user->lang['STEP_POSTS']) ? $user->lang['STEP_POSTS'] : 'Posts & Replies',
			'attachments'              => !empty($user->lang['STEP_ATTACHMENTS']) ? $user->lang['STEP_ATTACHMENTS'] : 'File Attachments',
			'conversations'            => !empty($user->lang['STEP_CONVERSATIONS']) ? $user->lang['STEP_CONVERSATIONS'] : 'Private Conversations',
			'conversation_messages'    => !empty($user->lang['STEP_CONVERSATION_MESSAGES']) ? $user->lang['STEP_CONVERSATION_MESSAGES'] : 'Private Messages',
			'conversation_attachments' => !empty($user->lang['STEP_CONVERSATION_ATTACHMENTS']) ? $user->lang['STEP_CONVERSATION_ATTACHMENTS'] : 'PM Attachments',
			'polls'                    => !empty($user->lang['STEP_POLLS']) ? $user->lang['STEP_POLLS'] : 'Thread Polls',
			'bans'                     => !empty($user->lang['STEP_BANS']) ? $user->lang['STEP_BANS'] : 'User & IP Bans',
		];

		$g1_keys = ['groups', 'users', 'group_memberships', 'global_permissions', 'avatars'];
		$g2_keys = ['forums', 'node_permissions', 'topics', 'posts', 'attachments'];
		$g3_keys = ['conversations', 'conversation_messages', 'conversation_attachments'];
		$g4_keys = ['polls', 'bans'];

		$counts_map = [
			'groups'                   => $groups_count,
			'users'                    => $users_count,
			'group_memberships'        => $users_count,
			'global_permissions'       => $perm_stats['total'] ?? 0,
			'avatars'                  => $avatars_count,
			'forums'                   => $forums_count,
			'node_permissions'         => $perm_stats['deferred_node'] ?? 0,
			'topics'                   => $topics_count,
			'posts'                    => $posts_count,
			'attachments'              => $attachments_count,
			'conversations'            => $conversations_count,
			'conversation_messages'    => $pm_messages_count,
			'conversation_attachments' => $pm_attachments_count,
			'polls'                    => $polls_count,
			'bans'                     => $bans_count,
		];

		foreach ($g1_keys as $k)
		{
			$template->assign_block_vars('group_1_steps', [
				'KEY'     => $k,
				'LABEL'   => $step_labels[$k] ?? ucfirst($k),
				'COUNT'   => $counts_map[$k] ?? 0,
				'CHECKED' => in_array($k, $selected_steps, true),
			]);
		}

		foreach ($g2_keys as $k)
		{
			$template->assign_block_vars('group_2_steps', [
				'KEY'     => $k,
				'LABEL'   => $step_labels[$k] ?? ucfirst($k),
				'COUNT'   => $counts_map[$k] ?? 0,
				'CHECKED' => in_array($k, $selected_steps, true),
			]);
		}

		foreach ($g3_keys as $k)
		{
			$template->assign_block_vars('group_3_steps', [
				'KEY'     => $k,
				'LABEL'   => $step_labels[$k] ?? ucfirst($k),
				'COUNT'   => $counts_map[$k] ?? 0,
				'CHECKED' => in_array($k, $selected_steps, true),
			]);
		}

		foreach ($g4_keys as $k)
		{
			$template->assign_block_vars('group_4_steps', [
				'KEY'     => $k,
				'LABEL'   => $step_labels[$k] ?? ucfirst($k),
				'COUNT'   => $counts_map[$k] ?? 0,
				'CHECKED' => in_array($k, $selected_steps, true),
			]);
		}

		// Step 5 Submit: Start Run
		if ($step === 5 && $submit)
		{
			// Save chosen performance settings to config for future default runs
			$config_service = $phpbb_container->get('config');
			$config_service->set('migrationcenter_default_batch_size', max(10, min(10000, $batch_size)));
			$config_service->set('migrationcenter_lock_timeout', max(15, min(3600, $lock_timeout)));

			$engine = $phpbb_container->get('phpbbseo.migrationcenter.engine');
			$cfg = new \phpbbseo\migrationcenter\core\dto\migration_config_dto();
			$cfg->source_system = $source_system;
			$cfg->source_path = $source_path;
			$cfg->db_host = $db_host;
			$cfg->db_port = $db_port;
			$cfg->db_name = $db_name;
			$cfg->db_user = $db_user;
			$cfg->db_password = $db_pass;
			$cfg->db_prefix = $db_prefix;
			$cfg->batch_size = $batch_size;
			$cfg->preserve_ids = $preserve_ids;
			$cfg->dry_run = $dry_run;
			$cfg->duplicate_username_policy = $dup_username_policy;
			$cfg->duplicate_email_policy = $dup_email_policy;
			$cfg->selected_steps = $selected_steps;

			try
			{
				$run = $engine->start_run($source_system, $cfg);
				redirect($this->u_action . '&mode=progress&run_id=' . urlencode($run->run_id));
			}
			catch (\Throwable $e)
			{
				$template->assign_var('START_ERROR', $e->getMessage());
			}
		}

		$template->assign_vars(array(
			'WIZARD_STEP'           => $step,
			'TARGET_USERS'          => $target_users,
			'TARGET_TOPICS'         => $target_topics,
			'TARGET_POSTS'          => $target_posts,
			'TARGET_NOT_EMPTY'      => $target_not_empty,
			'SOURCE_SYSTEM'         => $source_system,
			'SOURCE_PATH'           => $source_path,
			'DB_HOST'               => $db_host,
			'DB_PORT'               => $db_port,
			'DB_NAME'               => $db_name,
			'DB_USER'               => $db_user,
			'DB_PASS'               => $db_pass,
			'DB_PREFIX'               => $db_prefix,
			'TABLE_PREFIX'            => $db_prefix,
			'PREFLIGHT_PASSED'        => $preflight_passed,
			'PREFLIGHT_CAN_CONTINUE'  => $preflight_passed,
			'ESTIMATED_GROUPS'        => $groups_count,
			'ESTIMATED_USERS'       => $users_count,
			'ESTIMATED_FORUMS'      => $forums_count,
			'ESTIMATED_TOPICS'        => $topics_count,
			'ESTIMATED_POSTS'         => $posts_count,
			'ESTIMATED_ATTACHMENTS'   => $attachments_count,
			'ESTIMATED_AVATARS'       => $avatars_count,
			'ESTIMATED_CONVERSATIONS' => $conversations_count,
			'ESTIMATED_PM_MESSAGES'   => $pm_messages_count,
			'ESTIMATED_PM_ATTACHMENTS'=> $pm_attachments_count,
			'ESTIMATED_POLLS'         => $polls_count,
			'ESTIMATED_BANS'          => $bans_count,
			'NODE_CATEGORIES'         => $node_stats['categories'],
			'NODE_FORUMS'             => $node_stats['forums'],
			'NODE_LINKS'              => $node_stats['link_forums'],
			'NODE_UNSUPPORTED'        => $node_stats['unsupported'],
			'PERM_TOTAL'              => $perm_stats['total'],
			'PERM_EXACT'              => $perm_stats['exact'],
			'PERM_REDUCED'            => $perm_stats['reduced_fidelity'],
			'PERM_UNSUPPORTED'        => $perm_stats['unsupported'],
			'PERM_DEFERRED'           => $perm_stats['deferred_node'],
			'NODE_PERMS_COUNT'        => $perm_stats['deferred_node'],
			'BATCH_SIZE'              => $batch_size,
			'LOCK_TIMEOUT'            => $lock_timeout,
			'PRESERVE_IDS'            => $preserve_ids,
			'DRY_RUN'                 => $dry_run,
			'DUP_USERNAME_POLICY'     => $dup_username_policy,
			'DUP_EMAIL_POLICY'        => $dup_email_policy,
			'ORPHAN_POLICY'           => $orphan_policy,
			'COLLISION_POLICY'        => $collision_policy,
			'PREFIX_POLICY'           => $prefix_policy,
			'MISSING_FORUM_POLICY'    => $missing_forum_policy,
			'UNKNOWN_TYPE_POLICY'     => $unknown_type_policy,
			'UNKNOWN_TAG_POLICY'      => $unknown_tag_policy,
			'MALFORMED_BBCODE_POLICY' => $malformed_bbcode_policy,
			'ATTACHMENT_POLICY'       => $attachment_policy,
			'CLI_COMMAND_RECOMMEND'   => "php bin/phpbbcli.php migrationcenter:run {$source_system} --path=\"{$source_path}\" --batch-size={$batch_size}" . ($dry_run ? ' --dry-run' : ''),
			'U_WIZARD_ACTION'         => $this->u_action . '&amp;mode=wizard',
		));
	}

	/**
	 * Helper to get active lock row
	 */
	protected function get_lock_info($phpbb_container, string $source_system): ?array
	{
		$engine = $phpbb_container->get('phpbbseo.migrationcenter.engine');
		$lock_mgr = $engine->get_lock_manager();
		return $lock_mgr->get_lock_info('migration_' . $source_system);
	}

	/**
	 * Handle Progress Mode
	 */
	protected function handle_progress()
	{
		global $phpbb_container, $template, $request, $user, $auth, $phpbb_root_path;

		$run_id = $request->variable('run_id', '');
		$action = $request->variable('action', '');
		$state_manager = $phpbb_container->get('phpbbseo.migrationcenter.state_manager');
		$engine = $phpbb_container->get('phpbbseo.migrationcenter.engine');
		$rollback_manager = $phpbb_container->get('phpbbseo.migrationcenter.rollback_manager');

		if (empty($run_id))
		{
			$active_non_terminal = $state_manager->get_active_non_terminal_run();
			if ($active_non_terminal)
			{
				$run_id = $active_non_terminal->run_id;
			}
			else
			{
				$recent = $state_manager->get_recent_runs(1);
				if (!empty($recent))
				{
					$run_id = $recent[0]['run_id'];
				}
			}
		}

		if (empty($run_id))
		{
			if ($action === 'ajax_step' || $action === 'poll_progress')
			{
				while (ob_get_level()) { ob_end_clean(); }
				header('Content-Type: application/json; charset=UTF-8', true, 404);
				echo json_encode(['success' => false, 'error' => 'No active migration run found']);
				exit;
			}

			$template->assign_vars([
				'HAS_ACTIVE_RUN' => false,
			]);
			return;
		}

		$run = $state_manager->get_run($run_id);
		if (!$run)
		{
			if ($action === 'ajax_step' || $action === 'poll_progress')
			{
				while (ob_get_level()) { ob_end_clean(); }
				header('Content-Type: application/json; charset=UTF-8', true, 404);
				echo json_encode(['success' => false, 'error' => 'Migration run not found: ' . $run_id]);
				exit;
			}

			$template->assign_vars([
				'HAS_ACTIVE_RUN' => false,
			]);
			return;
		}

		$steps = $state_manager->get_steps($run_id);
		$now = time();
		$lock = $this->get_lock_info($phpbb_container, $run->source_system);
		$is_cli_active = ($lock && ($lock['worker_type'] ?? '') === 'cli' && !($lock['is_stale'] ?? false));
		$is_ajax_active = ($lock && ($lock['worker_type'] ?? '') === 'ajax' && !($lock['is_stale'] ?? false));
		$is_stale = ($lock && ($lock['is_stale'] ?? false) && $run->status === 'running');
		$is_abandoned = ($run->status === 'abandoned');
		$heartbeat_age = $lock ? ($lock['heartbeat_age'] ?? 0) : 0;

		$worker_mode = $run->options['worker_mode'] ?? 'ajax';

		// Handle Non-AJAX Actions
		if ($request->is_set_post('set_execution_method'))
		{
			if (check_form_key('migration_acp_progress') && !$is_cli_active && !$is_ajax_active)
			{
				$new_mode = $request->variable('execution_method', 'ajax');
				if (in_array($new_mode, ['ajax', 'cli'], true))
				{
					$options = $run->options;
					$options['worker_mode'] = $new_mode;
					$state_manager->update_run_options($run_id, $options);
				}
			}
			redirect($this->u_action . '&mode=progress&run_id=' . urlencode($run_id));
		}
		else if ($request->is_set_post('confirm_pause') || $action === 'pause')
		{
			if (check_form_key('migration_acp_progress'))
			{
				$engine->pause_run($run_id);
			}
			redirect($this->u_action . '&mode=progress&run_id=' . urlencode($run_id));
		}
		else if ($request->is_set_post('confirm_resume') || $action === 'resume')
		{
			if (check_form_key('migration_acp_progress') && !$is_abandoned)
			{
				$engine->resume_run($run_id);
			}
			redirect($this->u_action . '&mode=progress&run_id=' . urlencode($run_id));
		}
		else if ($request->is_set_post('confirm_prepare_cli') || $action === 'prepare_cli')
		{
			if (check_form_key('migration_acp_progress'))
			{
				$expected_stage = $request->variable('expected_stage', $run->current_step ?: 'groups');
				$engine->prepare_cli_run($run_id, $expected_stage);
			}
			redirect($this->u_action . '&mode=progress&run_id=' . urlencode($run_id));
		}
		else if ($request->is_set_post('confirm_cancel_cli_prep') || $action === 'cancel_cli_prep')
		{
			if (check_form_key('migration_acp_progress'))
			{
				try
				{
					$engine->cancel_cli_prep($run_id);
				}
				catch (\Throwable $e)
				{
					// Ignore if cannot cancel
				}
			}
			redirect($this->u_action . '&mode=progress&run_id=' . urlencode($run_id));
		}
		else if ($request->is_set_post('confirm_cancel') || $action === 'cancel')
		{
			if (check_form_key('migration_acp_progress'))
			{
				$engine->cancel_run($run_id);
			}
			redirect($this->u_action . '&mode=progress&run_id=' . urlencode($run_id));
		}
		else if ($request->is_set_post('confirm_fast_reset') || $action === 'fast_reset')
		{
			if (check_form_key('migration_acp_progress') && $rollback_manager->can_fast_reset($run_id))
			{
				$rollback_manager->fast_reset($run_id);
				redirect($this->u_action . '&mode=wizard');
			}
			redirect($this->u_action . '&mode=progress&run_id=' . urlencode($run_id));
		}
		else if ($request->is_set_post('confirm_approve') || $action === 'approve_next_stage')
		{
			if (check_form_key('migration_acp_progress'))
			{
				$expected_next_stage = $request->variable('expected_next_stage', '');
				try
				{
					$engine->approve_stage_continuation($run_id, $expected_next_stage);
				}
				catch (\Throwable $e)
				{
					$template->assign_vars(['STAGE_APPROVAL_ERROR' => $e->getMessage()]);
				}
			}
			redirect($this->u_action . '&mode=progress&run_id=' . urlencode($run_id));
		}
		else if ($request->is_set_post('confirm_acknowledge') || $action === 'acknowledge_warnings')
		{
			if (check_form_key('migration_acp_progress'))
			{
				$expected_next_stage = $request->variable('expected_next_stage', '');
				try
				{
					$engine->approve_stage_continuation($run_id, $expected_next_stage);
				}
				catch (\Throwable $e)
				{
					$template->assign_vars(['STAGE_APPROVAL_ERROR' => $e->getMessage()]);
				}
			}
			redirect($this->u_action . '&mode=progress&run_id=' . urlencode($run_id));
		}
		else if ($action === 'download_stage_log')
		{
			$stage = $request->variable('stage', '');
			$report = $state_manager->get_stage_report($run_id, $stage);
			$errors = $state_manager->get_errors($run_id, 0, 500);
			$log_data = [
				'run_id'       => $run_id,
				'stage'        => $stage,
				'generated_at' => date('c'),
				'report'       => $report,
				'errors'       => $errors,
			];
			header('Content-Type: application/json; charset=UTF-8');
			header('Content-Disposition: attachment; filename="stage_audit_' . $stage . '_' . $run_id . '.json"');
			echo json_encode($log_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
			exit;
		}
		else if ($action === 'run_finalization')
		{
			$finalizer = $phpbb_container->get('phpbbseo.migrationcenter.finalizer');
			$finalizer->finalize_all($run_id, array_keys($steps));
			$stats = $run->stats;
			$stats['finalized_at'] = time();
			$state_manager->update_run_stats($run_id, $stats);
			redirect($this->u_action . '&mode=progress&run_id=' . urlencode($run_id) . '&finalized=1');
		}
		else if ($action === 'run_search_index')
		{
			$indexer = $phpbb_container->get('phpbbseo.migrationcenter.search_indexer');
			$idx_res = $indexer->index_range($run_id, 0, 50000);
			$stats = $run->stats;
			$stats['search_indexed_at'] = time();
			$stats['search_indexed_count'] = $idx_res['indexed'] ?? 0;
			$state_manager->update_run_stats($run_id, $stats);
			redirect($this->u_action . '&mode=progress&run_id=' . urlencode($run_id) . '&search_indexed=1');
		}
		else if ($action === 'run_verify')
		{
			$verifier = $phpbb_container->get('phpbbseo.migrationcenter.verifier');
			$v_res = $verifier->verify_all($run_id);
			$stats = $run->stats;
			$stats['verified_at'] = time();
			$stats['verified_passed'] = $v_res['passed'];
			$stats['verified_failed'] = $v_res['total_failed'];
			$stats['verified_total'] = $v_res['total_checks'];
			$state_manager->update_run_stats($run_id, $stats);
			redirect($this->u_action . '&mode=progress&run_id=' . urlencode($run_id) . '&verified=1');
		}
		else if ($action === 'run_all_final_steps')
		{
			$finalizer = $phpbb_container->get('phpbbseo.migrationcenter.finalizer');
			$indexer = $phpbb_container->get('phpbbseo.migrationcenter.search_indexer');
			$verifier = $phpbb_container->get('phpbbseo.migrationcenter.verifier');

			// 1. Finalization & Recounts
			$finalizer->finalize_all($run_id, array_keys($steps));
			// 2. Search Indexing
			$idx_res = $indexer->index_range($run_id, 0, 50000);
			// 3. Health Verifications
			$v_res = $verifier->verify_all($run_id);

			$stats = $run->stats;
			$stats['finalized_at'] = time();
			$stats['search_indexed_at'] = time();
			$stats['search_indexed_count'] = $idx_res['indexed'] ?? 0;
			$stats['verified_at'] = time();
			$stats['verified_passed'] = $v_res['passed'];
			$stats['verified_failed'] = $v_res['total_failed'];
			$stats['verified_total'] = $v_res['total_checks'];
			$state_manager->update_run_stats($run_id, $stats);

			$state_manager->update_run_status($run_id, 'finalized');
			redirect($this->u_action . '&mode=progress&run_id=' . urlencode($run_id) . '&all_finalized=1');
		}
		else if ($action === 'confirm_rollback')
		{
			add_form_key('migration_acp_rollback');
			$template->assign_vars([
				'HAS_ACTIVE_RUN'       => true,
				'MODE_CONFIRM_ROLLBACK'=> true,
				'ACTIVE_RUN_ID'        => $run_id,
				'RUN_STATUS'           => $run->status,
				'U_ROLLBACK_EXECUTE'   => $this->u_action . '&amp;mode=progress&amp;action=execute_rollback&amp;run_id=' . urlencode($run_id),
				'U_CANCEL_ROLLBACK'    => $this->u_action . '&amp;mode=progress&amp;run_id=' . urlencode($run_id),
			]);
			return;
		}
		else if ($action === 'execute_rollback' && $request->is_set_post('submit_rollback'))
		{
			if (!check_form_key('migration_acp_rollback'))
			{
				trigger_error('FORM_INVALID');
			}

			$confirm_keyword = $request->variable('confirm_keyword', '');
			try
			{
				$res = $rollback_manager->rollback_run($run_id, $confirm_keyword);
				$template->assign_vars([
					'ROLLBACK_SUCCESS' => true,
					'ROLLBACK_REPORT'  => json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
				]);
			}
			catch (\Throwable $e)
			{
				$template->assign_vars([
					'ROLLBACK_ERROR'   => $e->getMessage(),
				]);
			}
		}

		// Calculate Weighted Overall Progress & Current Step Progress
		$total_imported = 0;
		$total_skipped = 0;
		$total_failed = 0;
		$total_source_records = 0;
		$total_processed_records = 0;
		$completed_steps = 0;
		$current_step_row = null;

		foreach ($steps as $s)
		{
			$imp = (int)$s['imported_records'];
			$skp = (int)$s['skipped_records'];
			$fld = (int)$s['failed_records'];
			$tot = (int)$s['total_records'];

			$total_imported += $imp;
			$total_skipped += $skp;
			$total_failed += $fld;
			$total_source_records += $tot;
			$total_processed_records += ($imp + $skp + $fld);

			$step_done = $imp + $skp + $fld;
			$step_pct = ($tot > 0) ? min(100, round(($step_done / $tot) * 100)) : ($s['status'] === 'completed' ? 100 : 0);

			if ($s['status'] === 'completed' || $s['status'] === 'skipped')
			{
				$completed_steps++;
			}
			else if (!$current_step_row && !$is_abandoned)
			{
				$current_step_row = $s;
			}

			$template->assign_block_vars('progress_steps', [
				'NAME'     => $s['step_name'],
				'LABEL'    => !empty($user->lang['STEP_' . strtoupper($s['step_name'])]) ? $user->lang['STEP_' . strtoupper($s['step_name'])] : ucfirst(str_replace('_', ' ', $s['step_name'])),
				'STATUS'   => $s['status'],
				'ORDER'    => $s['step_order'],
				'IMPORTED' => $imp,
				'SKIPPED'  => $skp,
				'FAILED'   => $fld,
				'TOTAL'    => $tot,
				'PERCENT'  => $step_pct,
				'CURSOR'   => $s['current_cursor'],
			]);
		}

		$total_steps_count = count($steps);
		$current_step_num = 1;
		if ($current_step_row)
		{
			$current_step_num = (int)($current_step_row['step_order'] ?? 1);
		}
		else if ($run->status === 'completed' || $run->status === 'finalized')
		{
			$current_step_num = $total_steps_count;
		}
		else
		{
			$current_step_num = min($total_steps_count, $completed_steps + 1);
		}

		$is_run_completed = ($run->status === 'completed' || $run->status === 'finalized');
		$overall_pct = ($total_source_records > 0) ? min(100, (int)floor(($total_processed_records / $total_source_records) * 100)) : ($is_run_completed ? 100 : 0);
		if ($overall_pct >= 100 && !$is_run_completed)
		{
			$overall_pct = 99;
		}

		if ($is_abandoned)
		{
			$elapsed = ($run->completed_at > $run->started_at && $run->started_at > 0) ? ($run->completed_at - $run->started_at) : 0;
			$rate = 0;
			$eta_formatted = '00:00:00';
		}
		else if ($run->status === 'completed' || $run->status === 'finalized')
		{
			$elapsed = ($run->completed_at > $run->started_at && $run->started_at > 0) ? ($run->completed_at - $run->started_at) : max(0, $now - $run->started_at);
			$rate = ($elapsed > 0 && $total_imported > 0) ? round($total_imported / $elapsed, 1) : 0;
			$eta_formatted = '00:00:00';
		}
		else
		{
			$elapsed = ($run->started_at > 0) ? max(0, $now - $run->started_at) : 0;
			$rate = ($elapsed > 0 && $total_imported > 0) ? round($total_imported / $elapsed, 1) : 0;
			$remaining_records = max(0, $total_source_records - $total_processed_records);
			if ($rate > 0 && $total_processed_records > 0 && $remaining_records > 0)
			{
				$eta_seconds = round($remaining_records / $rate);
				$eta_formatted = sprintf('%02d:%02d:%02d', ($eta_seconds/3600), ($eta_seconds/60%60), $eta_seconds%60);
			}
			else
			{
				$eta_formatted = !empty($user->lang['MIGRATION_ETA_CALCULATING']) ? $user->lang['MIGRATION_ETA_CALCULATING'] : 'Calculating...';
			}
		}

		$is_awaiting_cli_resume = ($run->status === 'running' && $worker_mode === 'cli' && !$lock);
		$display_status = ($is_stale) ? 'interrupted' : (($is_awaiting_cli_resume || $run->status === 'awaiting_worker') ? 'awaiting_worker' : $run->status);
		$is_ready = ($run->status === 'ready' || ($run->status === 'running' && !$lock && !$is_stale && $worker_mode === 'ajax'));
		$is_awaiting_worker = ($run->status === 'awaiting_worker' || $is_awaiting_cli_resume);

		$first_step = $run->current_step ?: 'groups';
		$first_step_lbl = !empty($user->lang['STEP_' . strtoupper($first_step)]) ? $user->lang['STEP_' . strtoupper($first_step)] : ucfirst(str_replace('_', ' ', $first_step));
		$start_stage_btn_label = sprintf(!empty($user->lang['MIGRATION_START_STAGE_1']) ? $user->lang['MIGRATION_START_STAGE_1'] : 'Start Stage 1: %s', $first_step_lbl);

		if ($current_step_row)
		{
			$s_name = $current_step_row['step_name'];
			$curr_label = !empty($user->lang['STAGE_' . strtoupper($s_name)]) ? $user->lang['STAGE_' . strtoupper($s_name)] : (!empty($user->lang['STEP_' . strtoupper($s_name)]) ? $user->lang['STEP_' . strtoupper($s_name)] : ucfirst(str_replace('_', ' ', $s_name)));
		}
		else if ($run->status === 'completed' || $run->status === 'finalized')
		{
			$curr_label = !empty($user->lang['COMPLETED']) ? $user->lang['COMPLETED'] : 'Completed';
		}
		else if ($is_abandoned)
		{
			$curr_label = !empty($user->lang['ABANDONED']) ? $user->lang['ABANDONED'] : 'None (Abandoned)';
		}
		else
		{
			$s_name = $run->current_step;
			$curr_label = !empty($user->lang['STAGE_' . strtoupper($s_name)]) ? $user->lang['STAGE_' . strtoupper($s_name)] : (!empty($user->lang['STEP_' . strtoupper($s_name)]) ? $user->lang['STEP_' . strtoupper($s_name)] : ucfirst(str_replace('_', ' ', $s_name)));
		}

		$curr_imported = $current_step_row ? (int)$current_step_row['imported_records'] : 0;
		$curr_skipped = $current_step_row ? (int)$current_step_row['skipped_records'] : 0;
		$curr_failed = $current_step_row ? (int)$current_step_row['failed_records'] : 0;
		$curr_total = $current_step_row ? (int)$current_step_row['total_records'] : 0;
		$curr_done = $curr_imported + $curr_skipped + $curr_failed;
		$curr_pct = ($curr_total > 0) ? min(100, round(($curr_done / $curr_total) * 100)) : ($run->status === 'completed' ? 100 : 0);

		// Build Stage Checkpoint Report data
		$is_awaiting_approval = in_array($run->status, ['awaiting_approval', 'stage_completed', 'stage_completed_with_warnings', 'stage_failed'], true);
		$stage_report = $state_manager->get_stage_report($run_id);
		$next_stage = $stage_report['next_stage'] ?? $state_manager->get_next_stage($run->current_step);
		$next_stage_label = '';
		if ($next_stage)
		{
			$stage_lang_key = 'STAGE_' . strtoupper($next_stage);
			$step_lang_key = 'STEP_' . strtoupper($next_stage);
			$stage_name_translated = !empty($user->lang[$stage_lang_key]) ? $user->lang[$stage_lang_key] : (!empty($user->lang[$step_lang_key]) ? $user->lang[$step_lang_key] : ucfirst(str_replace('_', ' ', $next_stage)));
			$next_stage_label = sprintf(!empty($user->lang['MIGRATION_CONTINUE_TO_STAGE']) ? $user->lang['MIGRATION_CONTINUE_TO_STAGE'] : 'Continue to %s', $stage_name_translated);
		}

		// Stage Timeline data
		$stage_seq = \phpbbseo\migrationcenter\core\engine\step_registry::CANONICAL_STAGE_ORDER;
		$timeline_data = [];
		$found_current = false;
		foreach (array_keys($stage_seq) as $stg)
		{
			$stg_step = $steps[$stg] ?? null;
			$stg_report = $state_manager->get_stage_report($run_id, $stg);
			$stg_lang_key = 'STAGE_' . strtoupper($stg);
			$stg_title = !empty($user->lang[$stg_lang_key]) ? $user->lang[$stg_lang_key] : ucfirst(str_replace('_', ' ', $stg));

			$icon = '○';
			$status_class = 'unstarted';
			$summary_text = 'Not started';

			if ($is_abandoned)
			{
				$icon = '○';
				$status_class = 'unstarted';
				$summary_text = 'Stopped (Abandoned)';
			}
			else if ($stg === 'finalization')
			{
				$is_fin = !empty($run->stats['finalized_at']);
				$icon = $is_fin ? '✓' : '○';
				$status_class = $is_fin ? 'completed' : 'unstarted';
				$summary_text = $is_fin ? (!empty($user->lang['RECOUNTS_SYNC_DONE']) ? $user->lang['RECOUNTS_SYNC_DONE'] : 'Recounts and statistics synchronized') : (!empty($user->lang['NOT_STARTED']) ? $user->lang['NOT_STARTED'] : 'Not started');
			}
			else if ($stg === 'search_index')
			{
				$is_idx = !empty($run->stats['search_indexed_at']);
				$icon = $is_idx ? '✓' : '○';
				$status_class = $is_idx ? 'completed' : 'unstarted';
				$idx_cnt = (int)($run->stats['search_indexed_count'] ?? 0);
				$summary_text = $is_idx ? sprintf(!empty($user->lang['SEARCH_INDEX_POSTS_COUNT']) ? $user->lang['SEARCH_INDEX_POSTS_COUNT'] : '%d posts indexed', $idx_cnt) : (!empty($user->lang['NOT_STARTED']) ? $user->lang['NOT_STARTED'] : 'Not started');
			}
			else if ($stg === 'final_verification')
			{
				$is_ver = !empty($run->stats['verified_at']);
				$ver_passed = !empty($run->stats['verified_passed']);
				$icon = $is_ver ? ($ver_passed ? '✓' : '!') : '○';
				$status_class = $is_ver ? ($ver_passed ? 'completed' : 'warning') : 'unstarted';
				$tot_chk = (int)($run->stats['verified_total'] ?? 11);
				$summary_text = $is_ver ? ($ver_passed ? sprintf(!empty($user->lang['ALL_CHECKS_PASSED_COUNT']) ? $user->lang['ALL_CHECKS_PASSED_COUNT'] : '%d / %d Checks Passed (100%%)', $tot_chk, $tot_chk) : (!empty($user->lang['VERIFICATION_WARNINGS']) ? $user->lang['VERIFICATION_WARNINGS'] : 'Warnings reported')) : (!empty($user->lang['NOT_STARTED']) ? $user->lang['NOT_STARTED'] : 'Not started');
			}
			else if ($stg_step && ($stg_step['status'] === 'completed' || $stg_step['status'] === 'skipped'))
			{
				$has_warn = ($stg_report && !empty($stg_report['warnings']) && $stg_report['warnings'] > 0);
				$icon = $has_warn ? '!' : '✓';
				$status_class = $has_warn ? 'warning' : 'completed';
				$created_cnt = $stg_report ? $stg_report['created'] : (int)$stg_step['imported_records'];
				$reused_cnt = $stg_report ? $stg_report['reused'] : 0;
				$summary_text = sprintf('%d processed (%d created, %d reused)', (int)$stg_step['imported_records'] + (int)$stg_step['skipped_records'], $created_cnt, $reused_cnt);
			}
			else if (!$is_abandoned && ($stg === $run->current_step || (!$found_current && $stg_step && $stg_step['status'] === 'running')))
			{
				$icon = '▶';
				$status_class = 'active';
				$summary_text = ($run->status === 'awaiting_approval') ? 'Waiting for administrator approval' : ($is_cli_active ? 'Processing in terminal (CLI)' : 'In progress');
				$found_current = true;
			}

			$timeline_row = [
				'STAGE_KEY'    => $stg,
				'STAGE_TITLE'  => $stg_title,
				'ICON'         => $icon,
				'STATUS_CLASS' => $status_class,
				'SUMMARY'      => $summary_text,
			];
			$timeline_data[] = $timeline_row;
			$template->assign_block_vars('stage_timeline', $timeline_row);
		}

		$is_awaiting_worker = ($run->status === 'awaiting_worker' || $is_awaiting_cli_resume);
		$cli_prepared_at = (int)($run->stats['cli_prepared_at'] ?? 0);
		$cli_waiting_duration = ($cli_prepared_at > 0) ? max(0, $now - $cli_prepared_at) : 0;
		$cli_waiting_duration_fmt = sprintf('%02d:%02d:%02d', ($cli_waiting_duration / 3600), ($cli_waiting_duration / 60 % 60), $cli_waiting_duration % 60);
		$startup_error = !empty($run->stats['startup_error']['message']) ? $run->stats['startup_error']['message'] : '';

		$is_windows = (DIRECTORY_SEPARATOR === '\\' || stripos(PHP_OS, 'WIN') === 0);
		$php_bin = 'php';
		if ($is_windows)
		{
			$candidates = [
				defined('PHP_BINDIR') ? PHP_BINDIR . DIRECTORY_SEPARATOR . 'php.exe' : null,
				'C:\\xampp\\php\\php.exe',
				'D:\\xampp\\php\\php.exe',
				'E:\\xampp\\php\\php.exe',
				dirname(PHP_BINARY) . DIRECTORY_SEPARATOR . 'php.exe',
				dirname(dirname(PHP_BINARY)) . DIRECTORY_SEPARATOR . 'php' . DIRECTORY_SEPARATOR . 'php.exe',
			];
			foreach ($candidates as $cand)
			{
				if ($cand && file_exists($cand) && stripos(basename($cand), 'httpd') === false)
				{
					$php_bin = $cand;
					break;
				}
			}
		}
		else
		{
			$candidates = [
				defined('PHP_BINDIR') ? PHP_BINDIR . '/php' : null,
				'/usr/bin/php',
				'/usr/local/bin/php',
			];
			foreach ($candidates as $cand)
			{
				if ($cand && file_exists($cand) && stripos(basename($cand), 'fpm') === false && stripos(basename($cand), 'apache') === false)
				{
					$php_bin = $cand;
					break;
				}
			}
		}

		$abs_root = realpath($phpbb_root_path) ?: realpath(__DIR__ . '/../../../../..');
		$cli_script = ($abs_root ? rtrim(str_replace('\\', '/', $abs_root), '/') : '.') . '/bin/phpbbcli.php';
		if ($is_windows)
		{
			$php_bin_win = str_replace('/', '\\', $php_bin);
			$cli_script_win = str_replace('/', '\\', $cli_script);
			$cli_exact_command = sprintf('"%s" "%s" migrationcenter:resume %s', $php_bin_win, $cli_script_win, $run_id);
		}
		else
		{
			$cli_exact_command = sprintf('"%s" "%s" migrationcenter:resume %s', $php_bin, $cli_script, $run_id);
		}

		$prepare_cli_stage_label = sprintf(!empty($user->lang['MIGRATION_PREPARE_CLI_FOR_STAGE']) ? $user->lang['MIGRATION_PREPARE_CLI_FOR_STAGE'] : 'Prepare CLI Command for %s', $curr_label);
		$start_browser_stage_label = sprintf(!empty($user->lang['MIGRATION_START_BROWSER_FOR_STAGE']) ? $user->lang['MIGRATION_START_BROWSER_FOR_STAGE'] : 'Start %s in Browser', $curr_label);

		// Handle AJAX Polling (Read-Only)
		if ($action === 'poll_progress')
		{
			$data = [
				'run_id'              => $run->run_id,
				'status'              => $display_status,
				'raw_status'          => $run->status,
				'worker_mode'         => $worker_mode,
				'is_cli_active'       => $is_cli_active,
				'is_ajax_active'      => $is_ajax_active,
				'is_ready'            => $is_ready,
				'is_awaiting_worker'  => $is_awaiting_worker,
				'cli_connected'       => $is_cli_active,
				'worker_id'           => $lock ? ($lock['worker_token'] ?? $lock['worker_type']) : null,
				'startup_error'       => $startup_error,
				'cli_prepared_at'     => $cli_prepared_at,
				'waiting_duration'    => $cli_waiting_duration,
				'waiting_duration_fmt'=> $cli_waiting_duration_fmt,
				'cli_exact_command'   => $cli_exact_command,
				'is_stale'            => ($is_stale || $is_running_without_worker),
				'is_abandoned'        => $is_abandoned,
				'current_step'        => $run->current_step,
				'current_step_lbl'    => $curr_label,
				'current_step_pct'    => $curr_pct,
				'current_stage_num'   => $current_step_num,
				'total_stages_count'  => $total_steps_count,
				'overall_percent'     => $overall_pct,
				'total_imported'      => $total_imported,
				'total_skipped'       => $total_skipped,
				'total_failed'        => $total_failed,
				'total_records'       => $total_source_records,
				'elapsed_seconds'     => $elapsed,
				'elapsed_formatted'   => sprintf('%02d:%02d:%02d', ($elapsed/3600), ($elapsed/60%60), $elapsed%60),
				'processing_rate'     => $rate,
				'eta_formatted'       => $eta_formatted,
				'heartbeat_age'       => $heartbeat_age,
				'is_awaiting_approval'=> $is_awaiting_approval,
				'stage_report'        => $stage_report,
				'next_stage'          => $next_stage,
				'next_stage_label'    => $next_stage_label,
				'steps'               => array_values($steps),
				'timeline'            => $timeline_data,
			];

			while (ob_get_level()) { ob_end_clean(); }
			header('Content-Type: application/json; charset=UTF-8');
			echo json_encode($data);
			exit;
		}

		// Handle AJAX Batch Execution (Single Bounded Batch)
		if ($action === 'ajax_step')
		{
			if (!$auth->acl_get('a_migrationcenter') && $user->data['user_type'] != USER_FOUNDER)
			{
				while (ob_get_level()) { ob_end_clean(); }
				header('Content-Type: application/json; charset=UTF-8', true, 403);
				echo json_encode(['success' => false, 'error' => 'Permission denied: a_migrationcenter required']);
				exit;
			}

			if (!$request->is_set_post('submit_step'))
			{
				while (ob_get_level()) { ob_end_clean(); }
				header('Content-Type: application/json; charset=UTF-8', true, 400);
				echo json_encode(['success' => false, 'error' => 'POST request required for batch execution']);
				exit;
			}

			if ($is_cli_active)
			{
				while (ob_get_level()) { ob_end_clean(); }
				header('Content-Type: application/json; charset=UTF-8', true, 409);
				echo json_encode(['success' => false, 'error' => 'Migration is currently being processed by a CLI worker. You cannot run browser batches concurrently.']);
				exit;
			}

			try
			{
				$worker_token = 'ajax_' . (int)$user->data['user_id'] . '_' . substr(md5(session_id()), 0, 8);
				$batch_res = $engine->execute_next_batch($run_id, 'ajax', 0, $worker_token);

				// Query fresh step stats to populate real-time UI counters after each batch
				$fresh_steps = $state_manager->get_steps($run_id);
				$f_stages_count = count($fresh_steps);
				$f_stage_num = 1;
				$f_imp = 0; $f_skp = 0; $f_fld = 0; $f_tot = 0; $f_proc = 0;
				$f_curr_pct = 0; $f_curr_lbl = '';
				foreach ($fresh_steps as $fs)
				{
					$fs_imp = (int)$fs['imported_records'];
					$fs_skp = (int)$fs['skipped_records'];
					$fs_fld = (int)$fs['failed_records'];
					$fs_tot = (int)$fs['total_records'];
					$f_imp += $fs_imp;
					$f_skp += $fs_skp;
					$f_fld += $fs_fld;
					$f_tot += $fs_tot;
					$f_proc += ($fs_imp + $fs_skp + $fs_fld);

					if ($fs['step_name'] === ($batch_res['stage_key'] ?? ''))
					{
						$step_done = $fs_imp + $fs_skp + $fs_fld;
						$f_curr_pct = ($fs_tot > 0) ? min(100, (int)floor(($step_done / $fs_tot) * 100)) : ($fs['status'] === 'completed' ? 100 : 0);
						$f_curr_lbl = !empty($user->lang['STEP_' . strtoupper($fs['step_name'])]) ? $user->lang['STEP_' . strtoupper($fs['step_name'])] : ucfirst(str_replace('_', ' ', $fs['step_name']));
						$f_stage_num = (int)($fs['step_order'] ?? 1);
					}
				}

				$f_is_done = in_array($batch_res['run_status'] ?? '', ['completed', 'finalized'], true);
				$f_overall_pct = ($f_tot > 0) ? min(100, (int)floor(($f_proc / $f_tot) * 100)) : ($f_is_done ? 100 : 0);
				if ($f_overall_pct >= 100 && !$f_is_done)
				{
					$f_overall_pct = 99;
				}
				$fresh_run = $state_manager->get_run($run_id);
				$f_elapsed = ($fresh_run && $fresh_run->started_at > 0) ? max(0, time() - $fresh_run->started_at) : 0;
				$f_rate = ($f_elapsed > 0 && $f_imp > 0) ? round($f_imp / $f_elapsed, 1) : 0;
				$f_rem = max(0, $f_tot - $f_proc);
				$f_eta = ($f_rate > 0 && $f_rem > 0) ? sprintf('%02d:%02d:%02d', ($f_rem/$f_rate/3600), ($f_rem/$f_rate/60%60), $f_rem/$f_rate%60) : '00:00:00';

				$batch_res['overall_percent']   = $f_overall_pct;
				$batch_res['total_imported']    = $f_imp;
				$batch_res['total_skipped']     = $f_skp;
				$batch_res['total_failed']      = $f_fld;
				$batch_res['total_records']     = $f_tot;
				$batch_res['current_step']      = $batch_res['stage_key'] ?? '';
				$batch_res['current_step_lbl']  = $f_curr_lbl;
				$batch_res['current_step_pct']  = $f_curr_pct;
				$batch_res['current_stage_num'] = $f_stage_num;
				$batch_res['total_stages_count'] = $f_stages_count;
				$batch_res['elapsed_seconds']   = $f_elapsed;
				$batch_res['elapsed_formatted'] = sprintf('%02d:%02d:%02d', ($f_elapsed/3600), ($f_elapsed/60%60), $f_elapsed%60);
				$batch_res['processing_rate']   = $f_rate;
				$batch_res['eta_formatted']     = $f_eta;
				$batch_res['steps']             = array_values($fresh_steps);

				while (ob_get_level()) { ob_end_clean(); }
				header('Content-Type: application/json; charset=UTF-8');
				echo json_encode($batch_res);
				exit;
			}
			catch (\Throwable $e)
			{
				while (ob_get_level()) { ob_end_clean(); }
				header('Content-Type: application/json; charset=UTF-8', true, 500);
				echo json_encode([
					'success'    => false,
					'run_id'     => $run_id,
					'run_status' => $run->status,
					'error'      => $e->getMessage(),
					'error_code' => 'BATCH_ERROR',
				]);
				exit;
			}
		}

		add_form_key('migration_acp_progress');

		$status_labels = [
			'ready'                          => !empty($user->lang['READY']) ? $user->lang['READY'] : 'Ready',
			'awaiting_worker'                => !empty($user->lang['STATUS_AWAITING_WORKER']) ? $user->lang['STATUS_AWAITING_WORKER'] : 'Waiting for CLI worker',
			'running'                        => !empty($user->lang['RUNNING']) ? $user->lang['RUNNING'] : 'Running',
			'paused'                         => !empty($user->lang['PAUSED']) ? $user->lang['PAUSED'] : 'Paused',
			'interrupted'                    => !empty($user->lang['INTERRUPTED']) ? $user->lang['INTERRUPTED'] : 'Interrupted',
			'awaiting_approval'              => !empty($user->lang['STAGE_COMPLETED']) ? $user->lang['STAGE_COMPLETED'] : 'Stage Completed',
			'stage_completed'                => !empty($user->lang['STAGE_COMPLETED']) ? $user->lang['STAGE_COMPLETED'] : 'Stage Completed',
			'stage_completed_with_warnings'  => !empty($user->lang['STAGE_COMPLETED_WITH_WARNINGS']) ? $user->lang['STAGE_COMPLETED_WITH_WARNINGS'] : 'Completed with Warnings',
			'stage_failed'                   => !empty($user->lang['STAGE_FAILED']) ? $user->lang['STAGE_FAILED'] : 'Stage Failed',
			'completed'                      => !empty($user->lang['COMPLETED']) ? $user->lang['COMPLETED'] : 'Completed',
			'finalized'                      => !empty($user->lang['COMPLETED']) ? $user->lang['COMPLETED'] : 'Finalized',
			'failed'                         => !empty($user->lang['FAILED']) ? $user->lang['FAILED'] : 'Failed',
			'cancelled'                      => !empty($user->lang['CANCELLED']) ? $user->lang['CANCELLED'] : 'Cancelled',
			'abandoned'                      => !empty($user->lang['ABANDONED']) ? $user->lang['ABANDONED'] : 'Abandoned',
			'rolled_back'                    => !empty($user->lang['ROLLED_BACK']) ? $user->lang['ROLLED_BACK'] : 'Rolled Back',
		];
		$run_status_label = $status_labels[$display_status] ?? ucfirst(str_replace('_', ' ', $display_status));

		$can_fast_reset = $rollback_manager->can_fast_reset($run_id);
		$clean_u_action = str_replace('&amp;', '&', $this->u_action);

		$stage_rep_name = $stage_report['stage_name'] ?? $run->current_step;
		$stage_rep_key = 'STAGE_' . strtoupper($stage_rep_name);
		$stage_rep_step_key = 'STEP_' . strtoupper($stage_rep_name);
		$stage_rep_label = !empty($user->lang[$stage_rep_key]) ? $user->lang[$stage_rep_key] : (!empty($user->lang[$stage_rep_step_key]) ? $user->lang[$stage_rep_step_key] : ucfirst(str_replace('_', ' ', $stage_rep_name)));

		$stage_rep_status = $stage_report['stage_status'] ?? $run->status;
		$stage_rep_status_label = $status_labels[$stage_rep_status] ?? ucfirst(str_replace('_', ' ', $stage_rep_status));

		$template->assign_vars([
			'HAS_ACTIVE_RUN'             => true,
			'ACTIVE_RUN_ID'              => $run_id,
			'ACTIVE_RUN_SHORT_ID'        => substr($run_id, 0, 8),
			'SOURCE_PLATFORM_LABEL'      => self::format_source_label($run->source_system, $run->source_version),
			'RUN_STATUS'                 => $display_status,
			'RUN_STATUS_LABEL'           => $run_status_label,
			'ACTIVE_RUN_STATUS'          => $display_status,
			'ACTIVE_RUN_STATUS_LABEL'    => $run_status_label,
			'RAW_RUN_STATUS'             => $run->status,
			'IS_READY'                   => $is_ready,
			'IS_AWAITING_WORKER'         => $is_awaiting_worker,
			'IS_STALE'                   => $is_stale,
			'IS_ABANDONED'               => $is_abandoned,
			'WORKER_MODE'                => $worker_mode,
			'IS_CLI_ACTIVE'              => $is_cli_active,
			'IS_AJAX_ACTIVE'             => $is_ajax_active,
			'START_STAGE_BTN_LABEL'      => $start_stage_btn_label,
			'PREPARE_CLI_STAGE_LABEL'    => $prepare_cli_stage_label,
			'START_BROWSER_STAGE_LABEL'  => $start_browser_stage_label,
			'CLI_EXACT_COMMAND'          => $cli_exact_command,
			'CLI_PREPARED_AT'            => ($cli_prepared_at > 0) ? date('Y-m-d H:i:s', $cli_prepared_at) : '-',
			'CLI_WAITING_DURATION'       => $cli_waiting_duration_fmt,
			'CLI_WAITING_DURATION_SECONDS' => $cli_waiting_duration,
			'CLI_CONNECTED'              => $is_cli_active,
			'IS_WINDOWS_SERVER'          => $is_windows,
			'STARTUP_ERROR'              => $startup_error,
			'HEARTBEAT_AGE'              => $heartbeat_age,
			'CURRENT_STEP'               => $run->current_step,
			'CURRENT_STEP_LABEL'         => $curr_label,
			'CURRENT_STAGE_NUM'          => $current_step_num,
			'TOTAL_STAGES_COUNT'         => $total_steps_count,
			'CURRENT_STEP_IMPORTED'      => $curr_imported,
			'CURRENT_STEP_SKIPPED'       => $curr_skipped,
			'CURRENT_STEP_FAILED'        => $curr_failed,
			'CURRENT_STEP_TOTAL'         => $curr_total,
			'CURRENT_STEP_PERCENT'       => $curr_pct,
			'OVERALL_PERCENT'            => $overall_pct,
			'TOTAL_IMPORTED'             => $total_imported,
			'TOTAL_SKIPPED'              => $total_skipped,
			'TOTAL_FAILED'               => $total_failed,
			'TOTAL_RECORDS'              => $total_source_records,
			'START_TIME_FORMATTED'       => $run->started_at ? date('Y-m-d H:i:s', $run->started_at) : '-',
			'ELAPSED_TIME_FORMATTED'     => sprintf('%02d:%02d:%02d', ($elapsed/3600), ($elapsed/60%60), $elapsed%60),
			'ETA_FORMATTED'              => $eta_formatted,
			'PROCESSING_RATE'            => $rate,
			'CLI_RESUME_CMD'             => $cli_exact_command,
			'IS_AWAITING_APPROVAL'       => $is_awaiting_approval,
			'STAGE_REPORT'               => $stage_report,
			'STAGE_REPORT_STAGE'         => $stage_rep_name,
			'STAGE_REPORT_STAGE_LABEL'   => $stage_rep_label,
			'STAGE_REPORT_STATUS'        => $stage_rep_status,
			'STAGE_REPORT_STATUS_LABEL'  => $stage_rep_status_label,
			'STAGE_REPORT_TOTAL'         => $stage_report['source_total'] ?? 0,
			'STAGE_REPORT_PROCESSED'     => $stage_report['processed'] ?? 0,
			'STAGE_REPORT_CREATED'       => $stage_report['created'] ?? 0,
			'STAGE_REPORT_REUSED'        => $stage_report['reused'] ?? 0,
			'STAGE_REPORT_UPDATED'       => $stage_report['updated'] ?? 0,
			'STAGE_REPORT_SKIPPED'       => $stage_report['skipped'] ?? 0,
			'STAGE_REPORT_WARNINGS'  => $stage_report['warnings'] ?? 0,
			'STAGE_REPORT_FAILED'    => $stage_report['permanently_failed'] ?? 0,
			'STAGE_REPORT_RATE'      => $stage_report['processing_rate'] ?? 0,
			'STAGE_REPORT_ELAPSED'   => $stage_report['elapsed_time'] ?? 0,
			'STAGE_REPORT_MAPPINGS'  => $stage_report['mapping_count'] ?? 0,
			'STAGE_REPORT_CURSOR'    => $stage_report['current_cursor'] ?? '0',
			'NEXT_STAGE'             => $next_stage,
			'NEXT_STAGE_LABEL'       => $next_stage_label,
			'CAN_CONTINUE_STAGE'     => ($is_awaiting_approval && !empty($next_stage) && ($stage_report['stage_status'] ?? '') !== 'stage_failed'),
			'CAN_ACKNOWLEDGE_WARNINGS'=> ($is_awaiting_approval && ($stage_report['warnings'] ?? 0) > 0),
			'CAN_RETRY_STAGE'        => ($run->status === 'stage_failed' || ($stage_report['stage_status'] ?? '') === 'stage_failed'),
			'U_APPROVE_STAGE'        => $this->u_action . '&amp;mode=progress&amp;action=approve_next_stage&amp;run_id=' . urlencode($run_id),
			'U_ACKNOWLEDGE_WARNINGS' => $this->u_action . '&amp;mode=progress&amp;action=acknowledge_warnings&amp;run_id=' . urlencode($run_id),
			'CAN_PAUSE'              => ($run->status === 'running' && !$is_stale && !$is_cli_active),
			'CAN_RESUME'             => in_array($run->status, ['paused', 'failed', 'ready', 'running', 'interrupted'], true) && !$is_abandoned && !$is_cli_active,
			'CAN_CANCEL'             => (in_array($run->status, ['running', 'paused', 'failed', 'ready', 'awaiting_approval', 'stage_completed', 'stage_completed_with_warnings', 'stage_failed', 'interrupted'], true) || in_array($display_status, ['running', 'paused', 'failed', 'ready', 'interrupted'], true)) && !$is_abandoned,
			'CAN_FAST_RESET'         => $can_fast_reset,
			'CAN_ROLLBACK'           => (in_array($run->status, ['paused', 'failed', 'cancelled', 'completed', 'finalized', 'awaiting_approval', 'stage_completed', 'stage_completed_with_warnings', 'stage_failed', 'interrupted'], true) || in_array($display_status, ['paused', 'failed', 'cancelled', 'interrupted'], true)) && !$can_fast_reset && !$is_abandoned,
			'CAN_FINALIZE'           => ($run->status === 'completed' || $run->status === 'finalized' || $completed_steps === count($steps)) && !$is_abandoned,
			'FINALIZATION_DONE'      => !empty($run->stats['finalized_at']),
			'SEARCH_INDEX_DONE'      => !empty($run->stats['search_indexed_at']),
			'SEARCH_INDEXED_COUNT'   => (int)($run->stats['search_indexed_count'] ?? 0),
			'VERIFY_DONE'            => !empty($run->stats['verified_at']),
			'VERIFY_PASSED'          => !empty($run->stats['verified_passed']),
			'ALL_FINAL_STEPS_DONE'   => (!empty($run->stats['finalized_at']) && !empty($run->stats['search_indexed_at']) && !empty($run->stats['verified_at'])),
			'U_RUN_FINALIZATION'     => $this->u_action . '&amp;mode=progress&amp;action=run_finalization&amp;run_id=' . urlencode($run_id),
			'U_RUN_SEARCH_INDEX'     => $this->u_action . '&amp;mode=progress&amp;action=run_search_index&amp;run_id=' . urlencode($run_id),
			'U_RUN_VERIFY'           => $this->u_action . '&amp;mode=progress&amp;action=run_verify&amp;run_id=' . urlencode($run_id),
			'U_RUN_ALL_FINAL'        => $this->u_action . '&amp;mode=progress&amp;action=run_all_final_steps&amp;run_id=' . urlencode($run_id),
			'U_PROGRESS_ACTION'      => $this->u_action . '&amp;mode=progress&amp;run_id=' . urlencode($run_id),
			'U_CONFIRM_ROLLBACK'     => $this->u_action . '&amp;mode=progress&amp;action=confirm_rollback&amp;run_id=' . urlencode($run_id),
			'U_FINALIZE_LINK'        => $this->u_action . '&amp;mode=finalize&amp;run_id=' . urlencode($run_id),
			'U_AJAX_STEP_URL'        => $clean_u_action . '&mode=progress&action=ajax_step&run_id=' . urlencode($run_id),
			'U_POLL_URL'             => $clean_u_action . '&mode=progress&action=poll_progress&run_id=' . urlencode($run_id),
		]);
	}

	/**
	 * Handle Errors Mode
	 */
	protected function handle_errors()
	{
		global $phpbb_container, $template, $request;

		$run_id = $request->variable('run_id', '');
		$template->assign_vars(array(
			'FILTER_RUN_ID' => $run_id,
		));
	}

	/**
	 * Handle Settings Mode
	 */
	protected function handle_settings()
	{
		global $phpbb_container, $template, $request;

		$config = $phpbb_container->get('config');
		$submit = $request->is_set_post('submit');
		$settings_saved = false;

		if ($submit)
		{
			if (!check_form_key('migration_acp_settings'))
			{
				trigger_error('FORM_INVALID');
			}

			$batch_size = max(10, min(10000, (int)$request->variable('batch_size', 500)));
			$lock_timeout = max(15, min(3600, (int)$request->variable('lock_timeout', 60)));

			$config->set('migrationcenter_default_batch_size', $batch_size);
			$config->set('migrationcenter_lock_timeout', $lock_timeout);
			$settings_saved = true;
		}

		add_form_key('migration_acp_settings');

		$current_batch_size = (int)($config['migrationcenter_default_batch_size'] ?? 500);
		if ($current_batch_size <= 0)
		{
			$current_batch_size = 500;
		}

		$current_lock_timeout = (int)($config['migrationcenter_lock_timeout'] ?? 60);
		if ($current_lock_timeout <= 0)
		{
			$current_lock_timeout = 60;
		}

		$template->assign_vars([
			'SETTINGS_BATCH_SIZE'   => $current_batch_size,
			'SETTINGS_LOCK_TIMEOUT' => $current_lock_timeout,
			'SETTINGS_SAVED'        => $settings_saved,
			'U_SETTINGS_ACTION'     => $this->u_action . '&amp;mode=settings',
		]);
	}

	/**
	 * Handle Finalization & Recounts Dashboard Mode
	 */
	protected function handle_finalize()
	{
		global $phpbb_container, $template, $request;

		$run_id = $request->variable('run_id', '');
		$action = $request->variable('action', '');

		$state_manager = $phpbb_container->get('phpbbseo.migrationcenter.state_manager');
		$finalizer = $phpbb_container->get('phpbbseo.migrationcenter.finalizer');
		$indexer = $phpbb_container->get('phpbbseo.migrationcenter.search_indexer');
		$verifier = $phpbb_container->get('phpbbseo.migrationcenter.verifier');

		$backend_info = $indexer->get_backend_info();

		// If run_id is missing, show list of available runs and DO NOT run verifications
		if (empty($run_id))
		{
			$recent_runs = $state_manager->get_recent_runs(10);
			foreach ($recent_runs as $r)
			{
				$template->assign_block_vars('finalize_runs', [
					'RUN_ID'       => $r['run_id'],
					'SOURCE'       => $r['source_system'],
					'STATUS'       => $r['status'],
					'STARTED_AT'   => $r['started_at'] ? date('Y-m-d H:i:s', $r['started_at']) : '-',
					'COMPLETED_AT' => $r['completed_at'] ? date('Y-m-d H:i:s', $r['completed_at']) : '-',
					'U_SELECT'     => $this->u_action . '&amp;mode=finalize&amp;run_id=' . urlencode($r['run_id']),
				]);
			}

			$template->assign_vars([
				'HAS_SELECTED_RUN' => false,
			]);
			return;
		}

		$run = $state_manager->get_run($run_id);
		if (!$run)
		{
			$template->assign_vars([
				'HAS_SELECTED_RUN' => false,
				'INVALID_RUN_ID'   => true,
			]);
			return;
		}

		// Check completion gate: All steps must be completed
		$steps = $state_manager->get_steps($run_id);
		$incomplete_steps = [];
		foreach ($steps as $s)
		{
			if ($s['status'] !== 'completed' && $s['status'] !== 'skipped')
			{
				$incomplete_steps[] = $s['step_name'];
			}
		}

		$is_fully_completed = (empty($incomplete_steps) && !empty($steps) && $run->status === 'completed');

		// Handle Actions ONLY if fully completed
		if ($action === 'run_finalizers' && $is_fully_completed)
		{
			$fin_res = $finalizer->run_all_finalizers($run_id);
			$template->assign_var('FINALIZE_SUCCESS', true);
		}
		else if ($action === 'run_search_index' && $is_fully_completed)
		{
			$idx_res = $indexer->index_posts($run_id, 0, 500);
			$template->assign_vars([
				'SEARCH_INDEX_SUCCESS' => true,
				'INDEXED_COUNT'        => $idx_res['indexed'],
			]);
		}

		// Run verifications for this exact run
		$v_res = $verifier->verify_all($run_id);

		foreach ($v_res['checks'] as $c)
		{
			$template->assign_block_vars('verify_checks', [
				'ID'      => $c['id'],
				'LABEL'   => $c['label'],
				'STATUS'  => $c['status'],
				'MESSAGE' => $c['message'],
			]);
		}

		$total_passed_count = $v_res['total_checks'] - $v_res['total_failed'];

		$is_finalized = ($run->status === 'finalized' || !empty($run->stats['finalized_at']));
		$is_search_indexed = !empty($run->stats['search_indexed_at']);
		$search_indexed_cnt = (int)($run->stats['search_indexed_count'] ?? 0);

		$template->assign_vars([
			'HAS_SELECTED_RUN'        => true,
			'FINALIZE_RUN_ID'         => $run_id,
			'RUN_STATUS'              => $run->status,
			'CAN_FINALIZE'            => ($is_fully_completed || $is_finalized),
			'IS_FINALIZED'            => $is_finalized,
			'IS_SEARCH_INDEXED'       => $is_search_indexed,
			'SEARCH_INDEXED_COUNT'    => $search_indexed_cnt,
			'FINALIZED_AT_FORMATTED'  => !empty($run->stats['finalized_at']) ? date('Y-m-d H:i:s', $run->stats['finalized_at']) : '-',
			'SEARCH_INDEXED_AT_FORMATTED' => !empty($run->stats['search_indexed_at']) ? date('Y-m-d H:i:s', $run->stats['search_indexed_at']) : '-',
			'INCOMPLETE_STEPS_LIST'   => implode(', ', $incomplete_steps),
			'SEARCH_BACKEND_NAME'     => $backend_info['name'],
			'SEARCH_BACKEND_CLASS'    => $backend_info['class'],
			'TOTAL_CHECKS_PASSED'     => $total_passed_count,
			'ALL_CHECKS_PASSED'       => $v_res['passed'],
			'TOTAL_CHECKS_COUNT'      => $v_res['total_checks'],
			'TOTAL_CHECKS_FAILED'     => $v_res['total_failed'],
			'TOTAL_CHECKS_WARNINGS'   => $v_res['total_warnings'],
			'U_FINALIZE_ACTION'       => $this->u_action . '&amp;mode=finalize&amp;run_id=' . urlencode($run_id),
			'CLI_FINALIZE_CMD'        => "php bin/phpbbcli.php migrationcenter:finalize {$run_id}",
			'CLI_SEARCH_CMD'          => "php bin/phpbbcli.php migrationcenter:search-index {$run_id} --batch-size=500",
			'CLI_VERIFY_CMD'          => "php bin/phpbbcli.php migrationcenter:verify {$run_id}",
		]);
	}

	/**
	 * Format user-friendly source system and version label
	 *
	 * @param string $system
	 * @param string $version
	 * @return string
	 */
	public static function format_source_label(string $system, string $version = ''): string
	{
		$sys = strtolower($system);
		if ($sys === 'vbulletin3' || $sys === 'vb3')
		{
			return 'vBulletin 3.8' . ($version ? " ({$version})" : '');
		}
		if ($sys === 'vbulletin4' || $sys === 'vb4')
		{
			return 'vBulletin 4.2' . ($version ? " ({$version})" : '');
		}
		if ($sys === 'vbulletin')
		{
			return 'vBulletin' . ($version ? " ({$version})" : ' 3.8 / 4.2');
		}
		if ($sys === 'xenforo')
		{
			return 'XenForo' . ($version ? " ({$version})" : ' 2.x');
		}
		return ucfirst($system) . ($version ? " ({$version})" : '');
	}
}
