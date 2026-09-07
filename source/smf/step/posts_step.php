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
use phpbbseo\migrationcenter\core\dto\post_dto;
use phpbbseo\migrationcenter\core\dto\step_result_dto;
use phpbbseo\migrationcenter\source\smf\adapter\smf_db_adapter;
use phpbbseo\migrationcenter\source\smf\content\smf_message_converter;

/**
 * SMF Posts and BBCode Migration Step
 */
class posts_step implements step_interface
{
	/** @var smf_message_converter */
	protected $converter;

	public function __construct(?smf_message_converter $converter = null)
	{
		$this->converter = $converter ?: new smf_message_converter();
	}

	public function get_name(): string
	{
		return 'posts';
	}

	public function get_label(): string
	{
		return 'Posts & Messages';
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
		$db = new smf_db_adapter($config);

		$cursor_id = (int)$cursor;
		$tbl_post = $db->get_table_name('messages');

		$sql = "SELECT id_msg, id_topic, id_board, poster_time, id_member, subject,
				       poster_name, poster_email, poster_ip, modified_time, modified_name, body
				FROM {$tbl_post}
				WHERE id_msg > {$cursor_id}
				ORDER BY id_msg ASC
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
			$pid = (int)$row['id_msg'];
			if ($pid > $max_cursor)
			{
				$max_cursor = $pid;
			}

			$dto = new post_dto();
			$dto->source_id = $pid;
			$dto->topic_source_id = (int)$row['id_topic'];
			$dto->user_source_id = (int)$row['id_member'];
			$dto->username = (string)($row['poster_name'] ?? '');
			$dto->source_username = $dto->username;
			$dto->post_subject = trim((string)($row['subject'] ?? ''));
			$dto->post_time = (int)($row['poster_time'] ?? time());

			// IP Address
			$raw_ip = $row['poster_ip'] ?? '';
			if (!empty($raw_ip))
			{
				if (strlen($raw_ip) === 4 || strlen($raw_ip) === 16)
				{
					$inet = @inet_ntop($raw_ip);
					$dto->poster_ip = ($inet !== false) ? $inet : '127.0.0.1';
				}
				else
				{
					$dto->poster_ip = (string)$raw_ip;
				}
			}
			else
			{
				$dto->poster_ip = '127.0.0.1';
			}

			// Edit info
			$dto->post_edit_time = (int)($row['modified_time'] ?? 0);
			$dto->post_edit_user = (string)($row['modified_name'] ?? '');

			// BBCode & Content conversion
			$res = $this->converter->convert((string)($row['body'] ?? ''), $config);
			$dto->post_text = $res->storage_text ?: $res->normalized_bbcode;
			$dto->bbcode_uid = $res->bbcode_uid ?? '';
			$dto->bbcode_bitfield = $res->bbcode_bitfield ?? '';

			$post_dtos[] = $dto;
		}

		$writer_res = $writer->write_posts($post_dtos, [
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

		$max_id = (int)$provider->get_max_source_id('posts', $config);
		if (count($rows) < $batch_size || $max_cursor >= $max_id)
		{
			$result->is_completed = true;
		}

		return $result;
	}
}
