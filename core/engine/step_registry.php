<?php
/**
 * phpBB Migration Center Extension
 *
 * @copyright (c) 2026 phpBB SEO Team
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace phpbbseo\migrationcenter\core\engine;

use phpbbseo\migrationcenter\core\contract\step_interface;

/**
 * Registry of Migration Steps
 */
class step_registry
{
	/** @var step_interface[] */
	protected $steps = [];

	/**
	 * Register a step
	 *
	 * @param step_interface $step
	 * @return void
	 */
	public function register(step_interface $step): void
	{
		$this->steps[$step->get_name()] = $step;
	}

	/**
	 * Get a step by name
	 *
	 * @param string $name
	 * @return step_interface|null
	 */
	public function get(string $name): ?step_interface
	{
		return $this->steps[$name] ?? null;
	}

	/**
	 * Get all registered steps
	 *
	 * @return step_interface[]
	 */
	public function get_all(): array
	{
		return $this->steps;
	}

	/**
	 * Check if step exists
	 *
	 * @param string $name
	 * @return bool
	 */
	public function has(string $name): bool
	{
		return isset($this->steps[$name]);
	}

	/**
	 * Canonical stage order sequence
	 */
	public const CANONICAL_STAGE_ORDER = [
		'groups'                    => 1,
		'users'                     => 2,
		'group_memberships'         => 3,
		'global_permissions'        => 4,
		'forums'                    => 5,
		'node_permissions'          => 6,
		'topics'                    => 7,
		'posts'                     => 8,
		'attachments'               => 9,
		'avatars'                   => 10,
		'conversations'             => 11,
		'conversation_messages'     => 12,
		'conversation_attachments'  => 13,
		'polls'                     => 14,
		'bans'                      => 15,
		'finalization'              => 16,
		'search_index'              => 17,
		'final_verification'        => 18,
	];

	/**
	 * Resolve step order with dependency sorting (topological sort) & automatic dependency expansion
	 *
	 * @param array $requested_steps
	 * @return array Ordered step names
	 */
	public function resolve_order(array $requested_steps): array
	{
		// 1. Expand all transitive dependencies
		$expanded_steps = $requested_steps;
		$queue = $requested_steps;
		while (!empty($queue))
		{
			$current = array_shift($queue);
			$step = $this->get($current);
			if ($step)
			{
				foreach ($step->get_dependencies() as $dep)
				{
					if (!in_array($dep, $expanded_steps, true))
					{
						$expanded_steps[] = $dep;
						$queue[] = $dep;
					}
				}
			}
		}

		// 2. Pre-sort by canonical stage order before topological resolution
		usort($expanded_steps, function($a, $b) {
			$orderA = self::CANONICAL_STAGE_ORDER[$a] ?? 999;
			$orderB = self::CANONICAL_STAGE_ORDER[$b] ?? 999;
			return $orderA <=> $orderB;
		});

		// 3. Topological sort with canonical priority
		$resolved = [];
		$visited = [];
		$visiting = [];

		$visit = function($step_name) use (&$visit, &$resolved, &$visited, &$visiting) {
			if (isset($visited[$step_name]))
			{
				return;
			}
			if (isset($visiting[$step_name]))
			{
				throw new \RuntimeException("Circular dependency detected involving step: {$step_name}");
			}
			$visiting[$step_name] = true;

			$step = $this->get($step_name);
			if ($step)
			{
				$deps = $step->get_dependencies();
				// Sort dependencies by canonical order too
				usort($deps, function($a, $b) {
					$orderA = self::CANONICAL_STAGE_ORDER[$a] ?? 999;
					$orderB = self::CANONICAL_STAGE_ORDER[$b] ?? 999;
					return $orderA <=> $orderB;
				});

				foreach ($deps as $dep)
				{
					if ($this->has($dep))
					{
						$visit($dep);
					}
				}
			}

			unset($visiting[$step_name]);
			$visited[$step_name] = true;
			$resolved[] = $step_name;
		};

		foreach ($expanded_steps as $step_name)
		{
			$visit($step_name);
		}

		return $resolved;
	}
}
