<?php
/**
 * XenForo Forum Tree Builder, Hierarchy Repair & Unicode Unit Test
 */

namespace phpbbseo\migrationcenter\tests\unit;

use phpbbseo\migrationcenter\core\dto\forum_dto;
use phpbbseo\migrationcenter\source\xenforo\tree\forum_tree_builder;

class XfForumTreeBuilderTest
{
	public function run()
	{
		$builder = new forum_tree_builder();

		// 1. Setup sample hierarchy with Categories, Forums, LinkForums, and Unicode
		$node1 = new forum_dto();
		$node1->source_id = 10;
		$node1->parent_source_id = 0;
		$node1->node_type = 'Category';
		$node1->forum_type = 0; // FORUM_CAT
		$node1->forum_name = "XXXXXX_XXXY_UnicodeRunner\xE2\x80\x8CXXX"; // Persian with ZWNJ
		$node1->display_order = 10;

		$node2 = new forum_dto();
		$node2->source_id = 20;
		$node2->parent_source_id = 10;
		$node2->node_type = 'Forum';
		$node2->forum_type = 1; // FORUM_POST
		$node2->forum_name = 'Development_and_Programming'; // Arabic
		$node2->display_order = 1;

		$node3 = new forum_dto();
		$node3->source_id = 30;
		$node3->parent_source_id = 10;
		$node3->node_type = 'LinkForum';
		$node3->forum_type = 2; // FORUM_LINK
		$node3->forum_name = 'External Documentation 🌐';
		$node3->forum_link = 'https://docs.example.com';
		$node3->display_order = 2;

		$node4 = new forum_dto();
		$node4->source_id = 40;
		$node4->parent_source_id = 20;
		$node4->node_type = 'Forum';
		$node4->forum_type = 1;
		$node4->forum_name = 'Sub-forum PHP & Security';
		$node4->display_order = 1;

		$nodes = [$node1, $node2, $node3, $node4];

		$res = $builder->build_tree($nodes, 'nearest', 1);
		$ordered = $res['nodes'];

		// Verify Nested Set Invariants
		$n1 = $ordered[10];
		$n2 = $ordered[20];
		$n3 = $ordered[30];
		$n4 = $ordered[40];

		// Node 1 (Root Category): left=1, right=8
		if ($n1->left_id !== 1 || $n1->right_id !== 8)
		{
			throw new \Exception("Root category nested bounds incorrect: left={$n1->left_id}, right={$n1->right_id}");
		}

		// Node 2 (Subforum): left=2, right=5
		if ($n2->left_id !== 2 || $n2->right_id !== 5)
		{
			throw new \Exception("Subforum nested bounds incorrect: left={$n2->left_id}, right={$n2->right_id}");
		}

		// Node 4 (Sub-subforum): left=3, right=4 (inside Node 2)
		if ($n4->left_id !== 3 || $n4->right_id !== 4)
		{
			throw new \Exception("Nested child bounds incorrect: left={$n4->left_id}, right={$n4->right_id}");
		}

		// Node 3 (LinkForum): left=6, right=7
		if ($n3->left_id !== 6 || $n3->right_id !== 7)
		{
			throw new \Exception("Sibling link forum nested bounds incorrect: left={$n3->left_id}, right={$n3->right_id}");
		}

		// 2. Hierarchy Repair: Self-Parent detection
		$self_parent_node = new forum_dto();
		$self_parent_node->source_id = 50;
		$self_parent_node->parent_source_id = 50; // self-parent
		$self_parent_node->forum_name = 'Self Parent Node';

		$repair_res = $builder->build_tree([$self_parent_node], 'nearest', 1);
		if (empty($repair_res['repairs']) || $repair_res['repairs'][0]['issue'] !== 'self_parent')
		{
			throw new \Exception("Self-parent repair was not triggered");
		}
		if ($repair_res['nodes'][50]->parent_source_id !== 0)
		{
			throw new \Exception("Self-parent node was not attached to root");
		}

		// 3. Hierarchy Repair: Cycle Detection (A -> B -> A)
		$cycle_a = new forum_dto();
		$cycle_a->source_id = 61;
		$cycle_a->parent_source_id = 62;
		$cycle_a->forum_name = 'Cycle A';

		$cycle_b = new forum_dto();
		$cycle_b->source_id = 62;
		$cycle_b->parent_source_id = 61;
		$cycle_b->forum_name = 'Cycle B';

		$cycle_res = $builder->build_tree([$cycle_a, $cycle_b], 'nearest', 1);
		if (empty($cycle_res['repairs']))
		{
			throw new \Exception("Cycle detection repair was not triggered");
		}

		// 4. Hierarchy Repair: Missing / Orphaned Parent
		$orphan_node = new forum_dto();
		$orphan_node->source_id = 70;
		$orphan_node->parent_source_id = 99999; // Nonexistent parent
		$orphan_node->forum_name = 'Orphan Node';

		$orphan_res = $builder->build_tree([$orphan_node], 'root', 1);
		if (empty($orphan_res['repairs']) || $orphan_res['repairs'][0]['issue'] !== 'missing_parent')
		{
			throw new \Exception("Missing parent repair was not triggered");
		}

		return true;
	}
}
