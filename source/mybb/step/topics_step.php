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
use phpbbseo\migrationcenter\core\dto\migration_config_dto;
use phpbbseo\migrationcenter\core\dto\step_result_dto;
use phpbbseo\migrationcenter\core\dto\topic_dto;
use phpbbseo\migrationcenter\source\mybb\adapter\mybb_db_adapter;

/**
 * MyBB Topics/Threads Migration Step
 */
class topics_step implements step_interface
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
		$db = new mybb_db_adapter($config);

		$cursor_id = (int)$cursor;
		$tbl_thread = $db->get_table_name('threads');

		$sql = "SELECT tid, fid, subject, uid, username, dateline, views, replies, closed, sticky, visible, poll
				FROM {$tbl_thread}
				WHERE tid > {$cursor_id}
				ORDER BY tid ASC
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
			$tid = (int)$row['tid'];
			if ($tid > $max_cursor)
			{
				$max_cursor = $tid;
			}

			$dto = new topic_dto();
			$dto->source_id = $tid;
			$dto->forum_source_id = (int)$row['fid'];
			$dto->user_source_id = (int)$row['uid'];
			$dto->source_username = (string)($row['username'] ?? '');
			$dto->topic_title = trim((string)$row['subject']);
			$dto->original_title = $dto->topic_title;
			$dto->topic_time = (int)($row['dateline'] ?? time());
			$dto->topic_views = (int)($row['views'] ?? 0);
			$dto->reply_count = (int)($row['replies'] ?? 0);
			$dto->topic_type = !empty($row['sticky']) ? 1 : 0;
			$dto->topic_status = (!empty($row['closed']) && $row['closed'] == 1) ? 1 : 0;
			$dto->topic_visibility = ((int)($row['visible'] ?? 1) === 1) ? 1 : 0;
			$dto->discussion_type = (!empty($row['poll']) && (int)$row['poll'] > 0) ? 'poll' : 'discussion';

			$topic_dtos[] = $dto;
		}

		$writer_res = $writer->write_topics($topic_dtos, [
			'run_id'        => $run_id,
			'source_system' => $config->source_system ?: 'mybb',
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

		$max_total_id = (int)$provider->get_max_source_id('topics', $config);
		if ($max_cursor >= $max_total_id || count($rows) < $batch_size)
		{
			$result->is_completed = true;
		}

		return $result;
	}
}
