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
use phpbbseo\migrationcenter\core\dto\post_dto;
use phpbbseo\migrationcenter\core\dto\step_result_dto;
use phpbbseo\migrationcenter\source\vbulletin\adapter\vb_db_adapter;
use phpbbseo\migrationcenter\source\vbulletin\content\vb_message_converter;

/**
 * vBulletin 6 Posts & Replies Migration Step (Node & Text Architecture)
 */
class vb6_posts_step implements step_interface
{
	/** @var vb_message_converter */
	protected $converter;

	public function __construct(?vb_message_converter $converter = null)
	{
		$this->converter = $converter ?: new vb_message_converter();
	}

	public function get_name(): string
	{
		return 'posts';
	}

	public function get_label(): string
	{
		return 'Posts & Replies';
	}

	public function get_dependencies(): array
	{
		return ['topics', 'users'];
	}

	public function process_batch(
		string $run_id,
		$cursor,
		int $batch_size,
		migration_config_dto $config,
		source_provider_interface $provider,
		target_writer_interface $writer
	): step_result_dto {
		$result = new step_result_dto('posts');
		$db = new vb_db_adapter($config);

		$cursor_id = (int)$cursor;
		$tbl_node = $db->get_table_name('node');
		$tbl_text = $db->get_table_name('text');

		$sql = "SELECT n.nodeid, n.starter, n.userid, n.authorname, n.title, n.publishdate,
				       n.created, n.ipaddress, n.approved, t.rawtext
				FROM {$tbl_node} n
				JOIN {$tbl_text} t ON t.nodeid = n.nodeid
				WHERE n.contenttypeid = 22 AND n.nodeid > {$cursor_id}
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

		$post_dtos = [];
		$max_cursor = $cursor_id;

		foreach ($rows as $row)
		{
			$pid = (int)$row['nodeid'];
			if ($pid > $max_cursor)
			{
				$max_cursor = $pid;
			}

			$dto = new post_dto();
			$dto->source_id = $pid;
			$dto->topic_source_id = (int)$row['starter'];
			$dto->user_source_id = (int)$row['userid'];
			$dto->username = (string)($row['authorname'] ?? '');
			$dto->post_subject = trim((string)($row['title'] ?? ''));
			$dto->raw_source_message = (string)($row['rawtext'] ?? '');

			$conv = $this->converter->convert($dto->raw_source_message, $config);
			$dto->normalized_message = $conv->normalized_bbcode;
			$dto->post_text = $conv->storage_text;
			$dto->bbcode_uid = $conv->bbcode_uid;
			$dto->bbcode_bitfield = $conv->bbcode_bitfield;

			$dto->post_time = (int)($row['publishdate'] ?: $row['created'] ?: time());
			$dto->poster_ip = (string)($row['ipaddress'] ?? '127.0.0.1');
			$dto->post_visibility = !empty($row['approved']) ? 1 : 0;

			$post_dtos[] = $dto;
		}

		$writer_res = $writer->write_posts($post_dtos, [
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
