<?php
/**
 * phpBB Migration Center Extension
 *
 * @copyright (c) 2026 phpBB SEO Team
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace phpbbseo\migrationcenter\source\smf\step;

use phpbbseo\migrationcenter\core\contract\step_interface;
use phpbbseo\migrationcenter\core\contract\source_provider_interface;
use phpbbseo\migrationcenter\core\contract\target_writer_interface;
use phpbbseo\migrationcenter\core\dto\forum_dto;
use phpbbseo\migrationcenter\core\dto\migration_config_dto;
use phpbbseo\migrationcenter\core\dto\step_result_dto;
use phpbbseo\migrationcenter\source\smf\adapter\smf_db_adapter;

/**
 * SMF Forums and Categories Migration Step
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
		$db = new smf_db_adapter($config);

		$cursor_id = (int)$cursor;
		$tbl_boards = $db->get_table_name('boards');
		$tbl_cats   = $db->get_table_name('categories');

		$forum_dtos = [];

		// On the first batch (cursor 0), import all categories as category forums
		if ($cursor_id === 0 && $db->table_exists('categories'))
		{
			$cat_rows = $db->fetch_all("SELECT * FROM {$tbl_cats} ORDER BY cat_order ASC, id_cat ASC");
			foreach ($cat_rows as $c)
			{
				$c_dto = new forum_dto();
				// Offset category source ID to prevent collision with board IDs
				$c_dto->source_id = 100000 + (int)$c['id_cat'];
				$c_dto->parent_source_id = 0;
				$c_dto->forum_name = trim((string)$c['name']);
				$c_dto->forum_name_clean = function_exists('utf8_clean_string') ? utf8_clean_string($c_dto->forum_name) : mb_strtolower($c_dto->forum_name, 'UTF-8');
				$c_dto->forum_desc = trim((string)($c['description'] ?? ''));
				$c_dto->display_order = (int)($c['cat_order'] ?? 0);
				$c_dto->forum_type = 0; // FORUM_CAT
				$c_dto->node_type = 'Category';
				$c_dto->allow_posting = false;
				$c_dto->forum_status = 0;

				$forum_dtos[] = $c_dto;
			}
		}

		// Fetch boards batch
		$sql = "SELECT id_board, id_cat, child_level, id_parent, board_order, name, description, redirect
				FROM {$tbl_boards}
				WHERE id_board > {$cursor_id}
				ORDER BY id_board ASC
				LIMIT {$batch_size}";

		$rows = $db->fetch_all($sql);
		$result->read_count = count($rows);

		if (empty($rows) && empty($forum_dtos))
		{
			$result->next_cursor = (string)$cursor_id;
			$result->current_cursor = (string)$cursor_id;
			$result->is_completed = true;
			return $result;
		}

		$max_cursor = $cursor_id;

		foreach ($rows as $row)
		{
			$bid = (int)$row['id_board'];
			if ($bid > $max_cursor)
			{
				$max_cursor = $bid;
			}

			$dto = new forum_dto();
			$dto->source_id = $bid;

			$parent_board = (int)($row['id_parent'] ?? 0);
			if ($parent_board > 0)
			{
				$dto->parent_source_id = $parent_board;
			}
			else
			{
				// Top-level board under category
				$cat_id = (int)($row['id_cat'] ?? 0);
				$dto->parent_source_id = ($cat_id > 0) ? (100000 + $cat_id) : 0;
			}

			$dto->forum_name = trim((string)$row['name']);
			$dto->forum_name_clean = function_exists('utf8_clean_string') ? utf8_clean_string($dto->forum_name) : mb_strtolower($dto->forum_name, 'UTF-8');
			$dto->forum_desc = trim((string)($row['description'] ?? ''));
			$dto->display_order = (int)($row['board_order'] ?? 0);

			$redirect = trim((string)($row['redirect'] ?? ''));
			if (!empty($redirect))
			{
				$dto->forum_type = 2; // FORUM_LINK
				$dto->node_type = 'Link';
				$dto->allow_posting = false;
				$dto->forum_status = 0;
			}
			else
			{
				$dto->forum_type = 1; // FORUM_POST
				$dto->node_type = 'Forum';
				$dto->allow_posting = true;
				$dto->forum_status = 0;
			}

			$forum_dtos[] = $dto;
		}

		// Topological sort: Categories (parent = 0) first, then root boards, then child boards
		usort($forum_dtos, function (forum_dto $a, forum_dto $b) {
			if ($a->parent_source_id === 0 && $b->parent_source_id !== 0)
			{
				return -1;
			}
			if ($a->parent_source_id !== 0 && $b->parent_source_id === 0)
			{
				return 1;
			}
			// Category priority
			if ($a->source_id >= 100000 && $b->source_id < 100000)
			{
				return -1;
			}
			if ($a->source_id < 100000 && $b->source_id >= 100000)
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
			'source_system' => 'smf',
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
		$result->processed_records = count($rows) + count($forum_dtos);
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
