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
use phpbbseo\migrationcenter\core\dto\forum_dto;
use phpbbseo\migrationcenter\source\xenforo\adapter\xf_db_adapter;
use phpbbseo\migrationcenter\source\xenforo\tree\forum_tree_builder;

/**
 * XenForo Forums & Categories Migration Step
 */
class forums_step implements step_interface
{
	/** @var forum_tree_builder */
	protected $tree_builder;

	/**
	 * Constructor
	 *
	 * @param forum_tree_builder|null $tree_builder
	 */
	public function __construct(?forum_tree_builder $tree_builder = null)
	{
		$this->tree_builder = $tree_builder ?: new forum_tree_builder();
	}

	/**
	 * Unique step identifier
	 *
	 * @return string
	 */
	public function get_name(): string
	{
		return 'forums';
	}

	/**
	 * Human-readable step label
	 *
	 * @return string
	 */
	public function get_label(): string
	{
		return 'Categories & Forums';
	}

	/**
	 * Step dependencies
	 *
	 * @return array
	 */
	public function get_dependencies(): array
	{
		return [];
	}

	/**
	 * Process forums batch
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
		$result = new step_result_dto('forums');
		$db = new xf_db_adapter($config);
		$prefix = $db->get_prefix();

		// Fetch all nodes with category/forum/link joins
		$sql = "SELECT 
					n.*,
					f.discussion_count,
					f.message_count,
					f.allow_posting,
					f.count_messages,
					lf.link_url,
					lf.redirect_count
				FROM `{$prefix}node` n
				LEFT JOIN `{$prefix}forum` f ON (n.node_id = f.node_id)
				LEFT JOIN `{$prefix}link_forum` lf ON (n.node_id = lf.node_id)
				ORDER BY n.parent_node_id ASC, n.display_order ASC, n.node_id ASC";

		$rows = $db->fetch_all($sql);
		$result->read_count = count($rows);

		if (empty($rows))
		{
			$result->is_completed = true;
			$result->next_cursor = $cursor;
			return $result;
		}

		$normalized_nodes = [];

		foreach ($rows as $row)
		{
			$node_id   = (int)$row['node_id'];
			$node_type = (string)($row['node_type_id'] ?? 'Forum');

			// Skip/report unsupported node types (Page, SearchForum)
			if ($node_type === 'Page' || $node_type === 'SearchForum')
			{
				$result->skipped_count++;
				$result->add_error(
					'UNSUPPORTED_NODE_TYPE',
					"Node '{$row['title']}' (ID: {$node_id}) of type '{$node_type}' has no standard phpBB forum equivalent and was skipped.",
					'info',
					$node_id
				);
				continue;
			}

			$forum = new forum_dto();
			$forum->source_id = $node_id;
			$forum->parent_source_id = (int)($row['parent_node_id'] ?? 0);
			$forum->node_type = $node_type;
			$forum->forum_name = trim((string)$row['title']);
			$forum->forum_name_clean = mb_strtolower($forum->forum_name, 'UTF-8');
			$forum->forum_desc = trim((string)($row['description'] ?? ''));
			$forum->display_order = (int)($row['display_order'] ?? 0);
			$forum->display_on_index = !empty($row['display_in_list']) ? 1 : 0;

			switch ($node_type)
			{
				case 'Category':
					$forum->forum_type = 0; // FORUM_CAT
					$forum->topics_count = 0;
					$forum->posts_count = 0;
					break;

				case 'LinkForum':
					$forum->forum_type = 2; // FORUM_LINK
					$forum->forum_link = (string)($row['link_url'] ?? '');
					$forum->topics_count = 0;
					$forum->posts_count = (int)($row['redirect_count'] ?? 0);
					break;

				case 'Forum':
				default:
					$forum->forum_type = 1; // FORUM_POST
					$forum->allow_posting = !isset($row['allow_posting']) || !empty($row['allow_posting']);
					$forum->count_messages = !isset($row['count_messages']) || !empty($row['count_messages']);
					$forum->topics_count = (int)($row['discussion_count'] ?? 0);
					$forum->posts_count = (int)($row['message_count'] ?? 0);
					break;
			}

			$forum->raw_source_data = $row;
			$normalized_nodes[] = $forum;
		}

		// Build nested-set hierarchy and repair missing parents / cycles
		$orphan_policy = $config->options['orphan_policy'] ?? 'nearest';
		$tree_data = $this->tree_builder->build_tree($normalized_nodes, $orphan_policy);
		$ordered_nodes = $tree_data['nodes'];

		// Record repairs in results
		foreach ($tree_data['repairs'] as $rep)
		{
			$result->add_error(
				'HIERARCHY_REPAIR',
				"Hierarchy repair for Node {$rep['node_id']} ('{$rep['title']}'): Issue: {$rep['issue']}, Action: {$rep['action']}",
				'info',
				$rep['node_id']
			);
		}

		$result->next_cursor = 1;
		$result->is_completed = true;

		// Dry-Run Handling
		if ($config->dry_run)
		{
			$result->imported_count = count($ordered_nodes);
			return $result;
		}

		// Write forums via target writer
		$write_options = [
			'run_id'        => $run_id,
			'source_system' => $config->source_system ?: 'xenforo',
		];

		$write_results = $writer->write_forums($ordered_nodes, $write_options);

		foreach ($write_results as $src_id => $res)
		{
			if ($res['status'] === 'success')
			{
				$result->imported_count++;
			}
			else if ($res['status'] === 'skipped')
			{
				$result->skipped_count++;
			}
			else
			{
				$result->add_error(
					'FORUM_WRITE_FAILED',
					"Forum ID {$src_id} write failed: " . ($res['error'] ?? 'Unknown error'),
					'error',
					$src_id
				);
			}
		}

		return $result;
	}
}
