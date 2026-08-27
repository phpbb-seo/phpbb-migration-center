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
 * XenForo Attachments Migration Step
 */
class attachments_step implements step_interface
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

	/**
	 * Unique step identifier
	 *
	 * @return string
	 */
	public function get_name(): string
	{
		return 'attachments';
	}

	/**
	 * Human-readable step label
	 *
	 * @return string
	 */
	public function get_label(): string
	{
		return 'Attachments';
	}

	/**
	 * Step dependencies
	 *
	 * @return array
	 */
	public function get_dependencies(): array
	{
		return ['posts', 'users'];
	}

	/**
	 * Process attachments batch using keyset pagination
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
		$result = new step_result_dto('attachments');
		$db = new xf_db_adapter($config);
		$prefix = $db->get_prefix();

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
					d.file_key,
					d.file_path,
					d.width,
					d.height,
					d.thumbnail_width,
					d.thumbnail_height
				FROM `{$prefix}attachment` a
				LEFT JOIN `{$prefix}attachment_data` d ON (a.data_id = d.data_id)
				WHERE a.attachment_id > :cursor AND a.content_type = 'post' {$max_id_clause}
				ORDER BY a.attachment_id ASC
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

		$attachments_to_write = [];
		$last_seen_id = $current_cursor;

		foreach ($rows as $row)
		{
			$attachment_id = (int)$row['attachment_id'];
			$last_seen_id = $attachment_id;

			$att = $this->normalizer->normalize_attachment($row, $config);
			$attachments_to_write[] = $att;
		}

		$result->next_cursor = $last_seen_id;
		if (count($rows) < $limit)
		{
			$result->is_completed = true;
		}

		// Dry-run handling
		if ($config->dry_run)
		{
			$result->imported_count = count($attachments_to_write);
			return $result;
		}

		$write_options = [
			'run_id'              => $run_id,
			'source_system'       => $config->source_system ?: 'xenforo',
			'missing_file_policy' => $config->options['missing_file_policy'] ?? 'skip',
		];

		$write_results = $writer->write_attachments($attachments_to_write, $write_options);

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
					'ATTACHMENT_SKIPPED',
					$res['error'] ?? 'Attachment skipped',
					'info',
					$src_id
				);
			}
			else
			{
				$result->failed_count++;
				$result->add_error(
					'ATTACHMENT_FAILED',
					$res['error'] ?? 'Attachment write failed',
					'error',
					$src_id
				);
			}
		}

		return $result;
	}
}
