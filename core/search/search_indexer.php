<?php
/**
 * phpBB Migration Center Extension
 *
 * @copyright (c) 2026 phpBB SEO Team
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace phpbbseo\migrationcenter\core\search;

use phpbbseo\migrationcenter\core\contract\id_mapper_interface;

/**
 * Incremental Search Indexer Service
 */
class search_indexer
{
	/** @var \phpbb\db\driver\driver_interface */
	protected $db;

	/** @var \phpbb\config\config */
	protected $config;

	/** @var \phpbb\auth\auth */
	protected $auth;

	/** @var \phpbb\user */
	protected $user;

	/** @var \phpbb\event\dispatcher_interface */
	protected $dispatcher;

	/** @var id_mapper_interface */
	protected $id_mapper;

	/** @var string */
	protected $table_prefix;

	/** @var string */
	protected $phpbb_root_path;

	/** @var string */
	protected $php_ext;

	/**
	 * Constructor
	 */
	public function __construct(
		\phpbb\db\driver\driver_interface $db,
		\phpbb\config\config $config,
		\phpbb\auth\auth $auth,
		\phpbb\user $user,
		\phpbb\event\dispatcher_interface $dispatcher,
		id_mapper_interface $id_mapper,
		string $table_prefix,
		string $phpbb_root_path,
		string $php_ext
	) {
		$this->db = $db;
		$this->config = $config;
		$this->auth = $auth;
		$this->user = $user;
		$this->dispatcher = $dispatcher;
		$this->id_mapper = $id_mapper;
		$this->table_prefix = $table_prefix;
		$this->phpbb_root_path = $phpbb_root_path;
		$this->php_ext = $php_ext;
	}

	/**
	 * Get detected search backend information
	 *
	 * @return array
	 */
	public function get_backend_info(): array
	{
		$search_type = (string)($this->config['search_type'] ?? '\phpbb\search\fulltext_native');

		return [
			'class'     => $search_type,
			'name'      => basename(str_replace('\\', '/', $search_type)),
			'installed' => class_exists($search_type),
		];
	}

	/**
	 * Instantiate phpBB's configured native search backend
	 *
	 * @return object|null
	 */
	public function get_backend_instance()
	{
		$error = false;
		$search_type = (string)($this->config['search_type'] ?? '\phpbb\search\fulltext_native');
		if (!class_exists($search_type))
		{
			$search_type = '\phpbb\search\fulltext_native';
		}

		try
		{
			return new $search_type($error, $this->phpbb_root_path, $this->php_ext, $this->auth, $this->config, $this->db, $this->user, $this->dispatcher);
		}
		catch (\Throwable $e)
		{
			return null;
		}
	}

	/**
	 * Incrementally index a batch of migration-owned posts
	 *
	 * @param string $run_id
	 * @param int $cursor
	 * @param int $batch_size
	 * @param array $options
	 * @return array
	 */
	public function index_posts(string $run_id, int $cursor = 0, int $batch_size = 200, array $options = []): array
	{
		$dry_run = !empty($options['dry_run']);
		$search = $this->get_backend_instance();

		$sql = 'SELECT p.post_id, p.topic_id, p.forum_id, p.poster_id, p.post_subject, p.post_text, p.post_visibility 
				FROM ' . $this->table_prefix . 'posts p 
				INNER JOIN ' . $this->table_prefix . "migration_id_map m 
					ON (m.target_id = p.post_id AND m.content_type = 'post') 
				WHERE p.post_id > " . $cursor . ' 
				ORDER BY p.post_id ASC';

		$res = $this->db->sql_query_limit($sql, $batch_size);
		$indexed = 0;
		$skipped = 0;
		$failed = 0;
		$max_seen = $cursor;
		$rows_count = 0;

		$prev_load_upd = (bool)($this->config['fulltext_native_load_upd'] ?? true);
		if (!$prev_load_upd)
		{
			$this->config->set('fulltext_native_load_upd', 1);
		}

		while ($p = $this->db->sql_fetchrow($res))
		{
			$rows_count++;
			$pid = (int)$p['post_id'];
			if ($pid > $max_seen)
			{
				$max_seen = $pid;
			}

			// Only approved posts (post_visibility = 1) are indexed
			if ((int)$p['post_visibility'] !== 1)
			{
				$skipped++;
				continue;
			}

			if ($dry_run)
			{
				$indexed++;
				continue;
			}

			try
			{
				if ($search && method_exists($search, 'index'))
				{
					$msg = (string)$p['post_text'];
					$subj = (string)$p['post_subject'];
					$search->index(
						'post',
						$pid,
						$msg,
						$subj,
						(int)$p['poster_id'],
						(int)$p['forum_id']
					);
				}
				$indexed++;
			}
			catch (\Throwable $e)
			{
				$failed++;
			}
		}
		$this->db->sql_freeresult($res);

		return [
			'indexed'      => $indexed,
			'skipped'      => $skipped,
			'failed'       => $failed,
			'next_cursor'  => $max_seen,
			'is_completed' => ($rows_count < $batch_size),
		];
	}

	/**
	 * Search for keywords through the native search index
	 *
	 * @param string $keywords
	 * @return array List of matching post IDs
	 */
	public function search_keywords(string $keywords): array
	{
		$backend = $this->get_backend_instance();
		$words = [];
		if ($backend && method_exists($backend, 'split_message'))
		{
			$words = $backend->split_message($keywords);
		}
		else
		{
			$words = preg_split('/[\s,]+/u', trim($keywords));
		}

		if (empty($words))
		{
			return [];
		}

		$post_ids = [];
		foreach ($words as $w)
		{
			$w = trim($w);
			if ($w === '')
			{
				continue;
			}

			// Query phpbb_search_wordlist & phpbb_search_wordmatch
			$sql = 'SELECT m.post_id 
					FROM ' . $this->table_prefix . 'search_wordlist w 
					INNER JOIN ' . $this->table_prefix . 'search_wordmatch m ON (w.word_id = m.word_id) 
					WHERE w.word_text = \'' . $this->db->sql_escape(mb_strtolower($w)) . '\'';
			$res = $this->db->sql_query($sql);
			while ($r = $this->db->sql_fetchrow($res))
			{
				$post_ids[(int)$r['post_id']] = (int)$r['post_id'];
			}
			$this->db->sql_freeresult($res);
		}

		return array_values($post_ids);
	}

	/**
	 * Index a range of posts (alias for backward compatibility)
	 *
	 * @param string $run_id
	 * @param int $cursor
	 * @param int $batch_size
	 * @param array $options
	 * @return array
	 */
	public function index_range(string $run_id, int $cursor = 0, int $batch_size = 50000, array $options = []): array
	{
		return $this->index_posts($run_id, $cursor, $batch_size, $options);
	}
}
