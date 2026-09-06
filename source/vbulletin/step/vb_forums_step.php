<?php
/**
 * phpBB Migration Center Extension
 *
 * @copyright (c) 2026 phpBB SEO Team
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace phpbbseo\migrationcenter\source\vbulletin\step;

use phpbbseo\migrationcenter\core\contract\step_interface;
use phpbbseo\migrationcenter\core\contract\source_provider_interface;
use phpbbseo\migrationcenter\core\contract\target_writer_interface;
use phpbbseo\migrationcenter\core\dto\forum_dto;
use phpbbseo\migrationcenter\core\dto\migration_config_dto;
use phpbbseo\migrationcenter\core\dto\step_result_dto;
use phpbbseo\migrationcenter\source\vbulletin\adapter\vb_db_adapter;

/**
 * vBulletin Forums and Categories Migration Step
 */
class vb_forums_step implements step_interface
{
	public function get_name(): string
	{
		return 'forums';
	}

	public function get_label(): string
	{
		return 'Forums & Categories';
	}

	public function get_dependencies(): array
	{
		return ['groups'];
	}

	public function process_batch(
		string $run_id,
		$cursor,
		int $batch_size,
		migration_config_dto $config,
		source_provider_interface $provider,
		target_writer_interface $writer
	): step_result_dto {
		$result = new step_result_dto('forums');
		$db = new vb_db_adapter($config);

		$cursor_id = (int)$cursor;
		$tbl_forum = $db->get_table_name('forum');

		// Fetch all forums in hierarchy order
		$sql = "SELECT forumid, parentid, title, description, displayorder, options
				FROM {$tbl_forum}
				WHERE forumid > {$cursor_id}
				ORDER BY forumid ASC
				LIMIT {$batch_size}";

		$rows = $db->fetch_all($sql);
		$result->read_count = count($rows);

		if (empty($rows))
		{
			$result->next_cursor = (string)$cursor_id;
			$result->current_cursor = (string)$cursor_id;
			$result->is_completed = true;
			return $result;
		}

		$forum_dtos = [];
		$max_cursor = $cursor_id;

		foreach ($rows as $row)
		{
			$fid = (int)$row['forumid'];
			if ($fid > $max_cursor)
			{
				$max_cursor = $fid;
			}

			$dto = new forum_dto();
			$dto->source_id = $fid;
			$parent = (int)$row['parentid'];
			$dto->parent_source_id = ($parent > 0) ? $parent : 0;
			$dto->forum_name = trim((string)$row['title']);
			$dto->forum_name_clean = function_exists('utf8_clean_string') ? utf8_clean_string($dto->forum_name) : mb_strtolower($dto->forum_name, 'UTF-8');
			$dto->forum_desc = trim((string)($row['description'] ?? ''));
			$dto->display_order = (int)($row['displayorder'] ?? 0);

			$options = (int)($row['options'] ?? 0);
			$is_active = ($options & 1);
			$can_post = ($options & 2);

			if ($parent <= 0 && !$can_post)
			{
				$dto->forum_type = 0; // FORUM_CAT
				$dto->node_type = 'Category';
				$dto->allow_posting = false;
			}
			else
			{
				$dto->forum_type = 1; // FORUM_POST
				$dto->node_type = 'Forum';
				$dto->allow_posting = true;
			}

			$dto->forum_status = 0; // UNLOCKED
			$forum_dtos[] = $dto;
		}

		// Sort parent categories and forums first so hierarchy is resolved cleanly
		usort($forum_dtos, function (forum_dto $a, forum_dto $b) {
			if ($a->parent_source_id === 0 && $b->parent_source_id !== 0)
			{
				return -1;
			}
			if ($a->parent_source_id !== 0 && $b->parent_source_id === 0)
			{
				return 1;
			}
			if ($a->display_order !== $b->display_order)
			{
				return $a->display_order <=> $b->display_order;
			}
			return $a->source_id <=> $b->source_id;
		});

		$writer_res = $writer->write_forums($forum_dtos, [
			'run_id'        => $run_id,
			'source_system' => $config->source_system ?: 'vbulletin',
		]);

		$created = 0;
		$reused  = 0;
		$skipped = 0;
		$failed  = 0;

		foreach ($writer_res as $res)
		{
			if (($res['status'] ?? '') === 'success')
			{
				if (!empty($res['reused']))
				{
					$reused++;
				}
				else
				{
					$created++;
				}
			}
			else if (($res['status'] ?? '') === 'skipped')
			{
				$skipped++;
			}
			else
			{
				$failed++;
			}
		}

		$result->imported_count = $created + $reused;
		$result->skipped_count = $skipped;
		$result->failed_count = $failed;
		$result->metrics = [
			'created' => $created,
			'reused'  => $reused,
			'updated' => 0,
			'skipped' => $skipped,
			'failed'  => $failed,
		];
		$result->next_cursor = (string)$max_cursor;
		$result->current_cursor = (string)$max_cursor;

		$max_total_id = (int)$provider->get_max_source_id('forums', $config);
		if ($max_cursor >= $max_total_id || count($rows) < $batch_size)
		{
			$result->is_completed = true;
		}

		return $result;
	}
}
