<?php
/**
 * phpBB Migration Center Extension
 *
 * @copyright (c) 2026 phpBB SEO Team
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace phpbbseo\migrationcenter\core\finalization;

use phpbbseo\migrationcenter\core\contract\id_mapper_interface;

/**
 * phpBB Board Finalizer & Recount Engine
 */
class phpbb_finalizer
{
	/** @var \phpbb\db\driver\driver_interface */
	protected $db;

	/** @var \phpbb\config\config */
	protected $config;

	/** @var \phpbb\cache\driver\driver_interface */
	protected $cache;

	/** @var id_mapper_interface */
	protected $id_mapper;

	/** @var string */
	protected $table_prefix;

	/**
	 * Constructor
	 */
	public function __construct(
		\phpbb\db\driver\driver_interface $db,
		\phpbb\config\config $config,
		\phpbb\cache\driver\driver_interface $cache,
		id_mapper_interface $id_mapper,
		string $table_prefix
	) {
		$this->db = $db;
		$this->config = $config;
		$this->cache = $cache;
		$this->id_mapper = $id_mapper;
		$this->table_prefix = $table_prefix;
	}

	/**
	 * Finalize topic pointers and post counts
	 *
	 * @param string $run_id
	 * @param array $options
	 * @return array
	 */
	public function finalize_topics(string $run_id, array $options = []): array
	{
		$dry_run = !empty($options['dry_run']);
		$topics_finalized = 0;
		$errors = [];

		if (!$dry_run)
		{
			$sql = 'UPDATE ' . $this->table_prefix . 'topics t
					JOIN (
						SELECT topic_id,
							   MIN(post_id) as min_p,
							   MAX(post_id) as max_p,
							   MAX(post_time) as last_time,
							   COUNT(*) as total_posts
						FROM ' . $this->table_prefix . 'posts
						WHERE post_visibility = 1
						GROUP BY topic_id
					) p ON (t.topic_id = p.topic_id)
					SET t.topic_first_post_id = p.min_p,
						t.topic_last_post_id = p.max_p,
						t.topic_last_post_time = p.last_time,
						t.topic_posts_approved = GREATEST(0, p.total_posts - 1)';
			$this->db->sql_query($sql);
			$topics_finalized = $this->db->sql_affectedrows();
		}

		return [
			'status'           => 'success',
			'topics_finalized' => $topics_finalized,
			'errors'           => $errors,
		];
	}

	/**
	 * Finalize forum counters, last posts, and nested-set trees
	 *
	 * @param string $run_id
	 * @param array $options
	 * @return array
	 */
	public function finalize_forums(string $run_id, array $options = []): array
	{
		$dry_run = !empty($options['dry_run']);
		$forums_finalized = 0;

		$sql = 'SELECT forum_id, forum_type FROM ' . $this->table_prefix . 'forums';
		$result = $this->db->sql_query($sql);

		while ($f = $this->db->sql_fetchrow($result))
		{
			$forum_id = (int)$f['forum_id'];
			$forum_type = (int)$f['forum_type'];

			// Only standard forums (FORUM_POST = 1) track topic and post counts
			if ($forum_type === 1)
			{
				// Count topics in this forum by visibility
				$sql_tc = 'SELECT topic_visibility, COUNT(*) as cnt FROM ' . $this->table_prefix . 'topics WHERE forum_id = ' . $forum_id . ' GROUP BY topic_visibility';
				$res_tc = $this->db->sql_query($sql_tc);
				$topics_approved = 0;
				$topics_unapproved = 0;
				$topics_softdeleted = 0;

				while ($tc = $this->db->sql_fetchrow($res_tc))
				{
					$vis = (int)$tc['topic_visibility'];
					if ($vis === 1) {
						$topics_approved = (int)$tc['cnt'];
					} else if ($vis === 0 || $vis === 2) {
						$topics_unapproved += (int)$tc['cnt'];
					} else if ($vis === 3) {
						$topics_softdeleted = (int)$tc['cnt'];
					}
				}
				$this->db->sql_freeresult($res_tc);

				// Count posts in this forum
				$sql_pc = 'SELECT post_visibility, COUNT(*) as cnt FROM ' . $this->table_prefix . 'posts WHERE forum_id = ' . $forum_id . ' GROUP BY post_visibility';
				$res_pc = $this->db->sql_query($sql_pc);
				$posts_approved = 0;
				$posts_unapproved = 0;
				$posts_softdeleted = 0;

				while ($pc = $this->db->sql_fetchrow($res_pc))
				{
					$vis = (int)$pc['post_visibility'];
					if ($vis === 1) {
						$posts_approved = (int)$pc['cnt'];
					} else if ($vis === 0 || $vis === 2) {
						$posts_unapproved += (int)$pc['cnt'];
					} else if ($vis === 3) {
						$posts_softdeleted = (int)$pc['cnt'];
					}
				}
				$this->db->sql_freeresult($res_pc);

				// Find latest approved post for forum_last_post_*
				$sql_last = 'SELECT p.post_id, p.post_subject, p.post_time, p.poster_id, u.username, u.user_colour 
							 FROM ' . $this->table_prefix . 'posts p 
							 LEFT JOIN ' . $this->table_prefix . 'users u ON (p.poster_id = u.user_id) 
							 WHERE p.forum_id = ' . $forum_id . ' AND p.post_visibility = 1 
							 ORDER BY p.post_time DESC, p.post_id DESC';
				$res_last = $this->db->sql_query_limit($sql_last, 1);
				$last_p = $this->db->sql_fetchrow($res_last);
				$this->db->sql_freeresult($res_last);

				$update_arr = [
					'forum_topics_approved'    => $topics_approved,
					'forum_topics_unapproved'  => $topics_unapproved,
					'forum_topics_softdeleted' => $topics_softdeleted,
					'forum_posts_approved'     => $posts_approved,
					'forum_posts_unapproved'   => $posts_unapproved,
					'forum_posts_softdeleted'  => $posts_softdeleted,
					'forum_last_post_id'       => (int)($last_p['post_id'] ?? 0),
					'forum_last_post_subject'  => (string)($last_p['post_subject'] ?? ''),
					'forum_last_post_time'     => (int)($last_p['post_time'] ?? 0),
					'forum_last_poster_id'     => (int)($last_p['poster_id'] ?? 0),
					'forum_last_poster_name'   => (string)($last_p['username'] ?? ''),
					'forum_last_poster_colour' => (string)($last_p['user_colour'] ?? ''),
				];

				if (!$dry_run)
				{
					$sql_up = 'UPDATE ' . $this->table_prefix . 'forums SET ' . $this->db->sql_build_array('UPDATE', $update_arr) . ' WHERE forum_id = ' . $forum_id;
					$this->db->sql_query($sql_up);
				}
				$forums_finalized++;
			}
		}
		$this->db->sql_freeresult($result);

		// Rebuild nested-set tree boundaries (left_id, right_id) for all forums
		if (!$dry_run)
		{
			global $phpbb_root_path, $phpEx;
			if (!function_exists('recalc_nested_sets'))
			{
				$root = $phpbb_root_path ?: dirname(dirname(dirname(dirname(dirname(__DIR__))))) . '/';
				if (file_exists($root . 'includes/functions_admin.php'))
				{
					require_once $root . 'includes/functions_admin.php';
				}
			}

			if (function_exists('recalc_nested_sets'))
			{
				$new_id = 1;
				recalc_nested_sets($new_id, 'forum_id', $this->table_prefix . 'forums');
			}

			if (is_object($this->cache))
			{
				$this->cache->destroy('sql', $this->table_prefix . 'forums');
			}
		}

		return [
			'status' => 'success',
			'forums_finalized' => $forums_finalized,
		];
	}

	/**
	 * Finalize user post counts, unread PM totals, and newest registered user
	 *
	 * @param string $run_id
	 * @param array $options
	 * @return array
	 */
	public function finalize_users(string $run_id, array $options = []): array
	{
		$dry_run = !empty($options['dry_run']);
		$users_finalized = 0;

		if (!$dry_run)
		{
			// 1. Recalculate user_posts for all non-anonymous users in set-based update
			$sql = 'UPDATE ' . $this->table_prefix . 'users u 
					JOIN (
						SELECT poster_id, COUNT(*) as cnt 
						FROM ' . $this->table_prefix . 'posts 
						WHERE post_visibility = 1 AND poster_id > 1 
						GROUP BY poster_id
					) p ON (u.user_id = p.poster_id) 
					SET u.user_posts = p.cnt 
					WHERE u.user_type <> 2';
			$this->db->sql_query($sql);
			$users_finalized = $this->db->sql_affectedrows();

			// 2. Synchronize unread PM counts in set-based update
			$sql = 'UPDATE ' . $this->table_prefix . 'users u
					LEFT JOIN (
						SELECT user_id, COUNT(*) as cnt
						FROM ' . $this->table_prefix . 'privmsgs_to
						WHERE pm_unread = 1 AND pm_deleted = 0 AND folder_id = 0
						GROUP BY user_id
					) pm ON (u.user_id = pm.user_id)
					SET u.user_unread_privmsg = COALESCE(pm.cnt, 0),
						u.user_new_privmsg = 0
					WHERE u.user_id > 1';
			$this->db->sql_query($sql);
		}

		return [
			'status' => 'success',
			'users_finalized' => $users_finalized,
		];
	}

	/**
	 * Finalize and recalculate global board statistics in phpbb_config
	 *
	 * @param string $run_id
	 * @param array $options
	 * @return array
	 */
	public function finalize_global_stats(string $run_id, array $options = []): array
	{
		$dry_run = !empty($options['dry_run']);

		// 1. Total approved posts
		$sql = 'SELECT COUNT(*) as cnt FROM ' . $this->table_prefix . 'posts WHERE post_visibility = 1';
		$num_posts = (int)$this->db->sql_fetchfield('cnt', 0, $this->db->sql_query($sql));

		// 2. Total approved topics
		$sql = 'SELECT COUNT(*) as cnt FROM ' . $this->table_prefix . 'topics WHERE topic_visibility = 1';
		$num_topics = (int)$this->db->sql_fetchfield('cnt', 0, $this->db->sql_query($sql));

		// 3. Total active registered users (exclude anonymous and bots)
		$sql = 'SELECT COUNT(*) as cnt FROM ' . $this->table_prefix . 'users WHERE user_type IN (0, 3)';
		$num_users = (int)$this->db->sql_fetchfield('cnt', 0, $this->db->sql_query($sql));

		// 4. Newest user (highest registered user_id)
		$sql = 'SELECT user_id, username, user_colour FROM ' . $this->table_prefix . 'users WHERE user_type IN (0, 3) AND user_id > 2 ORDER BY user_id DESC';
		$newest_user = $this->db->sql_fetchrow($this->db->sql_query_limit($sql, 1));

		if (!$dry_run)
		{
			$this->config->set('num_posts', $num_posts);
			$this->config->set('num_topics', $num_topics);
			$this->config->set('num_users', $num_users);

			if ($newest_user)
			{
				$this->config->set('newest_user_id', (int)$newest_user['user_id']);
				$this->config->set('newest_username', (string)$newest_user['username']);
				$this->config->set('newest_user_colour', (string)$newest_user['user_colour']);
			}
		}

		return [
			'status'     => 'success',
			'num_posts'  => $num_posts,
			'num_topics' => $num_topics,
			'num_users'  => $num_users,
			'newest_user'=> $newest_user ? (string)$newest_user['username'] : '',
		];
	}

	/**
	 * Run all finalization routines in order
	 *
	 * @param string $run_id
	 * @param array $options
	 * @return array
	 */
	public function run_all_finalizers(string $run_id, array $options = []): array
	{
		$results = [];
		$results['topics'] = $this->finalize_topics($run_id, $options);
		$results['forums'] = $this->finalize_forums($run_id, $options);
		$results['users']  = $this->finalize_users($run_id, $options);
		$results['stats']  = $this->finalize_global_stats($run_id, $options);

		if (empty($options['dry_run']))
		{
			$this->cache->purge();
		}

		return $results;
	}

	/**
	 * Run all finalization routines (alias for backward compatibility)
	 *
	 * @param string $run_id
	 * @param array $steps
	 * @param array $options
	 * @return array
	 */
	public function finalize_all(string $run_id, array $steps = [], array $options = []): array
	{
		return $this->run_all_finalizers($run_id, $options);
	}
}
