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
use phpbbseo\migrationcenter\core\dto\migration_config_dto;
use phpbbseo\migrationcenter\core\dto\step_result_dto;
use phpbbseo\migrationcenter\core\dto\topic_dto;
use phpbbseo\migrationcenter\source\vbulletin\adapter\vb_db_adapter;

/**
 * vBulletin 6 Topics Migration Step (Node Architecture)
 */
class vb6_topics_step implements step_interface
{
	public function get_name(): string
	{
		return 'topics';
	}

	public function get_label(): string
	{
		return 'Topics';
	}

	public function get_dependencies(): array
	{
		return ['forums', 'users'];
	}

	public function process_batch(
		string $run_id,
		$cursor,
		int $batch_size,
		migration_config_dto $config,
		source_provider_interface $provider,
		target_writer_interface $writer
	): step_result_dto {
		$result = new step_result_dto('topics');
		$db = new vb_db_adapter($config);

		$cursor_id = (int)$cursor;
		$tbl_node = $db->get_table_name('node');
		$tbl_view = $db->get_table_name('nodeview');

		$sql = "SELECT n.nodeid, n.parentid, n.userid, n.authorname, n.title, n.publishdate,
				       n.created, n.sticky, n.open, n.totalcount, COALESCE(nv.count, 0) AS views
				FROM {$tbl_node} n
				LEFT JOIN {$tbl_view} nv ON nv.nodeid = n.nodeid
				WHERE n.contenttypeid = 22 AND n.starter = n.nodeid AND n.nodeid > {$cursor_id}
				ORDER BY n.nodeid ASC
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

		$topic_dtos = [];
		$max_cursor = $cursor_id;

		foreach ($rows as $row)
		{
			$nid = (int)$row['nodeid'];
			if ($nid > $max_cursor)
			{
				$max_cursor = $nid;
			}

			$dto = new topic_dto();
			$dto->source_id = $nid;
			$dto->forum_source_id = (int)$row['parentid'];
			$dto->user_source_id = (int)$row['userid'];
			$dto->source_username = (string)($row['authorname'] ?? '');
			$dto->topic_title = trim((string)$row['title']);
			$dto->original_title = $dto->topic_title;
			$dto->topic_time = (int)($row['publishdate'] ?: $row['created'] ?: time());
			$dto->topic_views = (int)($row['views'] ?? 0);
			$dto->reply_count = max(0, (int)($row['totalcount'] ?? 1) - 1);
			$dto->topic_type = !empty($row['sticky']) ? 1 : 0;
			$dto->topic_status = empty($row['open']) ? 1 : 0;
			$dto->topic_visibility = 1;
			$dto->discussion_type = 'discussion';

			$topic_dtos[] = $dto;
		}

		$writer_res = $writer->write_topics($topic_dtos, [
			'run_id'        => $run_id,
			'source_system' => $config->source_system ?: 'vbulletin6',
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
		$result->processed_records = count($rows);
		$result->next_cursor = (string)$max_cursor;
		$result->current_cursor = (string)$max_cursor;

		if (count($rows) < $batch_size)
		{
			$result->is_completed = true;
		}

		return $result;
	}
}
