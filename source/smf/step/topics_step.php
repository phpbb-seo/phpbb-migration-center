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
use phpbbseo\migrationcenter\core\dto\migration_config_dto;
use phpbbseo\migrationcenter\core\dto\step_result_dto;
use phpbbseo\migrationcenter\core\dto\topic_dto;
use phpbbseo\migrationcenter\source\smf\adapter\smf_db_adapter;

/**
 * SMF Topics Migration Step
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
		$db = new smf_db_adapter($config);

		$cursor_id = (int)$cursor;
		$tbl_topics   = $db->get_table_name('topics');
		$tbl_messages = $db->get_table_name('messages');

		$sql = "SELECT t.id_topic, t.id_board, t.is_sticky, t.locked, t.num_views, t.num_replies, t.id_poll,
				       t.id_first_msg, t.id_last_msg, t.id_member_started, t.id_member_updated,
				       m.subject, m.poster_time, m.poster_name,
				       ml.poster_name AS last_poster_name, ml.poster_time AS last_poster_time
				FROM {$tbl_topics} t
				LEFT JOIN {$tbl_messages} m ON (m.id_msg = t.id_first_msg)
				LEFT JOIN {$tbl_messages} ml ON (ml.id_msg = t.id_last_msg)
				WHERE t.id_topic > {$cursor_id}
				ORDER BY t.id_topic ASC
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
			$tid = (int)$row['id_topic'];
			if ($tid > $max_cursor)
			{
				$max_cursor = $tid;
			}

			$dto = new topic_dto();
			$dto->source_id = $tid;
			$dto->forum_source_id = (int)$row['id_board'];
			$dto->user_source_id = (int)$row['id_member_started'];
			$dto->source_username = (string)($row['poster_name'] ?? '');
			$dto->topic_title = trim((string)($row['subject'] ?? ('Topic #' . $tid)));
			$dto->original_title = $dto->topic_title;
			$dto->topic_time = (int)($row['poster_time'] ?? time());
			$dto->topic_views = (int)($row['num_views'] ?? 0);
			$dto->reply_count = (int)($row['num_replies'] ?? 0);

			$dto->first_post_source_id = (int)($row['id_first_msg'] ?? 0);
			$dto->last_post_source_id = (int)($row['id_last_msg'] ?? 0);
			$dto->last_post_source_user_id = (int)($row['id_member_updated'] ?? 0);
			$dto->last_post_username = (string)($row['last_poster_name'] ?? $dto->source_username);
			$dto->last_post_time = (int)($row['last_poster_time'] ?? $dto->topic_time);

			// Status
			$dto->topic_status = !empty($row['locked']) ? 1 : 0; // 1 = locked
			$dto->topic_type = !empty($row['is_sticky']) ? 1 : 0; // 1 = sticky

			$topic_dtos[] = $dto;
		}

		$writer_res = $writer->write_topics($topic_dtos, [
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

		$max_id = (int)$provider->get_max_source_id('topics', $config);
		if (count($rows) < $batch_size || $max_cursor >= $max_id)
		{
			$result->is_completed = true;
		}

		return $result;
	}
}
