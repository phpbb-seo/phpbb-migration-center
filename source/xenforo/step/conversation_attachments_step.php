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
use phpbbseo\migrationcenter\source\xenforo\normalizer\xf_attachment_normalizer;

/**
 * XenForo Conversation Attachments Migration Step
 */
class conversation_attachments_step implements step_interface
{
	/** @var xf_attachment_normalizer */
	protected $normalizer;

	/**
	 * Constructor
	 *
	 * @param xf_attachment_normalizer|null $normalizer
	 */
	public function __construct(?xf_attachment_normalizer $normalizer = null)
	{
		$this->normalizer = $normalizer ?: new xf_attachment_normalizer();
	}

	public function get_name(): string
	{
		return 'conversation_attachments';
	}

	public function get_label(): string
	{
		return 'Conversation Attachments';
	}

	public function get_dependencies(): array
	{
		return ['conversation_messages', 'users'];
	}

	public function process_batch(
		string $run_id,
		$cursor,
		int $batch_size,
		migration_config_dto $config,
		source_provider_interface $provider,
		target_writer_interface $writer
	): step_result_dto {
		$result = new step_result_dto('conversation_attachments');
		$db = new xf_db_adapter($config);
		$prefix = $db->get_prefix();

		$current_cursor = (int)$cursor;
		if ($current_cursor === 0 && !empty($config->min_id))
		{
			$current_cursor = (int)$config->min_id - 1;
		}

		$limit = $batch_size > 0 ? $batch_size : 200;

		$max_id_clause = '';
		$params = [
			':cursor' => $current_cursor,
		];

		if ($config->max_id !== null && $config->max_id > 0)
		{
			$max_id_clause = ' AND a.attachment_id <= :max_id ';
			$params[':max_id'] = (int)$config->max_id;
		}

		$sql = "SELECT 
					a.attachment_id,
					a.data_id,
					a.content_type,
					a.content_id,
					a.attach_date,
					a.unassociated,
					a.view_count,
					d.user_id,
					d.upload_date,
					d.filename,
					d.file_size,
					d.file_hash,
					d.file_path,
					d.width,
					d.height,
					d.thumbnail_width,
					d.thumbnail_height
				FROM `{$prefix}attachment` a
				INNER JOIN `{$prefix}attachment_data` d ON a.data_id = d.data_id
				WHERE a.attachment_id > :cursor 
					AND a.content_type = 'conversation_message'
					{$max_id_clause}
				ORDER BY a.attachment_id ASC
				LIMIT {$limit}";

		$rows = $db->fetch_all($sql, $params);

		if (empty($rows))
		{
			$result->is_completed = true;
			$result->next_cursor = (string)$current_cursor;
			return $result;
		}

		$dtos = [];
		$max_seen_id = $current_cursor;

		foreach ($rows as $row)
		{
			$att_id = (int)$row['attachment_id'];
			if ($att_id > $max_seen_id)
			{
				$max_seen_id = $att_id;
			}

			$dto = $this->normalizer->normalize_attachment($row, $config);
			$dtos[] = $dto;
		}

		$result->items_total = count($dtos);
		$result->items_processed = count($dtos);

		if ($config->dry_run)
		{
			$result->items_imported = count($dtos);
		}
		else
		{
			$write_results = $writer->write_attachments($dtos, [
				'run_id'                => $run_id,
				'source_system'         => $config->source_system ?: 'xenforo',
				'attachment_policy'     => $config->options['attachment_policy'] ?? 'respect_target_policy',
				'missing_file_policy'   => $config->options['missing_file_policy'] ?? 'skip',
			]);

			foreach ($dtos as $d)
			{
				$sid = $d->source_id;
				$res = $write_results[$sid] ?? null;

				if ($res && $res['status'] === 'success')
				{
					$result->items_imported++;
				}
				else if ($res && $res['status'] === 'skipped')
				{
					$result->items_skipped++;
					if (!empty($res['error']))
					{
						$result->warnings[] = "Conversation attachment {$sid} skipped: {$res['error']}";
					}
				}
				else
				{
					$result->items_failed++;
					$err = $res['error'] ?? 'Unknown error';
					$result->errors[] = "Conversation attachment {$sid} failed: {$err}";
				}
			}
		}

		$result->next_cursor = (string)$max_seen_id;
		if (count($rows) < $limit)
		{
			$result->is_completed = true;
		}

		return $result;
	}
}
