<?php
/**
 * phpBB Migration Center Extension
 *
 * @copyright (c) 2026 phpBB SEO Team
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace phpbbseo\migrationcenter\source\xenforo\normalizer;

use phpbbseo\migrationcenter\core\dto\topic_dto;
use phpbbseo\migrationcenter\core\dto\migration_config_dto;
use phpbbseo\migrationcenter\source\xenforo\adapter\xf_db_adapter;

/**
 * XenForo Thread to phpBB Topic Normalizer
 */
class xf_topic_normalizer
{
	/** @var array Cached prefix ID => title map */
	protected $prefix_cache = [];

	/** @var bool */
	protected $prefixes_loaded = false;

	/**
	 * Load and cache prefix titles from XenForo
	 *
	 * @param xf_db_adapter $db
	 */
	public function load_prefixes(xf_db_adapter $db): void
	{
		if ($this->prefixes_loaded)
		{
			return;
		}

		$prefix = $db->get_prefix();
		$this->prefix_cache = [];

		try
		{
			// Load phrases for thread prefixes
			$sql = "SELECT phrase_title, phrase_text 
					FROM `{$prefix}phrase` 
					WHERE phrase_title LIKE 'thread_prefix.%'";
			$phrases = $db->fetch_all($sql);
			$phrase_map = [];
			foreach ($phrases as $ph)
			{
				$key = str_replace('thread_prefix.', '', $ph['phrase_title']);
				$phrase_map[$key] = $ph['phrase_text'];
			}

			// Load prefixes
			$sql = "SELECT prefix_id FROM `{$prefix}thread_prefix`";
			$prefixes = $db->fetch_all($sql);
			foreach ($prefixes as $p)
			{
				$pid = (int)$p['prefix_id'];
				$this->prefix_cache[$pid] = $phrase_map[$pid] ?? "Prefix #{$pid}";
			}
		}
		catch (\Throwable $e)
		{
		}

		$this->prefixes_loaded = true;
	}

	/**
	 * Set prefix cache directly (useful for unit tests)
	 *
	 * @param array $prefixes
	 */
	public function set_prefix_cache(array $prefixes): void
	{
		$this->prefix_cache = $prefixes;
		$this->prefixes_loaded = true;
	}

	/**
	 * Normalize a XenForo thread row into TopicDto
	 *
	 * @param array $row
	 * @param migration_config_dto $config
	 * @param array $deletion_log Optional deletion log row
	 * @return topic_dto|null Returns null if skipped (e.g. redirect)
	 */
	public function normalize_thread(array $row, migration_config_dto $config, array $deletion_log = []): ?topic_dto
	{
		$thread_id = (int)$row['thread_id'];
		$disc_type = (string)($row['discussion_type'] ?? 'discussion');
		$unknown_type_policy = $config->options['unknown_type_policy'] ?? 'normal_with_warning';

		$topic = new topic_dto();
		$topic->source_id = $thread_id;
		$topic->forum_source_id = (int)($row['node_id'] ?? 0);
		$topic->user_source_id = (int)($row['user_id'] ?? 0);
		$topic->source_username = trim((string)($row['username'] ?? ''));
		$topic->topic_time = (int)($row['post_date'] ?? time());
		$topic->reply_count = max(0, (int)($row['reply_count'] ?? 0));
		$topic->topic_views = max(0, (int)($row['view_count'] ?? 0));
		$topic->first_post_source_id = (int)($row['first_post_id'] ?? 0);
		$topic->last_post_source_id = (int)($row['last_post_id'] ?? 0);
		$topic->last_post_time = (int)($row['last_post_date'] ?? $topic->topic_time);
		$topic->last_post_source_user_id = (int)($row['last_post_user_id'] ?? 0);
		$topic->last_post_username = trim((string)($row['last_post_username'] ?? ''));
		$topic->discussion_type = $disc_type;
		$topic->raw_source_data = $row;

		// 1. Discussion Type Handling
		switch ($disc_type)
		{
			case 'discussion':
				break;

			case 'poll':
				$topic->unsupported_features[] = 'poll_data_deferred';
				break;

			case 'question':
				$topic->unsupported_features[] = 'question_solution_deferred';
				break;

			case 'article':
				$topic->unsupported_features[] = 'article_type_reduced';
				break;

			case 'redirect':
				// Redirects are skipped from ordinary topics
				return null;

			default:
				if ($unknown_type_policy === 'skip')
				{
					return null;
				}
				else if ($unknown_type_policy === 'stop')
				{
					throw new \RuntimeException("Encountered unknown discussion type '{$disc_type}' on Thread {$thread_id} with policy=stop.");
				}
				else
				{
					$topic->unsupported_features[] = "unknown_type_{$disc_type}_reduced";
				}
				break;
		}

		// 2. Open / Closed Status
		$is_open = !empty($row['discussion_open']);
		$topic->topic_status = $is_open ? 0 : 1; // 0 = ITEM_UNLOCKED, 1 = ITEM_LOCKED

		// 3. Sticky State
		$is_sticky = !empty($row['sticky']);
		$topic->topic_type = $is_sticky ? 1 : 0; // 0 = POST_NORMAL, 1 = POST_STICKY

		// 4. Visibility State
		$state = (string)($row['discussion_state'] ?? 'visible');
		switch ($state)
		{
			case 'visible':
				$topic->topic_visibility = 1; // ITEM_APPROVED
				break;

			case 'moderated':
				$topic->topic_visibility = 0; // ITEM_UNAPPROVED
				break;

			case 'deleted':
				$topic->topic_visibility = 2; // ITEM_DELETED (soft-deleted)
				if (!empty($deletion_log))
				{
					$topic->delete_time = (int)($deletion_log['delete_date'] ?? time());
					$topic->delete_user_source_id = (int)($deletion_log['delete_user_id'] ?? 0);
					$topic->delete_username = trim((string)($deletion_log['delete_username'] ?? ''));
					$topic->delete_reason = trim((string)($deletion_log['delete_reason'] ?? ''));
				}
				break;

			default:
				$topic->topic_visibility = 0; // ITEM_UNAPPROVED default safe
				$topic->unsupported_features[] = "unknown_state_{$state}_unapproved";
				break;
		}

		// 5. Title & Prefix Transformation
		$raw_title = trim((string)($row['title'] ?? ''));
		$topic->original_title = $raw_title;
		$prefix_id = (int)($row['prefix_id'] ?? 0);
		$topic->prefix_id = $prefix_id;

		$prefix_policy = $config->options['prefix_policy'] ?? 'prepend_title';

		if ($prefix_id > 0 && isset($this->prefix_cache[$prefix_id]))
		{
			$topic->prefix_title = $this->prefix_cache[$prefix_id];

			if ($prefix_policy === 'prepend_title')
			{
				$prefix_tag = "[{$topic->prefix_title}]";
				// Avoid duplicate prefixing on resume/rerun
				if (mb_strpos($raw_title, $prefix_tag, 0, 'UTF-8') !== 0)
				{
					$raw_title = $prefix_tag . ' ' . $raw_title;
				}
			}
			else
			{
				$topic->unsupported_features[] = 'prefix_ignored';
			}
		}

		// Fallback for empty title
		if ($raw_title === '')
		{
			$raw_title = "Untitled Topic #{$thread_id}";
			$topic->unsupported_features[] = 'empty_title_fallback';
		}

		// Truncate cleanly to 255 Unicode characters
		if (mb_strlen($raw_title, 'UTF-8') > 255)
		{
			$raw_title = mb_substr($raw_title, 0, 255, 'UTF-8');
			$topic->unsupported_features[] = 'title_truncated';
		}

		$topic->topic_title = $raw_title;

		return $topic;
	}
}
