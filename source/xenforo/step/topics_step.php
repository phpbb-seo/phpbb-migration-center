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
use phpbbseo\migrationcenter\source\xenforo\adapter\xf_db_adapter;
use phpbbseo\migrationcenter\source\xenforo\normalizer\xf_topic_normalizer;

/**
 * XenForo Topics / Threads Migration Step
 */
class topics_step implements step_interface
{
	/** @var xf_topic_normalizer */
	protected $normalizer;

	/**
	 * Constructor
	 *
	 * @param xf_topic_normalizer|null $normalizer
	 */
	public function __construct(?xf_topic_normalizer $normalizer = null)
	{
		$this->normalizer = $normalizer ?: new xf_topic_normalizer();
	}

	/**
	 * Unique step identifier
	 *
	 * @return string
	 */
	public function get_name(): string
	{
		return 'topics';
	}

	/**
	 * Human-readable step label
	 *
	 * @return string
	 */
	public function get_label(): string
	{
		return 'Topics';
	}

	/**
	 * Step dependencies
	 *
	 * @return array
	 */
	public function get_dependencies(): array
	{
		return ['forums', 'users'];
	}

	/**
	 * Process topics batch using keyset pagination
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
		$result = new step_result_dto('topics');
		$db = new xf_db_adapter($config);
		$prefix = $db->get_prefix();

		$this->normalizer->load_prefixes($db);

		$current_cursor = (int)$cursor;
		if ($current_cursor === 0 && !empty($config->min_id))
		{
			$current_cursor = (int)$config->min_id - 1;
		}

		$limit = $batch_size > 0 ? $batch_size : 500;

		$max_id_clause = '';
		$params = [
			':cursor' => $current_cursor,
		];

		if ($config->max_id !== null && $config->max_id > 0)
		{
			$max_id_clause = ' AND t.thread_id <= :max_id ';
			$params[':max_id'] = (int)$config->max_id;
		}

		$sql = "SELECT 
					t.*,
					d.delete_date,
					d.delete_user_id,
					d.delete_username,
					d.delete_reason
				FROM `{$prefix}thread` t
				LEFT JOIN `{$prefix}deletion_log` d ON (t.thread_id = d.content_id AND d.content_type = 'thread')
				WHERE t.thread_id > :cursor {$max_id_clause}
				ORDER BY t.thread_id ASC
				LIMIT {$limit}";

		$stmt = $db->get_pdo()->prepare($sql);
		$stmt->execute($params);
		$rows = $stmt->fetchAll();

		$result->read_count = count($rows);

		if (empty($rows))
		{
			$result->is_completed = true;
			$result->next_cursor = $current_cursor;
			return $result;
		}

		$topics_to_write = [];
		$last_seen_id = $current_cursor;

		foreach ($rows as $row)
		{
			$thread_id = (int)$row['thread_id'];
			$last_seen_id = $thread_id;

			$del_log = [];
			if (!empty($row['delete_date']))
			{
				$del_log = [
					'delete_date'     => $row['delete_date'],
					'delete_user_id'  => $row['delete_user_id'],
					'delete_username' => $row['delete_username'],
					'delete_reason'   => $row['delete_reason'],
				];
			}

			$topic = $this->normalizer->normalize_thread($row, $config, $del_log);
			if ($topic === null)
			{
				$result->skipped_count++;
				continue;
			}

			$topics_to_write[] = $topic;
		}

		$result->next_cursor = $last_seen_id;
		if (count($rows) < $limit)
		{
			$result->is_completed = true;
		}

		// Dry-run handling
		if ($config->dry_run)
		{
			$result->imported_count = count($topics_to_write);
			return $result;
		}

		$write_options = [
			'run_id'               => $run_id,
			'source_system'        => $config->source_system ?: 'xenforo',
			'missing_forum_policy' => $config->options['missing_forum_policy'] ?? 'skip',
			'fallback_forum_id'    => (int)($config->options['fallback_forum_id'] ?? 2),
		];

		$write_results = $writer->write_topics($topics_to_write, $write_options);

		foreach ($write_results as $src_id => $res)
		{
			if ($res['status'] === 'success')
			{
				$result->imported_count++;
			}
			else if ($res['status'] === 'skipped')
			{
				$result->skipped_count++;
				$result->add_error(
					'TOPIC_SKIPPED',
					$res['error'] ?? 'Topic skipped by policy',
					'info',
					$src_id
				);
			}
			else
			{
				$result->failed_count++;
				$result->add_error(
					'TOPIC_FAILED',
					$res['error'] ?? 'Topic write failed',
					'error',
					$src_id
				);
			}
		}

		return $result;
	}
}
