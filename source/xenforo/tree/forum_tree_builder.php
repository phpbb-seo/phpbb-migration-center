<?php
/**
 * phpBB Migration Center Extension
 *
 * @copyright (c) 2026 phpBB SEO Team
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace phpbbseo\migrationcenter\source\xenforo\tree;

use phpbbseo\migrationcenter\core\dto\forum_dto;

/**
 * Forum Tree Builder & Hierarchy Repair Engine
 */
class forum_tree_builder
{
	/** @var array Hierarchy repair log */
	protected $repairs = [];

	/**
	 * Build, repair, and compute nested set boundaries for forum nodes
	 *
	 * @param forum_dto[] $nodes
	 * @param string $orphan_policy ('nearest', 'root', 'skip')
	 * @param int $start_counter Starting value for left_id
	 * @return array ['nodes' => forum_dto[], 'repairs' => array]
	 */
	public function build_tree(array $nodes, string $orphan_policy = 'nearest', int $start_counter = 1): array
	{
		$this->repairs = [];
		$indexed_nodes = [];
		$node_parent_map = [];

		// Index all nodes by source_id
		foreach ($nodes as $node)
		{
			$indexed_nodes[$node->source_id] = $node;
			$node_parent_map[$node->source_id] = $node->parent_source_id;
		}

		// 1. Detect and repair self-parenting and cycles
		foreach ($indexed_nodes as $src_id => $node)
		{
			// Check self-parent
			if ($node->parent_source_id == $src_id)
			{
				$node->parent_source_id = 0;
				$this->repairs[] = [
					'node_id' => $src_id,
					'title'   => $node->forum_name,
					'issue'   => 'self_parent',
					'action'  => 'attached_to_root',
				];
			}

			// Check cycle
			$visited = [$src_id => true];
			$current_parent = $node->parent_source_id;
			$has_cycle = false;

			while ($current_parent > 0 && isset($indexed_nodes[$current_parent]))
			{
				if (isset($visited[$current_parent]))
				{
					$has_cycle = true;
					break;
				}
				$visited[$current_parent] = true;
				$current_parent = $indexed_nodes[$current_parent]->parent_source_id;
			}

			if ($has_cycle)
			{
				$node->parent_source_id = 0;
				$this->repairs[] = [
					'node_id' => $src_id,
					'title'   => $node->forum_name,
					'issue'   => 'cycle_detected',
					'action'  => 'broken_cycle_attached_to_root',
				];
			}
		}

		// 2. Detect and repair missing/orphaned parents
		$valid_nodes = [];
		foreach ($indexed_nodes as $src_id => $node)
		{
			if ($node->parent_source_id > 0 && !isset($indexed_nodes[$node->parent_source_id]))
			{
				if ($orphan_policy === 'skip')
				{
					$this->repairs[] = [
						'node_id' => $src_id,
						'title'   => $node->forum_name,
						'issue'   => 'missing_parent',
						'action'  => 'skipped',
					];
					continue;
				}
				else if ($orphan_policy === 'nearest')
				{
					// Search upwards for nearest existing ancestor
					$ancestor = $node_parent_map[$src_id] ?? 0;
					$found_parent = 0;
					while ($ancestor > 0)
					{
						if (isset($indexed_nodes[$ancestor]))
						{
							$found_parent = $ancestor;
							break;
						}
						$ancestor = $node_parent_map[$ancestor] ?? 0;
					}

					$node->parent_source_id = $found_parent;
					$this->repairs[] = [
						'node_id' => $src_id,
						'title'   => $node->forum_name,
						'issue'   => 'missing_parent',
						'action'  => $found_parent > 0 ? "attached_to_ancestor_{$found_parent}" : 'attached_to_root',
					];
				}
				else // 'root'
				{
					$node->parent_source_id = 0;
					$this->repairs[] = [
						'node_id' => $src_id,
						'title'   => $node->forum_name,
						'issue'   => 'missing_parent',
						'action'  => 'attached_to_root',
					];
				}
			}

			$valid_nodes[$src_id] = $node;
		}

		// 3. Build parent-to-children map
		$children_map = [];
		foreach ($valid_nodes as $src_id => $node)
		{
			$pid = (int)$node->parent_source_id;
			if (!isset($children_map[$pid]))
			{
				$children_map[$pid] = [];
			}
			$children_map[$pid][] = $node;
		}

		// Sort all sibling lists deterministically by display_order ASC, then source_id ASC
		foreach ($children_map as $pid => &$siblings)
		{
			usort($siblings, function (forum_dto $a, forum_dto $b) {
				if ($a->display_order !== $b->display_order)
				{
					return $a->display_order <=> $b->display_order;
				}
				return $a->source_id <=> $b->source_id;
			});
		}
		unset($siblings);

		// 4. Perform DFS traversal to calculate nested set left_id and right_id
		$counter = $start_counter;
		$ordered_nodes = [];

		$this->calculate_nested_set(0, $children_map, $counter, $ordered_nodes);

		// 5. Invariant Validation
		$seen_left = [];
		$seen_right = [];
		foreach ($ordered_nodes as $node)
		{
			if ($node->left_id >= $node->right_id)
			{
				throw new \RuntimeException("Nested set invariant violation: left_id ({$node->left_id}) >= right_id ({$node->right_id}) for node {$node->source_id}");
			}
			if (isset($seen_left[$node->left_id]))
			{
				throw new \RuntimeException("Duplicate left_id {$node->left_id} in forum tree!");
			}
			if (isset($seen_right[$node->right_id]))
			{
				throw new \RuntimeException("Duplicate right_id {$node->right_id} in forum tree!");
			}
			$seen_left[$node->left_id] = true;
			$seen_right[$node->right_id] = true;
		}

		return [
			'nodes'   => $ordered_nodes,
			'repairs' => $this->repairs,
		];
	}

	/**
	 * Recursive DFS calculation of nested set left_id and right_id
	 *
	 * @param int $parent_id
	 * @param array $children_map
	 * @param int $counter
	 * @param array $ordered_nodes
	 */
	protected function calculate_nested_set(int $parent_id, array &$children_map, int &$counter, array &$ordered_nodes): void
	{
		if (!isset($children_map[$parent_id]))
		{
			return;
		}

		foreach ($children_map[$parent_id] as $child_node)
		{
			$child_node->left_id = $counter++;
			$ordered_nodes[$child_node->source_id] = $child_node;

			// Recursively visit child subforums
			$this->calculate_nested_set((int)$child_node->source_id, $children_map, $counter, $ordered_nodes);

			$child_node->right_id = $counter++;
		}
	}
}
