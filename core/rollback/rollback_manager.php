<?php
/**
 * phpBB Migration Center Extension
 *
 * @copyright (c) 2026 phpBB SEO Team
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace phpbbseo\migrationcenter\core\rollback;

use phpbb\db\driver\driver_interface;
use phpbb\config\config;
use phpbb\cache\driver\driver_interface as cache_interface;
use phpbbseo\migrationcenter\core\contract\id_mapper_interface;
use phpbbseo\migrationcenter\core\state\lock_manager;
use phpbbseo\migrationcenter\core\state\state_manager;

/**
 * Production-Safe Rollback and Fast-Reset Manager
 */
class rollback_manager
{
	/** @var driver_interface */
	protected $db;

	/** @var config */
	protected $config;

	/** @var cache_interface */
	protected $cache;

	/** @var id_mapper_interface */
	protected $id_mapper;

	/** @var lock_manager */
	protected $lock_manager;

	/** @var state_manager */
	protected $state_manager;

	/** @var string */
	protected $table_prefix;

	/** @var string */
	protected $phpbb_root_path;

	/**
	 * Constructor
	 */
	public function __construct(
		driver_interface $db,
		config $config,
		cache_interface $cache,
		id_mapper_interface $id_mapper,
		lock_manager $lock_manager,
		state_manager $state_manager,
		string $table_prefix,
		string $phpbb_root_path
	) {
		$this->db = $db;
		$this->config = $config;
		$this->cache = $cache;
		$this->id_mapper = $id_mapper;
		$this->lock_manager = $lock_manager;
		$this->state_manager = $state_manager;
		$this->table_prefix = $table_prefix;
		$this->phpbb_root_path = rtrim($phpbb_root_path, '/\\') . '/';
	}

	/**
	 * Check if a run qualifies for fast zero-import reset
	 *
	 * @param string $run_id
	 * @return bool
	 */
	public function can_fast_reset(string $run_id): bool
	{
		$run = $this->state_manager->get_run($run_id);
		if (!$run)
		{
			return false;
		}

		$sql = 'SELECT COUNT(*) as cnt FROM ' . $this->table_prefix . "migration_id_map 
				WHERE run_id = '" . $this->db->sql_escape($run_id) . "'";
		$res = $this->db->sql_query($sql);
		$mappings_count = (int)$this->db->sql_fetchfield('cnt');
		$this->db->sql_freeresult($res);

		if ($mappings_count > 0)
		{
			return false;
		}

		$steps = $this->state_manager->get_steps($run_id);
		foreach ($steps as $s)
		{
			if ((int)$s['imported_records'] > 0 || (string)$s['current_cursor'] !== '0')
			{
				return false;
			}
		}

		return true;
	}

	/**
	 * Perform Fast Reset on zero-import run
	 *
	 * @param string $run_id
	 * @return array
	 */
	public function fast_reset(string $run_id): array
	{
		$run = $this->state_manager->get_run($run_id);
		if (!$run)
		{
			throw new \InvalidArgumentException("Migration run not found: {$run_id}");
		}

		if (!$this->can_fast_reset($run_id))
		{
			throw new \RuntimeException("Fast reset not allowed: run contains imported records or mappings. Perform full rollback instead.");
		}

		$lock_name = 'migration_' . $run->source_system;
		$this->lock_manager->force_release($lock_name);

		$this->state_manager->update_run_status($run_id, 'abandoned');

		return [
			'run_id'     => $run_id,
			'status'     => 'abandoned',
			'fast_reset' => true,
			'message'    => 'Zero-import run successfully abandoned. Stale lock released.',
		];
	}

	/**
	 * Execute full reverse-topological rollback for a migration run
	 *
	 * @param string $run_id
	 * @param string $confirmation_word Must be exactly 'ROLLBACK'
	 * @return array
	 * @throws \InvalidArgumentException
	 * @throws \RuntimeException
	 */
	public function rollback(string $run_id, string $confirmation_word): array
	{
		if (trim(strtoupper($confirmation_word)) !== 'ROLLBACK')
		{
			throw new \InvalidArgumentException("Invalid confirmation word. You must type 'ROLLBACK' to confirm deletion.");
		}

		$run = $this->state_manager->get_run($run_id);
		if (!$run)
		{
			throw new \InvalidArgumentException("Migration run not found: {$run_id}");
		}

		$lock_name = 'migration_' . $run->source_system;
		if ($this->lock_manager->is_locked($lock_name) && $run->status === 'running')
		{
			$lock = $this->lock_manager->get_lock($lock_name);
			if ($lock && (time() - $lock['heartbeat_at']) < 300)
			{
				throw new \RuntimeException("Cannot rollback while migration worker is actively running. Pause or cancel the run first.");
			}
		}

		$this->state_manager->update_run_status($run_id, 'rolling_back');

		$audit = [
			'run_id'         => $run_id,
			'started_at'     => time(),
			'deleted'        => [],
			'files_removed'  => 0,
			'errors'         => [],
		];

		try
		{
			// 1. Search wordmatch entries
			$post_target_ids = $this->get_mapped_target_ids($run_id, 'post');
			if (!empty($post_target_ids))
			{
				$this->db->sql_query('DELETE FROM ' . $this->table_prefix . 'search_wordmatch WHERE ' . $this->db->sql_in_set('post_id', $post_target_ids));
				$audit['deleted']['search_matches'] = count($post_target_ids);
			}

			// 2. Poll votes & options
			$poll_topic_ids = $this->get_mapped_target_ids($run_id, 'poll');
			if (!empty($poll_topic_ids))
			{
				$this->db->sql_query('DELETE FROM ' . $this->table_prefix . 'poll_votes WHERE ' . $this->db->sql_in_set('topic_id', $poll_topic_ids));
				$this->db->sql_query('DELETE FROM ' . $this->table_prefix . 'poll_options WHERE ' . $this->db->sql_in_set('topic_id', $poll_topic_ids));
				$audit['deleted']['polls'] = count($poll_topic_ids);
			}

			// 3. PM attachments and physical files
			$pm_attach_ids = $this->get_mapped_target_ids($run_id, 'privmsg_attachment');
			if (!empty($pm_attach_ids))
			{
				$audit['files_removed'] += $this->delete_attachment_files_and_rows($pm_attach_ids);
				$audit['deleted']['pm_attachments'] = count($pm_attach_ids);
			}

			// 4. PM recipients & messages
			$pm_msg_ids = $this->get_mapped_target_ids($run_id, 'privmsg');
			if (!empty($pm_msg_ids))
			{
				$this->db->sql_query('DELETE FROM ' . $this->table_prefix . 'privmsgs_to WHERE ' . $this->db->sql_in_set('msg_id', $pm_msg_ids));
				$this->db->sql_query('DELETE FROM ' . $this->table_prefix . 'privmsgs WHERE ' . $this->db->sql_in_set('msg_id', $pm_msg_ids));
				$audit['deleted']['privmsgs'] = count($pm_msg_ids);
			}

			// 5. Post attachments and physical files
			$post_attach_ids = $this->get_mapped_target_ids($run_id, 'attachment');
			if (!empty($post_attach_ids))
			{
				$audit['files_removed'] += $this->delete_attachment_files_and_rows($post_attach_ids);
				$audit['deleted']['post_attachments'] = count($post_attach_ids);
			}

			// 6. Posts
			if (!empty($post_target_ids))
			{
				$this->db->sql_query('DELETE FROM ' . $this->table_prefix . 'posts WHERE ' . $this->db->sql_in_set('post_id', $post_target_ids));
				$audit['deleted']['posts'] = count($post_target_ids);
			}

			// 7. Topics and topic trackers
			$topic_target_ids = $this->get_mapped_target_ids($run_id, 'topic');
			if (!empty($topic_target_ids))
			{
				$this->db->sql_query('DELETE FROM ' . $this->table_prefix . 'topics_track WHERE ' . $this->db->sql_in_set('topic_id', $topic_target_ids));
				$this->db->sql_query('DELETE FROM ' . $this->table_prefix . 'topics_posted WHERE ' . $this->db->sql_in_set('topic_id', $topic_target_ids));
				$this->db->sql_query('DELETE FROM ' . $this->table_prefix . 'topics WHERE ' . $this->db->sql_in_set('topic_id', $topic_target_ids));
				$audit['deleted']['topics'] = count($topic_target_ids);
			}

			// 8. Forum-level ACL entries
			$forum_target_ids = $this->get_mapped_target_ids($run_id, 'forum');
			if (!empty($forum_target_ids))
			{
				$this->db->sql_query('DELETE FROM ' . $this->table_prefix . 'acl_groups WHERE ' . $this->db->sql_in_set('forum_id', $forum_target_ids));
				$this->db->sql_query('DELETE FROM ' . $this->table_prefix . 'acl_users WHERE ' . $this->db->sql_in_set('forum_id', $forum_target_ids));
			}

			// 9. Forums and forum trackers
			if (!empty($forum_target_ids))
			{
				$this->db->sql_query('DELETE FROM ' . $this->table_prefix . 'forums_track WHERE ' . $this->db->sql_in_set('forum_id', $forum_target_ids));
				$this->db->sql_query('DELETE FROM ' . $this->table_prefix . 'forums WHERE ' . $this->db->sql_in_set('forum_id', $forum_target_ids));
				$audit['deleted']['forums'] = count($forum_target_ids);
			}

			// 10. Avatar files on disk
			$avatar_target_ids = $this->get_mapped_target_ids($run_id, 'avatar');
			if (!empty($avatar_target_ids))
			{
				$audit['files_removed'] += $this->delete_avatar_files($avatar_target_ids);
				$audit['deleted']['avatars'] = count($avatar_target_ids);
			}

			// 11. Migrated users (STRICT SAFETY: Never delete Anonymous=1 or Founders=2)
			$user_target_ids = $this->get_mapped_target_ids($run_id, 'user');
			$safe_user_ids = [];
			foreach ($user_target_ids as $uid)
			{
				$uid = (int)$uid;
				if ($uid > 2)
				{
					$safe_user_ids[] = $uid;
				}
			}

			if (!empty($safe_user_ids))
			{
				$this->db->sql_query('DELETE FROM ' . $this->table_prefix . 'user_group WHERE ' . $this->db->sql_in_set('user_id', $safe_user_ids));
				$this->db->sql_query('DELETE FROM ' . $this->table_prefix . 'profile_fields_data WHERE ' . $this->db->sql_in_set('user_id', $safe_user_ids));
				$this->db->sql_query('DELETE FROM ' . $this->table_prefix . 'users WHERE ' . $this->db->sql_in_set('user_id', $safe_user_ids));
				$audit['deleted']['users'] = count($safe_user_ids);
			}

			// 12. Custom groups (STRICT SAFETY: Never delete core groups <= 7)
			$group_target_ids = $this->get_mapped_target_ids($run_id, 'group');
			$safe_group_ids = [];
			foreach ($group_target_ids as $gid)
			{
				$gid = (int)$gid;
				if ($gid > 7)
				{
					$safe_group_ids[] = $gid;
				}
			}

			if (!empty($safe_group_ids))
			{
				$this->db->sql_query('DELETE FROM ' . $this->table_prefix . 'groups WHERE ' . $this->db->sql_in_set('group_id', $safe_group_ids));
				$audit['deleted']['groups'] = count($safe_group_ids);
			}

			// 13. Bans
			$ban_target_ids = $this->get_mapped_target_ids($run_id, 'ban');
			if (!empty($ban_target_ids))
			{
				$this->db->sql_query('DELETE FROM ' . $this->table_prefix . 'banlist WHERE ' . $this->db->sql_in_set('ban_id', $ban_target_ids));
				$audit['deleted']['bans'] = count($ban_target_ids);
			}

			// 14. Clear run-owned mappings
			$this->db->sql_query('DELETE FROM ' . $this->table_prefix . "migration_id_map WHERE run_id = '" . $this->db->sql_escape($run_id) . "'");

			// 15. Reset step counters
			$this->db->sql_query('UPDATE ' . $this->table_prefix . "migration_steps 
				SET status = 'pending', current_cursor = '0', imported_records = 0, skipped_records = 0, failed_records = 0 
				WHERE run_id = '" . $this->db->sql_escape($run_id) . "'");

			// 16. Recalculate native phpBB board statistics
			$this->recalculate_board_stats();

			// 17. Release lock and update run status to rolled_back
			$this->lock_manager->force_release($lock_name);
			$this->state_manager->update_run_status($run_id, 'rolled_back', '', $audit);

			$audit['completed_at'] = time();
			$audit['status'] = 'rolled_back';

			return $audit;
		}
		catch (\Throwable $e)
		{
			$this->state_manager->update_run_status($run_id, 'rollback_failed');
			$this->state_manager->log_error(
				$run_id,
				'rollback',
				'ROLLBACK_ERROR',
				$e->getMessage(),
				'fatal',
				'core',
				'0',
				['trace' => $e->getTraceAsString()]
			);
			throw new \RuntimeException("Rollback failed: " . $e->getMessage(), 0, $e);
		}
	}

	/**
	 * Get mapped target IDs for a content type in a run
	 */
	protected function get_mapped_target_ids(string $run_id, string $content_type): array
	{
		$sql = 'SELECT target_id FROM ' . $this->table_prefix . "migration_id_map 
				WHERE run_id = '" . $this->db->sql_escape($run_id) . "' 
				AND content_type = '" . $this->db->sql_escape($content_type) . "'";
		$res = $this->db->sql_query($sql);
		$ids = [];
		while ($r = $this->db->sql_fetchrow($res))
		{
			$ids[] = $r['target_id'];
		}
		$this->db->sql_freeresult($res);
		return $ids;
	}

	/**
	 * Delete attachment physical files and rows
	 */
	protected function delete_attachment_files_and_rows(array $attach_ids): int
	{
		if (empty($attach_ids))
		{
			return 0;
		}

		$sql = 'SELECT physical_filename, thumbnail FROM ' . $this->table_prefix . 'attachments 
				WHERE ' . $this->db->sql_in_set('attach_id', $attach_ids);
		$res = $this->db->sql_query($sql);
		$files_deleted = 0;
		$upload_dir = $this->phpbb_root_path . 'files/';

		while ($r = $this->db->sql_fetchrow($res))
		{
			$main_file = $upload_dir . $r['physical_filename'];
			if (file_exists($main_file) && is_file($main_file))
			{
				@unlink($main_file);
				$files_deleted++;
			}

			if (!empty($r['thumbnail']))
			{
				$thumb_file = $upload_dir . 'thumb_' . $r['physical_filename'];
				if (file_exists($thumb_file) && is_file($thumb_file))
				{
					@unlink($thumb_file);
					$files_deleted++;
				}
			}
		}
		$this->db->sql_freeresult($res);

		$this->db->sql_query('DELETE FROM ' . $this->table_prefix . 'attachments WHERE ' . $this->db->sql_in_set('attach_id', $attach_ids));

		return $files_deleted;
	}

	/**
	 * Delete avatar physical files for user target IDs
	 */
	protected function delete_avatar_files(array $user_ids): int
	{
		if (empty($user_ids))
		{
			return 0;
		}

		$sql = 'SELECT user_avatar FROM ' . $this->table_prefix . 'users 
				WHERE ' . $this->db->sql_in_set('user_id', $user_ids) . " AND user_avatar_type = 'avatar.driver.upload'";
		$res = $this->db->sql_query($sql);
		$files_deleted = 0;
		$avatar_dir = $this->phpbb_root_path . 'images/avatars/upload/';

		while ($r = $this->db->sql_fetchrow($res))
		{
			if (!empty($r['user_avatar']))
			{
				$file = $avatar_dir . $r['user_avatar'];
				if (file_exists($file) && is_file($file))
				{
					@unlink($file);
					$files_deleted++;
				}
			}
		}
		$this->db->sql_freeresult($res);

		return $files_deleted;
	}

	/**
	 * Recalculate board statistics after deletion
	 */
	protected function recalculate_board_stats(): void
	{
		$res = $this->db->sql_query('SELECT COUNT(*) as cnt FROM ' . $this->table_prefix . 'posts');
		$posts_cnt = (int)$this->db->sql_fetchfield('cnt');
		$this->db->sql_freeresult($res);

		$res = $this->db->sql_query('SELECT COUNT(*) as cnt FROM ' . $this->table_prefix . 'topics');
		$topics_cnt = (int)$this->db->sql_fetchfield('cnt');
		$this->db->sql_freeresult($res);

		$res = $this->db->sql_query('SELECT COUNT(*) as cnt FROM ' . $this->table_prefix . 'users WHERE user_type <> 2');
		$users_cnt = (int)$this->db->sql_fetchfield('cnt');
		$this->db->sql_freeresult($res);

		$this->config->set('num_posts', (string)$posts_cnt, false);
		$this->config->set('num_topics', (string)$topics_cnt, false);
		$this->config->set('num_users', (string)$users_cnt, false);

		$this->cache->purge();
	}
}