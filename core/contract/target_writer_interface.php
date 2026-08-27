<?php
/**
 * phpBB Migration Center Extension
 *
 * @copyright (c) 2026 phpBB SEO Team
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace phpbbseo\migrationcenter\core\contract;

use phpbbseo\migrationcenter\core\dto\user_dto;
use phpbbseo\migrationcenter\core\dto\group_dto;
use phpbbseo\migrationcenter\core\dto\forum_dto;
use phpbbseo\migrationcenter\core\dto\topic_dto;
use phpbbseo\migrationcenter\core\dto\post_dto;
use phpbbseo\migrationcenter\core\dto\attachment_dto;

/**
 * Target Writer Interface
 * Writes normalized DTOs into target phpBB database and storage while preserving all invariants.
 */
interface target_writer_interface
{
	/**
	 * Write a batch of users
	 *
	 * @param user_dto[] $users
	 * @param array $options
	 * @return array Array of [source_id => ['target_id' => int, 'status' => string, 'error' => ?string]]
	 */
	public function write_users(array $users, array $options = []): array;

	/**
	 * Write a batch of groups
	 *
	 * @param group_dto[] $groups
	 * @param array $options
	 * @return array
	 */
	public function write_groups(array $groups, array $options = []): array;

	/**
	 * Reconcile user group memberships
	 *
	 * @param array $memberships
	 * @param array $options
	 * @return array
	 */
	public function write_group_memberships(array $memberships, array $options = []): array;

	/**
	 * Write global permissions for groups
	 *
	 * @param array $permissions
	 * @param array $options
	 * @return array
	 */
	public function write_global_permissions(array $permissions, array $options = []): array;

	/**
	 * Write a batch of forums/categories
	 *
	 * @param forum_dto[] $forums
	 * @param array $options
	 * @return array
	 */
	public function write_forums(array $forums, array $options = []): array;

	/**
	 * Write forum-scoped (node) permissions
	 *
	 * @param array $permissions
	 * @param array $options
	 * @return array
	 */
	public function write_node_permissions(array $permissions, array $options = []): array;

	/**
	 * Write a batch of topics
	 *
	 * @param topic_dto[] $topics
	 * @param array $options
	 * @return array
	 */
	public function write_topics(array $topics, array $options = []): array;

	/**
	 * Write a batch of posts
	 *
	 * @param post_dto[] $posts
	 * @param array $options
	 * @return array
	 */
	public function write_posts(array $posts, array $options = []): array;

	/**
	 * Write a batch of attachments
	 *
	 * @param attachment_dto[] $attachments
	 * @param array $options
	 * @return array
	 */
	public function write_attachments(array $attachments, array $options = []): array;

	/**
	 * Perform recount, synchronization, and finalization
	 *
	 * @param array $steps_run
	 * @return void
	 */
	public function finalize(array $steps_run = []): void;
}
