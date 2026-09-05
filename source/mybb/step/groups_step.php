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
use phpbbseo\migrationcenter\source\mybb\adapter\mybb_db_adapter;
use phpbbseo\migrationcenter\source\mybb\normalizer\mybb_group_normalizer;

/**
 * MyBB User Groups Migration Step
 */
class groups_step implements step_interface
{
	/** @var mybb_group_normalizer */
	protected $normalizer;

	public function __construct(?mybb_group_normalizer $normalizer = null)
	{
		$this->normalizer = $normalizer ?: new mybb_group_normalizer();
	}

	public function get_name(): string
	{
		return 'groups';
	}

	public function get_label(): string
	{
		return 'User Groups';
	}

	public function get_dependencies(): array
	{
		return [];
	}

	public function process_batch(
		string $run_id,
		$cursor,
		int $batch_size,
		migration_config_dto $config,
		source_provider_interface $provider,
		target_writer_interface $writer
	): step_result_dto {
		$result = new step_result_dto('groups');
		$db = new mybb_db_adapter($config);

		$cursor_id = (int)$cursor;
		$tbl_groups = $db->get_table_name('usergroups');

		$sql = "SELECT * FROM {$tbl_groups}
				WHERE gid > {$cursor_id}
				ORDER BY gid ASC
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

		$dtos = [];
		$max_cursor = $cursor_id;

		foreach ($rows as $row)
		{
			$gid = (int)$row['gid'];
			if ($gid > $max_cursor)
			{
				$max_cursor = $gid;
			}
			$dtos[] = $this->normalizer->normalize($row);
		}

		$write_res = $writer->write_groups($dtos, [
			'run_id'        => $run_id,
			'source_system' => 'mybb',
			'preserve_ids'  => $config->preserve_ids,
		]);

		$created = 0;
		$reused  = 0;
		$skipped = 0;
		$failed  = 0;

		foreach ($write_res as $res)
		{
			if (($res['status'] ?? '') === 'success')
			{
				if (!empty($res['reused']) || !empty($res['builtin']))
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
		$result->current_cursor = (string)$cursor_id;

		$max_id = (int)$provider->get_max_source_id('groups', $config);
		if (count($rows) < $batch_size || $max_cursor >= $max_id)
		{
			$result->is_completed = true;
		}

		return $result;
	}
}
