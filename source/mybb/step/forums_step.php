<?php
/**
 * phpBB Migration Center Extension
 *
 * @copyright (c) 2026 phpBB SEO Team
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace phpbbseo\migrationcenter\source\mybb\step;

use phpbbseo\migrationcenter\core\contract\step_interface;
use phpbbseo\migrationcenter\core\contract\source_provider_interface;
use phpbbseo\migrationcenter\core\contract\target_writer_interface;
use phpbbseo\migrationcenter\core\dto\forum_dto;
use phpbbseo\migrationcenter\core\dto\migration_config_dto;
use phpbbseo\migrationcenter\core\dto\step_result_dto;
use phpbbseo\migrationcenter\source\mybb\adapter\mybb_db_adapter;

/**
 * MyBB Forums and Categories Migration Step
 */
class forums_step implements step_interface
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
		$db = new mybb_db_adapter($config);

		$cursor_id = (int)$cursor;
		$tbl_forum = $db->get_table_name('forums');

		$sql = "SELECT fid, pid, name, description, disporder, type, open, active
				FROM {$tbl_forum}
				WHERE fid > {$cursor_id}
				ORDER BY fid ASC
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
			$fid = (int)$row['fid'];
			if ($fid > $max_cursor)
			{
				$max_cursor = $fid;
			}

			$dto = new forum_dto();
			$dto->source_id = $fid;
			$parent = (int)$row['pid'];
			$dto->parent_source_id = ($parent > 0) ? $parent : 0;
			$dto->forum_name = trim((string)$row['name']);
			$dto->forum_name_clean = function_exists('utf8_clean_string') ? utf8_clean_string($dto->forum_name) : mb_strtolower($dto->forum_name, 'UTF-8');
			$dto->forum_desc = trim((string)($row['description'] ?? ''));
			$dto->display_order = (int)($row['disporder'] ?? 0);

			if ($row['type'] === 'c')
			{
				$dto->forum_type = 0; // FORUM_CAT
				$dto->node_type = 'Category';
				$dto->allow_posting = false;
			}
			else
			{
				$dto->forum_type = 1; // FORUM_POST
				$dto->node_type = 'Forum';
				$dto->allow_posting = !empty($row['open']);
			}

			$dto->forum_status = empty($row['open']) ? 1 : 0; // 1 = locked
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
			'source_system' => 'mybb',
			'preserve_ids'  => $config->preserve_ids,
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
		$result->processed_records = count($rows);
		$result->imported_records = $result->imported_count;
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
		$result->current_cursor = (string)$cursor_id;

		$max_id = (int)$provider->get_max_source_id('forums', $config);
		if (count($rows) < $batch_size || $max_cursor >= $max_id)
		{
			$result->is_completed = true;
		}

		return $result;
	}
}
