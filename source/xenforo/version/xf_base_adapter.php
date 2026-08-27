<?php
/**
 * phpBB Migration Center Extension
 *
 * @copyright (c) 2026 phpBB SEO Team
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace phpbbseo\migrationcenter\source\xenforo\version;

use phpbbseo\migrationcenter\source\xenforo\adapter\xf_db_adapter;

/**
 * Base XenForo Version Adapter (Core 2.0 capabilities)
 */
class xf_base_adapter
{
	/** @var xf_db_adapter */
	protected $db;

	/**
	 * Constructor
	 *
	 * @param xf_db_adapter|null $db
	 */
	public function __construct(?xf_db_adapter $db = null)
	{
		$this->db = $db;
	}

	/**
	 * Get list of required tables for XenForo
	 *
	 * @return array
	 */
	public function get_required_tables(): array
	{
		return [
			'xf_user',
			'xf_user_authenticate',
			'xf_user_group',
			'xf_node',
			'xf_forum',
			'xf_thread',
			'xf_post',
			'xf_attachment',
			'xf_attachment_data',
			'xf_conversation_master',
			'xf_conversation_user',
			'xf_conversation_message',
			'xf_poll',
			'xf_poll_response',
			'xf_poll_vote',
			'xf_ban_email',
			'xf_user_ban',
			'xf_thread_watch',
			'xf_forum_watch',
		];
	}

	/**
	 * Get ordered list of supported migration steps
	 *
	 * @return array
	 */
	public function get_supported_steps(): array
	{
		return [
			'groups',
			'users',
			'group_memberships',
			'global_permissions',
			'forums',
			'node_permissions',
			'topics',
			'posts',
			'attachments',
			'avatars',
			'conversations',
			'conversation_messages',
			'conversation_attachments',
			'polls',
			'bans',
		];
	}

	/**
	 * Get feature compatibility breakdown
	 *
	 * @return array
	 */
	public function get_feature_compatibility(): array
	{
		return [
			'users'         => ['status' => 'supported', 'note' => 'Users, groups, profile data and password hashes'],
			'forums'        => ['status' => 'supported', 'note' => 'Categories and forum hierarchy'],
			'topics'        => ['status' => 'supported', 'note' => 'Normal, sticky, locked, unapproved topics'],
			'posts'         => ['status' => 'supported', 'note' => 'BBCode, quotes, code blocks, formatting'],
			'attachments'   => ['status' => 'supported', 'note' => 'Physical files and post attachments'],
			'pms'           => ['status' => 'supported', 'note' => 'Direct conversations and messages'],
			'polls'         => ['status' => 'supported', 'note' => 'Single/multiple choice polls and votes'],
			'bans'          => ['status' => 'supported', 'note' => 'User account and email bans'],
			'subscriptions' => ['status' => 'supported', 'note' => 'Forum and topic notification watches'],
		];
	}
}
