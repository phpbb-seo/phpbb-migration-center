<?php
/**
 * phpBB Migration Center Extension
 *
 * @copyright (c) 2026 phpBB SEO Team
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace phpbbseo\migrationcenter\core\writer;

use phpbb\db\driver\driver_interface;
use phpbb\config\config;
use phpbbseo\migrationcenter\core\contract\target_writer_interface;
use phpbbseo\migrationcenter\core\contract\id_mapper_interface;
use phpbbseo\migrationcenter\core\dto\user_dto;
use phpbbseo\migrationcenter\core\dto\group_dto;
use phpbbseo\migrationcenter\core\dto\forum_dto;
use phpbbseo\migrationcenter\core\dto\topic_dto;
use phpbbseo\migrationcenter\core\dto\post_dto;
use phpbbseo\migrationcenter\core\dto\attachment_dto;

/**
 * phpBB Target Writer
 */
class phpbb_target_writer implements target_writer_interface
{
	/** @var driver_interface */
	protected $db;

	/** @var config */
	protected $config;

	/** @var mixed */
	protected $cache;

	/** @var id_mapper_interface */
	protected $id_mapper;

	/** @var string */
	protected $table_prefix;

	/**
	 * Constructor
	 *
	 * @param driver_interface $db
	 * @param config $config
	 * @param mixed $cache
	 * @param id_mapper_interface $id_mapper
	 * @param string $table_prefix
	 */
	public function __construct(
		driver_interface $db,
		config $config,
		$cache,
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
	 * Write a batch of users
	 *
	 * @param user_dto[] $users
	 * @param array $options
	 * @return array
	 */
	public function write_users(array $users, array $options = []): array
	{
		$results = [];
		$run_id = (string)($options['run_id'] ?? '');
		$source_system = (string)($options['source_system'] ?? 'xenforo');
		$preserve_ids = !empty($options['preserve_ids']);
		$dup_username_policy = (string)($options['duplicate_username_policy'] ?? 'rename');
		$dup_email_policy = (string)($options['duplicate_email_policy'] ?? 'keep');

		foreach ($users as $user)
		{
			$source_id = $user->source_id;

			try
			{
				// 1. Check if user was already migrated in this or previous run (Idempotency)
				$existing_target_id = $this->id_mapper->get_target_id($source_system, 'user', $source_id);
				if ($existing_target_id !== null)
				{
					// Verify target user actually exists
					$sql = 'SELECT user_id FROM ' . $this->table_prefix . 'users WHERE user_id = ' . (int)$existing_target_id;
					$res = $this->db->sql_query($sql);
					$still_exists = $this->db->sql_fetchfield('user_id');
					$this->db->sql_freeresult($res);

					if ($still_exists)
					{
						$results[$source_id] = [
							'target_id' => (int)$existing_target_id,
							'status'    => 'success',
							'error'     => null,
							'note'      => 'already_mapped',
						];
						continue;
					}
				}

				// 2. Resolve username and detect collisions
				$username = $user->username;
				$username_clean = $user->username_clean ?: (function_exists('utf8_clean_string') ? utf8_clean_string($username) : mb_strtolower($username, 'UTF-8'));

				// Check existing user by clean username
				$sql = 'SELECT user_id, user_type, username FROM ' . $this->table_prefix . 'users WHERE username_clean = ' . "'" . $this->db->sql_escape($username_clean) . "'";
				$res = $this->db->sql_query($sql);
				$collision_row = $this->db->sql_fetchrow($res);
				$this->db->sql_freeresult($res);

				$was_renamed = false;
				if ($collision_row)
				{
					// Protected account (Founder / Admin ID 2)
					if ($dup_username_policy === 'stop')
					{
						throw new \RuntimeException("Username collision on '{$username}' and duplicate policy is 'stop'.");
					}
					else if ($dup_username_policy === 'skip')
					{
						$results[$source_id] = [
							'target_id' => null,
							'status'    => 'skipped',
							'error'     => "Skipped due to username collision on '{$username}'.",
						];
						continue;
					}
					else if ($dup_username_policy === 'merge' && (int)$collision_row['user_id'] !== 2 && (int)$collision_row['user_type'] !== 3)
					{
						// Merge into existing user
						$target_id = (int)$collision_row['user_id'];
						$this->id_mapper->set($run_id, $source_system, 'user', $source_id, $target_id, 'merged', '', ['merged_with' => $collision_row['username']]);
						$results[$source_id] = [
							'target_id' => $target_id,
							'status'    => 'success',
							'error'     => null,
							'note'      => 'merged',
						];
						continue;
					}
					else
					{
						// Default: Rename with deterministic suffix
						$was_renamed = true;
						$suffix_num = 1;
						$original_username = $username;

						while ($collision_row)
						{
							$suffix_num++;
							$username = $original_username . '_' . $suffix_num;
							$username_clean = function_exists('utf8_clean_string') ? utf8_clean_string($username) : mb_strtolower($username, 'UTF-8');

							$sql = 'SELECT user_id FROM ' . $this->table_prefix . 'users WHERE username_clean = ' . "'" . $this->db->sql_escape($username_clean) . "'";
							$res = $this->db->sql_query($sql);
							$collision_row = $this->db->sql_fetchrow($res);
							$this->db->sql_freeresult($res);
						}
					}
				}

				// 3. Resolve email address and collisions
				$email = $user->email;
				if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL))
				{
					$email = 'imported_user_' . $source_id . '@invalid.local';
				}
				else if ($dup_email_policy === 'replace_placeholder')
				{
					$sql = 'SELECT user_id FROM ' . $this->table_prefix . 'users WHERE user_email = ' . "'" . $this->db->sql_escape($email) . "'";
					$res = $this->db->sql_query($sql);
					$email_exists = $this->db->sql_fetchfield('user_id');
					$this->db->sql_freeresult($res);

					if ($email_exists)
					{
						$email = 'duplicate_' . $source_id . '_' . $user->email;
					}
				}

				// 4. Determine target user ID (preserve source ID if requested and available)
				$target_id = null;
				if ($preserve_ids && (int)$source_id > 1)
				{
					$sql = 'SELECT user_id FROM ' . $this->table_prefix . 'users WHERE user_id = ' . (int)$source_id;
					$res = $this->db->sql_query($sql);
					$id_taken = $this->db->sql_fetchfield('user_id');
					$this->db->sql_freeresult($res);

					if (!$id_taken)
					{
						$target_id = (int)$source_id;
					}
				}

				// 5. Build phpBB user database record
				$now = time();
				$regdate = $user->registered_date ?: $now;

				$user_row = [
					'user_type'             => (int)$user->user_type,
					'group_id'              => (int)($user->group_id ?: 2),
					'user_permissions'      => '',
					'user_perm_from'        => 0,
					'user_ip'               => $user->user_ip ?: '127.0.0.1',
					'user_regdate'          => $regdate,
					'username'              => $username,
					'username_clean'        => $username_clean,
					'user_password'         => $user->password_hash ?: '',
					'user_passchg'          => $regdate,
					'user_email'            => $email,
					'user_birthday'         => $user->birthday ?: '',
					'user_lastvisit'        => (int)$user->last_visit_date,
					'user_last_active'      => (int)$user->last_visit_date,
					'user_lastmark'         => $now,
					'user_lastpost_time'    => 0,
					'user_lastpage'         => '',
					'user_last_confirm_key' => '',
					'user_last_search'      => 0,
					'user_warnings'         => 0,
					'user_last_warning'     => 0,
					'user_login_attempts'   => 0,
					'user_inactive_reason'  => (int)$user->user_inactive_reason,
					'user_inactive_time'    => (int)$user->user_inactive_time,
					'user_posts'            => (int)$user->post_count,
					'user_lang'             => $user->language ?: 'en',
					'user_timezone'         => $user->timezone ?: 'UTC',
					'user_dateformat'       => 'd M Y, H:i',
					'user_style'            => 1,
					'user_rank'             => 0,
					'user_colour'           => '',
					'user_new_privmsg'      => 0,
					'user_unread_privmsg'   => 0,
					'user_last_privmsg'     => 0,
					'user_message_rules'    => 0,
					'user_full_folder'      => -3,
					'user_emailtime'        => 0,
					'user_topic_show_days'  => 0,
					'user_topic_sortby_type'=> 't',
					'user_topic_sortby_dir' => 'd',
					'user_post_show_days'   => 0,
					'user_post_sortby_type' => 't',
					'user_post_sortby_dir'  => 'a',
					'user_notify'           => 0,
					'user_notify_pm'        => 1,
					'user_notify_type'      => 0,
					'user_allow_pm'         => 1,
					'user_allow_viewonline' => (int)$user->visibility,
					'user_allow_viewemail'  => 1,
					'user_allow_massemail'  => 1,
					'user_options'          => 230271,
					'user_avatar'           => $user->avatar_path ?: '',
					'user_avatar_type'      => $user->avatar_type ?: '',
					'user_avatar_width'     => 0,
					'user_avatar_height'    => 0,
					'user_sig'                 => $user->signature ?: '',
					'user_sig_bbcode_uid'      => $user->sig_bbcode_uid ?: '',
					'user_sig_bbcode_bitfield' => $user->sig_bbcode_bitfield ?: '',
					'user_jabber'           => '',
					'user_actkey'           => '',
					'user_actkey_expiration'=> 0,
					'reset_token'           => '',
					'reset_token_expiration'=> 0,
					'user_newpasswd'        => '',
					'user_form_salt'        => substr(md5(uniqid(mt_rand(), true)), 0, 16),
					'user_new'              => 0,
					'user_reminded'         => 0,
					'user_reminded_time'    => 0,
				];

				if ($target_id !== null)
				{
					$user_row['user_id'] = $target_id;
				}

				$sql = 'INSERT INTO ' . $this->table_prefix . 'users ' . $this->db->sql_build_array('INSERT', $user_row);
				$this->db->sql_query($sql);

				$final_target_id = ($target_id !== null) ? $target_id : (int)$this->db->sql_nextid();

				// Update newest user config if non-bot registered user
				if ($final_target_id > 2 && (int)$user->user_type !== 2)
				{
					$this->update_newest_user_config($final_target_id, $username, (string)($user_row['user_colour'] ?? ''));
				}

				// 6. Insert default user group mapping into user_group
				$user_group_row = [
					'group_id'     => (int)($user->group_id ?: 2),
					'user_id'      => $final_target_id,
					'group_leader' => 0,
					'user_pending' => 0,
				];
				$sql = 'INSERT INTO ' . $this->table_prefix . 'user_group ' . $this->db->sql_build_array('INSERT', $user_group_row);
				$this->db->sql_query($sql);

				// 7. Record ID mapping atomically (Preserving ban metadata for authoritative Bans phase)
				$mapping_meta = [
					'ownership'              => 'created',
					'fingerprint'            => [
						'username_clean' => $username_clean,
						'user_email'     => $email,
						'user_regdate'   => $regdate,
					],
					'primary_group_source'   => $user->primary_group_source_id,
					'secondary_groups_source'=> $user->secondary_group_source_ids,
					'is_admin'               => $user->is_admin,
					'is_moderator'           => $user->is_moderator,
					'original_username'      => $user->username,
					'was_renamed'            => $was_renamed,
					'banned_state'           => $user->banned_state ? 1 : 0,
					'ban_info'               => $user->ban_info ?: null,
				];

				$this->id_mapper->set($run_id, $source_system, 'user', $source_id, $final_target_id, 'mapped', '', $mapping_meta);

				$results[$source_id] = [
					'target_id' => $final_target_id,
					'status'    => 'success',
					'error'     => null,
					'renamed'   => $was_renamed,
				];
			}
			catch (\Throwable $e)
			{
				$results[$source_id] = [
					'target_id' => null,
					'status'    => 'failed',
					'error'     => $e->getMessage(),
				];
			}
		}

		// Update active user count in phpBB config so memberlist and index reflect new users immediately
		try
		{
			$sql = 'SELECT COUNT(user_id) as total FROM ' . $this->table_prefix . 'users WHERE user_type <> 2 AND user_id > 1';
			$res = $this->db->sql_query($sql);
			$actual_users = (int)$this->db->sql_fetchfield('total');
			$this->db->sql_freeresult($res);
			if ($actual_users > 0)
			{
				$this->config->set('num_users', $actual_users);
			}
		}
		catch (\Throwable $e)
		{
			// Non-blocking
		}

		return $results;
	}

	/**
	 * Write a batch of groups
	 *
	 * @param group_dto[] $groups
	 * @param array $options
	 * @return array
	 */
	public function write_groups(array $groups, array $options = []): array
	{
		$results = [];
		$run_id = (string)($options['run_id'] ?? '');
		$source_system = (string)($options['source_system'] ?? 'xenforo');

		foreach ($groups as $group)
		{
			$source_id = $group->source_id;

			try
			{
				// 1. Idempotency check: verify if already mapped
				$existing_target_id = $this->id_mapper->get_target_id($source_system, 'group', $source_id);
				if ($existing_target_id !== null)
				{
					$sql = 'SELECT group_id FROM ' . $this->table_prefix . 'groups WHERE group_id = ' . (int)$existing_target_id;
					$res = $this->db->sql_query($sql);
					$still_exists = $this->db->sql_fetchfield('group_id');
					$this->db->sql_freeresult($res);

					if ($still_exists)
					{
						$is_builtin = ($group->is_builtin || !empty($group->canonical_name));
						$results[$source_id] = [
							'target_id' => (int)$existing_target_id,
							'status'    => 'success',
							'error'     => null,
							'builtin'   => $is_builtin,
							'reused'    => $is_builtin,
							'note'      => 'already_mapped',
						];
						continue;
					}
				}

				// 2. Handle built-in system groups
				if ($group->is_builtin || !empty($group->canonical_name))
				{
					$canonical = $group->canonical_name ?: $group->group_name;
					$sql = 'SELECT group_id FROM ' . $this->table_prefix . 'groups 
							WHERE group_name = ' . "'" . $this->db->sql_escape($canonical) . "' 
							AND group_type = 3";
					$res = $this->db->sql_query($sql);
					$target_id = (int)$this->db->sql_fetchfield('group_id');
					$this->db->sql_freeresult($res);

					if ($target_id > 0)
					{
						$this->id_mapper->set($run_id, $source_system, 'group', $source_id, $target_id, 'reused', '', [
							'builtin'        => true,
							'ownership'      => 'reused',
							'canonical_name' => $canonical,
						]);

						$results[$source_id] = [
							'target_id' => $target_id,
							'status'    => 'success',
							'error'     => null,
							'builtin'   => true,
							'reused'    => true,
						];
						continue;
					}
				}

				// 3. Custom Group Creation: check name collisions
				$group_name = $this->sanitize_utf8($group->group_name);
				$sql = 'SELECT group_id FROM ' . $this->table_prefix . 'groups WHERE group_name = ' . "'" . $this->db->sql_escape($group_name) . "'";
				$res = $this->db->sql_query($sql);
				$collision = $this->db->sql_fetchrow($res);
				$this->db->sql_freeresult($res);

				$was_renamed = false;
				if ($collision)
				{
					$was_renamed = true;
					$suffix = 1;
					$original_name = $group_name;
					while ($collision)
					{
						$suffix++;
						$group_name = $original_name . '_' . $suffix;
						$sql = 'SELECT group_id FROM ' . $this->table_prefix . 'groups WHERE group_name = ' . "'" . $this->db->sql_escape($group_name) . "'";
						$res = $this->db->sql_query($sql);
						$collision = $this->db->sql_fetchrow($res);
						$this->db->sql_freeresult($res);
					}
				}

				$group_row = [
					'group_name'          => $group_name,
					'group_desc'          => $group->group_desc ?: '',
					'group_desc_bitfield' => '',
					'group_desc_options'  => 7,
					'group_desc_uid'      => '',
					'group_type'          => (int)$group->group_type,
					'group_colour'        => $group->group_colour ?: '',
					'group_rank'          => 0,
					'group_receive_pm'    => 1,
					'group_legend'        => 0,
					'group_message_limit' => 0,
					'group_max_recipients'=> 0,
					'group_skip_auth'     => 0,
				];

				$sql = 'INSERT INTO ' . $this->table_prefix . 'groups ' . $this->db->sql_build_array('INSERT', $group_row);
				$this->db->sql_query($sql);
				$target_id = (int)$this->db->sql_nextid();

				$this->id_mapper->set($run_id, $source_system, 'group', $source_id, $target_id, 'created', '', [
					'builtin'     => false,
					'ownership'   => 'created',
					'was_renamed' => $was_renamed,
					'orig_name'   => $group->group_name,
					'fingerprint' => [
						'group_name' => $group_name,
						'created_at' => time(),
					],
				]);

				$results[$source_id] = [
					'target_id' => $target_id,
					'status'    => 'success',
					'error'     => null,
					'renamed'   => $was_renamed,
				];
			}
			catch (\Throwable $e)
			{
				$results[$source_id] = [
					'target_id' => null,
					'status'    => 'failed',
					'error'     => $e->getMessage(),
				];
			}
		}

		return $results;
	}

	/**
	 * Reconcile user group memberships
	 *
	 * @param array $memberships
	 * @param array $options
	 * @return array
	 */
	public function write_group_memberships(array $memberships, array $options = []): array
	{
		$results = [];
		$source_system = (string)($options['source_system'] ?? 'xenforo');

		// Resolve canonical admin and mod group IDs in target phpBB
		$sql = 'SELECT group_id, group_name FROM ' . $this->table_prefix . "groups WHERE group_name IN ('ADMINISTRATORS', 'GLOBAL_MODERATORS', 'REGISTERED', 'GUESTS')";
		$res = $this->db->sql_query($sql);
		$canonical_groups = [];
		while ($row = $this->db->sql_fetchrow($res))
		{
			$canonical_groups[$row['group_name']] = (int)$row['group_id'];
		}
		$this->db->sql_freeresult($res);

		$admin_gid = $canonical_groups['ADMINISTRATORS'] ?? 5;
		$mod_gid   = $canonical_groups['GLOBAL_MODERATORS'] ?? 4;
		$reg_gid   = $canonical_groups['REGISTERED'] ?? 2;

		foreach ($memberships as $item)
		{
			$user_source_id = $item['user_source_id'];

			try
			{
				$target_user_id = $this->id_mapper->get_target_id($source_system, 'user', $user_source_id);
				if (!$target_user_id)
				{
					$results[$user_source_id] = [
						'status' => 'skipped',
						'error'  => "Target user mapping not found for user ID {$user_source_id}",
					];
					continue;
				}

				$target_user_id = (int)$target_user_id;

				// PROTECTED: Never modify pre-existing phpBB Admin (user_id = 2) or Founders
				if ($target_user_id === 2)
				{
					$results[$user_source_id] = [
						'status' => 'skipped',
						'error'  => 'Pre-existing phpBB admin account is protected from migration membership changes.',
					];
					continue;
				}

				// Check existing user info
				$sql = 'SELECT user_id, user_type, group_id FROM ' . $this->table_prefix . 'users WHERE user_id = ' . $target_user_id;
				$res = $this->db->sql_query($sql);
				$target_user_row = $this->db->sql_fetchrow($res);
				$this->db->sql_freeresult($res);

				if (!$target_user_row)
				{
					$results[$user_source_id] = [
						'status' => 'failed',
						'error'  => "User record {$target_user_id} not found in database",
					];
					continue;
				}

				// Security check: NEVER grant USER_FOUNDER
				if ((int)$target_user_row['user_type'] === 3)
				{
					$results[$user_source_id] = [
						'status' => 'skipped',
						'error'  => 'Founder accounts are protected from automated membership changes.',
					];
					continue;
				}

				// Gather all source groups for this user
				$source_group_ids = [];
				if (!empty($item['primary_group_source_id']))
				{
					$source_group_ids[] = (int)$item['primary_group_source_id'];
				}
				if (!empty($item['secondary_group_source_ids']) && is_array($item['secondary_group_source_ids']))
				{
					foreach ($item['secondary_group_source_ids'] as $sgid)
					{
						$source_group_ids[] = (int)$sgid;
					}
				}
				$source_group_ids = array_unique(array_filter($source_group_ids));

				// Resolve target group IDs
				$target_group_ids = [];
				foreach ($source_group_ids as $sgid)
				{
					$tgid = $this->id_mapper->get_target_id($source_system, 'group', $sgid);
					if ($tgid !== null)
					{
						$tgid = (int)$tgid;

						// Security: only assign ADMINISTRATORS if user has verified admin status
						if ($tgid === $admin_gid && empty($item['is_admin']))
						{
							continue;
						}

						// Security: only assign GLOBAL_MODERATORS if user has verified moderator status
						if ($tgid === $mod_gid && empty($item['is_moderator']))
						{
							continue;
						}

						$target_group_ids[] = $tgid;
					}
				}

				// Always ensure REGISTERED group is present
				if (!in_array($reg_gid, $target_group_ids, true))
				{
					$target_group_ids[] = $reg_gid;
				}
				$target_group_ids = array_unique($target_group_ids);

				// Insert missing memberships into phpbb_user_group
				foreach ($target_group_ids as $tgid)
				{
					$sql = 'SELECT 1 FROM ' . $this->table_prefix . 'user_group WHERE user_id = ' . $target_user_id . ' AND group_id = ' . $tgid;
					$res = $this->db->sql_query($sql);
					$already_member = $this->db->sql_fetchfield('1');
					$this->db->sql_freeresult($res);

					if (!$already_member)
					{
						$ug_row = [
							'group_id'     => $tgid,
							'user_id'      => $target_user_id,
							'group_leader' => 0,
							'user_pending' => 0,
						];
						$sql = 'INSERT INTO ' . $this->table_prefix . 'user_group ' . $this->db->sql_build_array('INSERT', $ug_row);
						$this->db->sql_query($sql);
					}
				}

				// Update primary group_id in phpbb_users if source primary group mapped
				$primary_target_gid = null;
				if (!empty($item['primary_group_source_id']))
				{
					$primary_target_gid = $this->id_mapper->get_target_id($source_system, 'group', $item['primary_group_source_id']);
				}

				if ($primary_target_gid && in_array((int)$primary_target_gid, $target_group_ids, true))
				{
					$sql = 'UPDATE ' . $this->table_prefix . 'users SET group_id = ' . (int)$primary_target_gid . ' WHERE user_id = ' . $target_user_id;
					$this->db->sql_query($sql);
				}

				$results[$user_source_id] = [
					'status'       => 'success',
					'error'        => null,
					'groups_count' => count($target_group_ids),
				];
			}
			catch (\Throwable $e)
			{
				$results[$user_source_id] = [
					'status' => 'failed',
					'error'  => $e->getMessage(),
				];
			}
		}

		return $results;
	}

	/**
	 * Write global permissions for groups
	 *
	 * @param array $permissions
	 * @param array $options
	 * @return array
	 */
	public function write_global_permissions(array $permissions, array $options = []): array
	{
		$results = [];
		$source_system = (string)($options['source_system'] ?? 'xenforo');

		// Build map of phpBB auth_option name to auth_option_id
		$sql = 'SELECT auth_option_id, auth_option FROM ' . $this->table_prefix . 'acl_options WHERE is_global = 1';
		$res = $this->db->sql_query($sql);
		$acl_options_map = [];
		while ($row = $this->db->sql_fetchrow($res))
		{
			$acl_options_map[$row['auth_option']] = (int)$row['auth_option_id'];
		}
		$this->db->sql_freeresult($res);

		$affected_groups = [];

		foreach ($permissions as $idx => $perm)
		{
			$group_source_id = $perm['group_source_id'];
			$phpbb_opt_name  = $perm['phpbb_option'];
			$auth_setting    = (int)$perm['auth_setting'];

			try
			{
				$target_group_id = $this->id_mapper->get_target_id($source_system, 'group', $group_source_id);
				if (!$target_group_id)
				{
					$results[$idx] = [
						'status' => 'skipped',
						'error'  => "Target group not mapped for source group {$group_source_id}",
					];
					continue;
				}

				$target_group_id = (int)$target_group_id;

				if (!isset($acl_options_map[$phpbb_opt_name]))
				{
					$results[$idx] = [
						'status' => 'skipped',
						'error'  => "Global ACL option {$phpbb_opt_name} not found in phpBB",
					];
					continue;
				}

				$auth_option_id = $acl_options_map[$phpbb_opt_name];

				// Check existing acl_groups entry
				$sql = 'SELECT auth_setting FROM ' . $this->table_prefix . 'acl_groups
						WHERE group_id = ' . $target_group_id . '
							AND forum_id = 0
							AND auth_option_id = ' . $auth_option_id;
				$res = $this->db->sql_query($sql);
				$existing_setting = $this->db->sql_fetchfield('auth_setting');
				$this->db->sql_freeresult($res);

				if ($existing_setting !== false)
				{
					// Update if setting changed
					if ((int)$existing_setting !== $auth_setting)
					{
						$sql = 'UPDATE ' . $this->table_prefix . 'acl_groups
								SET auth_setting = ' . $auth_setting . '
								WHERE group_id = ' . $target_group_id . '
									AND forum_id = 0
									AND auth_option_id = ' . $auth_option_id;
						$this->db->sql_query($sql);
					}
				}
				else
				{
					// Insert new ACL entry
					$acl_row = [
						'group_id'       => $target_group_id,
						'forum_id'       => 0,
						'auth_option_id' => $auth_option_id,
						'auth_role_id'   => 0,
						'auth_setting'   => $auth_setting,
					];
					$sql = 'INSERT INTO ' . $this->table_prefix . 'acl_groups ' . $this->db->sql_build_array('INSERT', $acl_row);
					$this->db->sql_query($sql);
				}

				$affected_groups[$target_group_id] = true;

				$results[$idx] = [
					'status' => 'success',
					'error'  => null,
				];
			}
			catch (\Throwable $e)
			{
				$results[$idx] = [
					'status' => 'failed',
					'error'  => $e->getMessage(),
				];
			}
		}

		// Invalidate ACL cache and clear user_permissions on affected users so phpBB recalculates fresh permissions
		if (!empty($affected_groups))
		{
			$gids = implode(',', array_keys($affected_groups));
			$sql = 'UPDATE ' . $this->table_prefix . "users SET user_permissions = '' WHERE user_id IN (SELECT user_id FROM " . $this->table_prefix . "user_group WHERE group_id IN ({$gids}))";
			$this->db->sql_query($sql);

			if (is_object($this->cache) && method_exists($this->cache, 'destroy'))
			{
				$this->cache->destroy('acl_options');
				$this->cache->destroy('_acl_options');
			}
		}

		return $results;
	}

	/**
	 * Write a batch of forums/categories
	 *
	 * @param forum_dto[] $forums
	 * @param array $options
	 * @return array
	 */
	public function write_forums(array $forums, array $options = []): array
	{
		$results = [];
		$run_id = (string)($options['run_id'] ?? '');
		$source_system = (string)($options['source_system'] ?? 'xenforo');

		// Check highest existing right_id to preserve existing forums
		$sql = 'SELECT MAX(right_id) as max_right FROM ' . $this->table_prefix . 'forums';
		$res = $this->db->sql_query($sql);
		$max_existing_right = (int)$this->db->sql_fetchfield('max_right');
		$this->db->sql_freeresult($res);

		$offset = $max_existing_right > 0 ? $max_existing_right : 0;

		foreach ($forums as $forum)
		{
			$source_id = $forum->source_id;

			try
			{
				// 1. Check idempotency: verify if already mapped
				$existing_target_id = $this->id_mapper->get_target_id($source_system, 'forum', $source_id);
				if ($existing_target_id !== null)
				{
					$sql = 'SELECT forum_id FROM ' . $this->table_prefix . 'forums WHERE forum_id = ' . (int)$existing_target_id;
					$res = $this->db->sql_query($sql);
					$still_exists = $this->db->sql_fetchfield('forum_id');
					$this->db->sql_freeresult($res);

					if ($still_exists)
					{
						$results[$source_id] = [
							'target_id' => (int)$existing_target_id,
							'status'    => 'success',
							'error'     => null,
							'note'      => 'already_mapped',
						];
						continue;
					}
				}

				// 2. Resolve parent target ID
				$parent_target_id = 0;
				if (!empty($forum->parent_source_id))
				{
					$parent_target_id = (int)$this->id_mapper->get_target_id($source_system, 'forum', $forum->parent_source_id);
				}

				// 3. Collision handling: check if forum with same name exists under same parent
				$forum_name = $this->sanitize_utf8($forum->forum_name);
				$sql = 'SELECT forum_id FROM ' . $this->table_prefix . 'forums
						WHERE parent_id = ' . $parent_target_id . "
							AND forum_name = '" . $this->db->sql_escape($forum_name) . "'";
				$res = $this->db->sql_query($sql);
				$collision = $this->db->sql_fetchrow($res);
				$this->db->sql_freeresult($res);

				$was_renamed = false;
				if ($collision)
				{
					$was_renamed = true;
					$suffix = 1;
					$original_name = $forum_name;
					while ($collision)
					{
						$suffix++;
						$forum_name = $original_name . '_' . $suffix;
						$sql = 'SELECT forum_id FROM ' . $this->table_prefix . 'forums
								WHERE parent_id = ' . $parent_target_id . "
									AND forum_name = '" . $this->db->sql_escape($forum_name) . "'";
						$res = $this->db->sql_query($sql);
						$collision = $this->db->sql_fetchrow($res);
						$this->db->sql_freeresult($res);
					}
				}

				// Calculate offset nested set bounds
				$left_id = (int)$forum->left_id + $offset;
				$right_id = (int)$forum->right_id + $offset;

				$forum_row = [
					'parent_id'              => $parent_target_id,
					'left_id'                => $left_id,
					'right_id'               => $right_id,
					'forum_parents'          => '',
					'forum_name'             => $forum_name,
					'forum_desc'             => $this->sanitize_utf8($forum->forum_desc),
					'forum_desc_bitfield'    => '',
					'forum_desc_options'     => 7,
					'forum_desc_uid'         => '',
					'forum_link'             => (string)$forum->forum_link,
					'forum_password'         => '',
					'forum_style'            => 0,
					'forum_image'            => '',
					'forum_rules'            => '',
					'forum_rules_link'       => '',
					'forum_rules_bitfield'   => '',
					'forum_rules_options'    => 7,
					'forum_rules_uid'        => '',
					'forum_topics_per_page'  => 0,
					'forum_type'             => is_numeric($forum->forum_type) ? (int)$forum->forum_type : ($forum->forum_type === 'forum' ? 1 : ($forum->forum_type === 'link' ? 2 : 0)),
					'forum_status'           => (int)$forum->forum_status,
					'forum_posts_approved'   => (int)$forum->posts_count,
					'forum_posts_unapproved' => 0,
					'forum_posts_softdeleted'=> 0,
					'forum_topics_approved'  => (int)$forum->topics_count,
					'forum_topics_unapproved'=> 0,
					'forum_topics_softdeleted'=> 0,
					'forum_last_post_id'     => 0,
					'forum_last_poster_id'   => 0,
					'forum_last_post_subject'=> '',
					'forum_last_post_time'   => 0,
					'forum_last_poster_name' => '',
					'forum_last_poster_colour'=> '',
					'forum_flags'            => 48,
					'display_on_index'       => (int)$forum->display_on_index,
					'enable_indexing'        => 1,
					'enable_icons'           => 0,
					'enable_prune'           => 0,
					'prune_next'             => 0,
					'prune_days'             => 0,
					'prune_viewed'           => 0,
					'prune_freq'             => 0,
				];

				$sql = 'INSERT INTO ' . $this->table_prefix . 'forums ' . $this->db->sql_build_array('INSERT', $forum_row);
				$this->db->sql_query($sql);
				$target_id = (int)$this->db->sql_nextid();

				// Ensure newly created forum or category has canonical default permissions
				$this->assign_default_forum_permissions($target_id, (int)$forum_row['forum_type']);

				$this->id_mapper->set($run_id, $source_system, 'forum', $source_id, $target_id, 'mapped', '', [
					'node_type'   => $forum->node_type,
					'was_renamed' => $was_renamed,
				]);

				$results[$source_id] = [
					'target_id' => $target_id,
					'status'    => 'success',
					'error'     => null,
					'renamed'   => $was_renamed,
				];
			}
			catch (\Throwable $e)
			{
				$results[$source_id] = [
					'target_id' => null,
					'status'    => 'failed',
					'error'     => $e->getMessage(),
				];
			}
		}

		return $results;
	}

	/**
	 * Write forum-scoped (node) permissions
	 *
	 * @param array $permissions
	 * @param array $options
	 * @return array
	 */
	public function write_node_permissions(array $permissions, array $options = []): array
	{
		$results = [];
		$source_system = (string)($options['source_system'] ?? 'xenforo');

		// Build map of local phpBB auth_option name to auth_option_id
		$sql = 'SELECT auth_option_id, auth_option FROM ' . $this->table_prefix . 'acl_options WHERE is_local = 1';
		$res = $this->db->sql_query($sql);
		$local_options_map = [];
		while ($row = $this->db->sql_fetchrow($res))
		{
			$local_options_map[$row['auth_option']] = (int)$row['auth_option_id'];
		}
		$this->db->sql_freeresult($res);

		$affected_forums = [];

		foreach ($permissions as $idx => $perm)
		{
			$source_node_id  = $perm['source_node_id'];
			$phpbb_opt_name  = (string)$perm['phpbb_option'];
			$auth_setting    = (int)$perm['auth_setting'];

			try
			{
				// SECURITY RULE: Never allow 'a_' or non-local permissions at node scope!
				if (strpos($phpbb_opt_name, 'a_') === 0 || (!isset($local_options_map[$phpbb_opt_name]) && strpos($phpbb_opt_name, 'm_') !== 0 && strpos($phpbb_opt_name, 'f_') !== 0))
				{
					$results[$idx] = [
						'status' => 'skipped',
						'error'  => "Security restriction: Option '{$phpbb_opt_name}' is not allowed at node scope.",
					];
					continue;
				}

				$target_forum_id = $this->id_mapper->get_target_id($source_system, 'forum', $source_node_id);
				if (!$target_forum_id)
				{
					$results[$idx] = [
						'status' => 'skipped',
						'error'  => "Target forum mapping not found for source node ID {$source_node_id}",
					];
					continue;
				}

				$target_forum_id = (int)$target_forum_id;

				if (!isset($local_options_map[$phpbb_opt_name]))
				{
					$results[$idx] = [
						'status' => 'skipped',
						'error'  => "Local ACL option '{$phpbb_opt_name}' not found in phpBB",
					];
					continue;
				}

				$auth_option_id = $local_options_map[$phpbb_opt_name];

				// Handle group permission
				if (!empty($perm['source_group_id']))
				{
					$target_group_id = $this->id_mapper->get_target_id($source_system, 'group', $perm['source_group_id']);
					if (!$target_group_id && in_array((int)$perm['source_group_id'], [1, 2, 3, 4], true))
					{
						$canonical_map = [1 => 'GUESTS', 2 => 'REGISTERED', 3 => 'ADMINISTRATORS', 4 => 'GLOBAL_MODERATORS'];
						$c_name = $canonical_map[(int)$perm['source_group_id']];
						$sql_g = 'SELECT group_id FROM ' . $this->table_prefix . "groups WHERE group_name = '{$c_name}'";
						$res_g = $this->db->sql_query($sql_g);
						$target_group_id = (int)$this->db->sql_fetchfield('group_id');
						$this->db->sql_freeresult($res_g);
					}

					if ($target_group_id)
					{
						$target_group_id = (int)$target_group_id;

						$sql = 'SELECT auth_setting FROM ' . $this->table_prefix . 'acl_groups
								WHERE group_id = ' . $target_group_id . '
									AND forum_id = ' . $target_forum_id . '
									AND auth_option_id = ' . $auth_option_id;
						$res = $this->db->sql_query($sql);
						$existing_setting = $this->db->sql_fetchfield('auth_setting');
						$this->db->sql_freeresult($res);

						if ($existing_setting !== false)
						{
							if ((int)$existing_setting !== $auth_setting)
							{
								$sql = 'UPDATE ' . $this->table_prefix . 'acl_groups
										SET auth_setting = ' . $auth_setting . '
										WHERE group_id = ' . $target_group_id . '
											AND forum_id = ' . $target_forum_id . '
											AND auth_option_id = ' . $auth_option_id;
								$this->db->sql_query($sql);
							}
						}
						else
						{
							$acl_row = [
								'group_id'       => $target_group_id,
								'forum_id'       => $target_forum_id,
								'auth_option_id' => $auth_option_id,
								'auth_role_id'   => 0,
								'auth_setting'   => $auth_setting,
							];
							$sql = 'INSERT INTO ' . $this->table_prefix . 'acl_groups ' . $this->db->sql_build_array('INSERT', $acl_row);
							$this->db->sql_query($sql);
						}
					}
				}
				// Handle user permission
				else if (!empty($perm['source_user_id']))
				{
					$target_user_id = $this->id_mapper->get_target_id($source_system, 'user', $perm['source_user_id']);
					if ($target_user_id && (int)$target_user_id !== 2) // protect admin
					{
						$target_user_id = (int)$target_user_id;

						$sql = 'SELECT auth_setting FROM ' . $this->table_prefix . 'acl_users
								WHERE user_id = ' . $target_user_id . '
									AND forum_id = ' . $target_forum_id . '
									AND auth_option_id = ' . $auth_option_id;
						$res = $this->db->sql_query($sql);
						$existing_setting = $this->db->sql_fetchfield('auth_setting');
						$this->db->sql_freeresult($res);

						if ($existing_setting !== false)
						{
							if ((int)$existing_setting !== $auth_setting)
							{
								$sql = 'UPDATE ' . $this->table_prefix . 'acl_users
										SET auth_setting = ' . $auth_setting . '
										WHERE user_id = ' . $target_user_id . '
											AND forum_id = ' . $target_forum_id . '
											AND auth_option_id = ' . $auth_option_id;
								$this->db->sql_query($sql);
							}
						}
						else
						{
							$acl_row = [
								'user_id'        => $target_user_id,
								'forum_id'       => $target_forum_id,
								'auth_option_id' => $auth_option_id,
								'auth_role_id'   => 0,
								'auth_setting'   => $auth_setting,
							];
							$sql = 'INSERT INTO ' . $this->table_prefix . 'acl_users ' . $this->db->sql_build_array('INSERT', $acl_row);
							$this->db->sql_query($sql);
						}
					}
				}

				$affected_forums[$target_forum_id] = true;

				$results[$idx] = [
					'status' => 'success',
					'error'  => null,
				];
			}
			catch (\Throwable $e)
			{
				$results[$idx] = [
					'status' => 'failed',
					'error'  => $e->getMessage(),
				];
			}
		}

		if (!empty($affected_forums))
		{
			$this->db->sql_query('UPDATE ' . $this->table_prefix . "users SET user_permissions = ''");
			if (is_object($this->cache) && method_exists($this->cache, 'destroy'))
			{
				$this->cache->destroy('acl_options');
				$this->cache->destroy('_acl_options');
			}
		}

		return $results;
	}

	/**
	 * Write a batch of topics
	 *
	 * @param topic_dto[] $topics
	 * @param array $options
	 * @return array
	 */
	public function write_topics(array $topics, array $options = []): array
	{
		$results = [];
		$run_id = (string)($options['run_id'] ?? '');
		$source_system = (string)($options['source_system'] ?? 'xenforo');
		$missing_forum_policy = (string)($options['missing_forum_policy'] ?? 'skip');
		$fallback_forum_id = (int)($options['fallback_forum_id'] ?? 2);

		foreach ($topics as $topic)
		{
			$source_id = $topic->source_id;

			try
			{
				// 1. Check idempotency: already mapped
				$existing_target_id = $this->id_mapper->get_target_id($source_system, 'topic', $source_id);
				if ($existing_target_id !== null)
				{
					$sql = 'SELECT topic_id FROM ' . $this->table_prefix . 'topics WHERE topic_id = ' . (int)$existing_target_id;
					$res = $this->db->sql_query($sql);
					$still_exists = $this->db->sql_fetchfield('topic_id');
					$this->db->sql_freeresult($res);

					if ($still_exists)
					{
						$results[$source_id] = [
							'target_id' => (int)$existing_target_id,
							'status'    => 'success',
							'error'     => null,
							'note'      => 'already_mapped',
						];
						continue;
					}
				}

				// 2. Resolve Target Forum ID
				$forum_target_id = $this->id_mapper->get_target_id($source_system, 'forum', $topic->forum_source_id);
				if (!$forum_target_id)
				{
					if ($missing_forum_policy === 'fallback' && $fallback_forum_id > 0)
					{
						$forum_target_id = $fallback_forum_id;
					}
					else
					{
						$results[$source_id] = [
							'target_id' => null,
							'status'    => 'skipped',
							'error'     => "Missing target forum mapping for source node ID {$topic->forum_source_id}",
						];
						continue;
					}
				}

				$forum_target_id = (int)$forum_target_id;

				// 3. Resolve Author Target ID
				$user_target_id = 1; // Anonymous default
				if (!empty($topic->user_source_id))
				{
					$mapped_user_id = $this->id_mapper->get_target_id($source_system, 'user', $topic->user_source_id);
					if ($mapped_user_id)
					{
						$user_target_id = (int)$mapped_user_id;
					}
				}

				// 4. Resolve Last Poster Target ID
				$last_poster_target_id = 1;
				if (!empty($topic->last_post_source_user_id))
				{
					$mapped_last_user_id = $this->id_mapper->get_target_id($source_system, 'user', $topic->last_post_source_user_id);
					if ($mapped_last_user_id)
					{
						$last_poster_target_id = (int)$mapped_last_user_id;
					}
				}

				// 5. Resolve Deleting User Target ID if soft-deleted
				$delete_user_target_id = 0;
				if (!empty($topic->delete_user_source_id))
				{
					$mapped_del_user_id = $this->id_mapper->get_target_id($source_system, 'user', $topic->delete_user_source_id);
					if ($mapped_del_user_id)
					{
						$delete_user_target_id = (int)$mapped_del_user_id;
					}
				}

				$clean_title = $this->sanitize_utf8($topic->topic_title);
				$author_name = $this->sanitize_utf8($topic->source_username ?: 'Guest');
				$last_author_name = $this->sanitize_utf8($topic->last_post_username ?: $author_name);

				$topic_row = [
					'forum_id'                  => $forum_target_id,
					'icon_id'                   => 0,
					'topic_attachment'          => 0,
					'topic_reported'            => 0,
					'topic_title'               => $clean_title,
					'topic_poster'              => $user_target_id,
					'topic_time'                => (int)($topic->topic_time ?: time()),
					'topic_time_limit'          => 0,
					'topic_views'               => (int)$topic->topic_views,
					'topic_status'              => (int)$topic->topic_status,
					'topic_type'                => (int)$topic->topic_type,
					'topic_first_post_id'       => 0, // Provisional, updated in Phase 4D
					'topic_first_poster_name'   => $author_name,
					'topic_first_poster_colour' => '',
					'topic_last_post_id'        => 0, // Provisional, updated in Phase 4D
					'topic_last_poster_id'      => $last_poster_target_id,
					'topic_last_poster_name'    => $last_author_name,
					'topic_last_poster_colour'  => '',
					'topic_last_post_subject'   => $clean_title,
					'topic_last_post_time'      => (int)($topic->last_post_time ?: $topic->topic_time ?: time()),
					'topic_last_view_time'      => (int)($topic->last_post_time ?: $topic->topic_time ?: time()),
					'topic_moved_id'            => 0,
					'topic_bumped'              => 0,
					'topic_bumper'              => 0,
					'poll_title'                => '',
					'poll_start'                => 0,
					'poll_length'               => 0,
					'poll_max_options'          => 1,
					'poll_last_vote'            => 0,
					'poll_vote_change'          => 0,
					'topic_visibility'          => (int)$topic->topic_visibility,
					'topic_delete_time'         => (int)$topic->delete_time,
					'topic_delete_reason'       => $this->sanitize_utf8($topic->delete_reason),
					'topic_delete_user'         => $delete_user_target_id,
					'topic_posts_approved'      => 0, // Provisional, calculated when posts are written
					'topic_posts_unapproved'    => 0,
					'topic_posts_softdeleted'   => 0,
				];

				$sql = 'INSERT INTO ' . $this->table_prefix . 'topics ' . $this->db->sql_build_array('INSERT', $topic_row);
				$this->db->sql_query($sql);
				$target_id = (int)$this->db->sql_nextid();

				$this->id_mapper->set($run_id, $source_system, 'topic', $source_id, $target_id, 'mapped', '', [
					'first_post_id'   => $topic->first_post_source_id,
					'last_post_id'    => $topic->last_post_source_id,
					'discussion_type' => $topic->discussion_type,
					'original_title'  => $topic->original_title,
					'prefix_id'       => $topic->prefix_id,
				]);

				$results[$source_id] = [
					'target_id' => $target_id,
					'status'    => 'success',
					'error'     => null,
				];
			}
			catch (\Throwable $e)
			{
				$results[$source_id] = [
					'target_id' => null,
					'status'    => 'failed',
					'error'     => $e->getMessage(),
				];
			}
		}

		return $results;
	}

	/**
	 * Write a batch of posts
	 *
	 * @param post_dto[] $posts
	 * @param array $options
	 * @return array
	 */
	public function write_posts(array $posts, array $options = []): array
	{
		$results = [];
		$run_id = (string)($options['run_id'] ?? '');
		$source_system = (string)($options['source_system'] ?? 'xenforo');

		// Cache topic forum IDs and titles to avoid duplicate queries
		$topic_info_cache = [];

		foreach ($posts as $post)
		{
			$source_id = $post->source_id;

			try
			{
				// 1. Idempotency Check
				$existing_target_id = $this->id_mapper->get_target_id($source_system, 'post', $source_id);
				if ($existing_target_id !== null)
				{
					$sql = 'SELECT post_id FROM ' . $this->table_prefix . 'posts WHERE post_id = ' . (int)$existing_target_id;
					$res = $this->db->sql_query($sql);
					$still_exists = $this->db->sql_fetchfield('post_id');
					$this->db->sql_freeresult($res);

					if ($still_exists)
					{
						$results[$source_id] = [
							'target_id' => (int)$existing_target_id,
							'status'    => 'success',
							'error'     => null,
							'note'      => 'already_mapped',
						];
						continue;
					}
				}

				// 2. Resolve Target Topic ID
				$topic_target_id = $this->id_mapper->get_target_id($source_system, 'topic', $post->topic_source_id);
				if (!$topic_target_id)
				{
					$results[$source_id] = [
						'target_id' => null,
						'status'    => 'skipped',
						'error'     => "Target topic mapping not found for source thread ID {$post->topic_source_id}",
					];
					continue;
				}

				$topic_target_id = (int)$topic_target_id;

				// 3. Resolve Target Forum ID and Topic Title from target topic
				if (!isset($topic_info_cache[$topic_target_id]))
				{
					$sql = 'SELECT forum_id, topic_title FROM ' . $this->table_prefix . 'topics WHERE topic_id = ' . $topic_target_id;
					$res = $this->db->sql_query($sql);
					$t_row = $this->db->sql_fetchrow($res);
					$this->db->sql_freeresult($res);

					if (!$t_row)
					{
						$results[$source_id] = [
							'target_id' => null,
							'status'    => 'skipped',
							'error'     => "Target topic ID {$topic_target_id} does not exist in target database",
						];
						continue;
					}

					$topic_info_cache[$topic_target_id] = $t_row;
				}

				$forum_target_id = (int)$topic_info_cache[$topic_target_id]['forum_id'];
				$topic_title = (string)$topic_info_cache[$topic_target_id]['topic_title'];

				// 4. Resolve Author Target ID
				$user_target_id = 1; // Anonymous default
				if (!empty($post->user_source_id))
				{
					$mapped_user = $this->id_mapper->get_target_id($source_system, 'user', $post->user_source_id);
					if ($mapped_user)
					{
						$user_target_id = (int)$mapped_user;
					}
				}

				// 5. Resolve Last Editor Target ID
				$edit_user_target_id = 0;
				if (!empty($post->post_edit_source_user_id))
				{
					$mapped_editor = $this->id_mapper->get_target_id($source_system, 'user', $post->post_edit_source_user_id);
					if ($mapped_editor)
					{
						$edit_user_target_id = (int)$mapped_editor;
					}
				}

				// 6. Resolve Deleting User Target ID
				$del_user_target_id = 0;
				if (!empty($post->delete_user_source_id))
				{
					$mapped_del = $this->id_mapper->get_target_id($source_system, 'user', $post->delete_user_source_id);
					if ($mapped_del)
					{
						$del_user_target_id = (int)$mapped_del;
					}
				}

				// 7. Subject Construction
				$post_subject = $post->post_subject;
				if ($post_subject === '')
				{
					$post_subject = ($post->position === 0) ? $topic_title : ('Re: ' . $topic_title);
				}

				$clean_subject = $this->sanitize_utf8($post_subject);
				$clean_username = $this->sanitize_utf8($post->username ?: 'Guest');
				$raw_txt = $post->post_text !== '' ? $post->post_text : (isset($post->message) ? $post->message : ($post->normalized_message ?: $post->raw_source_message));
				$clean_text = $this->sanitize_utf8($raw_txt);

				if (!function_exists('generate_text_for_storage'))
				{
					global $phpbb_root_path, $phpEx;
					if (!empty($phpbb_root_path) && file_exists($phpbb_root_path . 'includes/functions_posting.' . ($phpEx ?: 'php')))
					{
						require_once $phpbb_root_path . 'includes/functions_posting.' . ($phpEx ?: 'php');
					}
				}

				$bbcode_uid = (string)$post->bbcode_uid;
				$bbcode_bitfield = (string)$post->bbcode_bitfield;

				if (function_exists('generate_text_for_storage') && strpos($clean_text, '<r>') !== 0 && strpos($clean_text, '<t>') !== 0 && strpos($clean_text, '<m>') !== 0)
				{
					$storage_uid = '';
					$storage_bitfield = '';
					$flags = 0;
					try
					{
						generate_text_for_storage($clean_text, $storage_uid, $storage_bitfield, $flags, true, true, true);
						$bbcode_uid = $storage_uid;
						$bbcode_bitfield = $storage_bitfield;
					}
					catch (\Throwable $e)
					{
					}
				}

				$post_row = [
					'topic_id'            => $topic_target_id,
					'forum_id'            => $forum_target_id,
					'poster_id'           => $user_target_id,
					'icon_id'             => 0,
					'poster_ip'           => $post->poster_ip ?: '127.0.0.1',
					'post_time'           => (int)($post->post_time ?: time()),
					'post_reported'       => 0,
					'enable_bbcode'       => 1,
					'enable_smilies'      => 1,
					'enable_magic_url'    => 1,
					'enable_sig'          => 1,
					'post_username'       => $clean_username,
					'post_subject'        => $clean_subject,
					'post_text'           => $clean_text,
					'post_checksum'       => md5($clean_text),
					'post_attachment'     => 0,
					'bbcode_bitfield'     => $bbcode_bitfield,
					'bbcode_uid'          => $bbcode_uid,
					'post_postcount'      => 1,
					'post_edit_time'      => (int)$post->post_edit_time,
					'post_edit_reason'    => $this->sanitize_utf8($post->post_edit_reason),
					'post_edit_user'      => $edit_user_target_id,
					'post_edit_count'     => (int)$post->post_edit_count,
					'post_edit_locked'    => 0,
					'post_visibility'     => (int)$post->post_visibility,
					'post_delete_time'    => (int)$post->delete_time,
					'post_delete_reason'  => $this->sanitize_utf8($post->delete_reason),
					'post_delete_user'    => $del_user_target_id,
				];

				$sql = 'INSERT INTO ' . $this->table_prefix . 'posts ' . $this->db->sql_build_array('INSERT', $post_row);
				$this->db->sql_query($sql);
				$target_id = (int)$this->db->sql_nextid();

				$this->id_mapper->set($run_id, $source_system, 'post', $source_id, $target_id, 'mapped', '', [
					'topic_id'    => $topic_target_id,
					'attachments' => $post->attachment_source_ids,
				]);

				$results[$source_id] = [
					'target_id' => $target_id,
					'status'    => 'success',
					'error'     => null,
				];
			}
			catch (\Throwable $e)
			{
				$results[$source_id] = [
					'target_id' => null,
					'status'    => 'failed',
					'error'     => $e->getMessage(),
				];
			}
		}

		return $results;
	}

	/**
	 * Finalize topic post pointers and reply counts based on real imported posts
	 *
	 * @param array $target_topic_ids
	 * @return array
	 */
	public function finalize_topics(array $target_topic_ids): array
	{
		$finalized = [];

		foreach ($target_topic_ids as $topic_id)
		{
			$topic_id = (int)$topic_id;
			if ($topic_id <= 0)
			{
				continue;
			}

			$sql = 'SELECT post_id, poster_id, post_username, post_subject, post_time, post_visibility 
					FROM ' . $this->table_prefix . 'posts 
					WHERE topic_id = ' . $topic_id . ' 
					ORDER BY post_time ASC, post_id ASC';
			$res = $this->db->sql_query($sql);
			$posts = [];
			while ($row = $this->db->sql_fetchrow($res))
			{
				$posts[] = $row;
			}
			$this->db->sql_freeresult($res);

			if (empty($posts))
			{
				continue;
			}

			$first_post = $posts[0];
			$last_post = $posts[count($posts) - 1];

			$approved_count = 0;
			$unapproved_count = 0;
			$deleted_count = 0;

			foreach ($posts as $p)
			{
				$vis = (int)$p['post_visibility'];
				if ($vis === 1)
				{
					$approved_count++;
				}
				else if ($vis === 0)
				{
					$unapproved_count++;
				}
				else if ($vis === 2)
				{
					$deleted_count++;
				}
			}

			$update_data = [
				'topic_first_post_id'       => (int)$first_post['post_id'],
				'topic_first_poster_name'   => (string)$first_post['post_username'],
				'topic_last_post_id'        => (int)$last_post['post_id'],
				'topic_last_poster_id'      => (int)$last_post['poster_id'],
				'topic_last_poster_name'    => (string)$last_post['post_username'],
				'topic_last_post_subject'   => (string)$last_post['post_subject'],
				'topic_last_post_time'      => (int)$last_post['post_time'],
				'topic_posts_approved'      => max(0, $approved_count - 1),
				'topic_posts_unapproved'    => $unapproved_count,
				'topic_posts_softdeleted'   => $deleted_count,
			];

			$sql = 'UPDATE ' . $this->table_prefix . 'topics 
					SET ' . $this->db->sql_build_array('UPDATE', $update_data) . ' 
					WHERE topic_id = ' . $topic_id;
			$this->db->sql_query($sql);

			$finalized[$topic_id] = $update_data;
		}

		return $finalized;
	}

	/**
	 * Synchronize forum post/topic counters and latest post pointers based on real content
	 *
	 * @param array $target_forum_ids
	 * @return array
	 */
	public function synchronize_forums(array $target_forum_ids): array
	{
		$synchronized = [];

		foreach ($target_forum_ids as $forum_id)
		{
			$forum_id = (int)$forum_id;
			if ($forum_id <= 0)
			{
				continue;
			}

			// 1. Topic counts by visibility
			$sql = 'SELECT 
						COUNT(CASE WHEN topic_visibility = 1 THEN 1 END) as topics_approved,
						COUNT(CASE WHEN topic_visibility = 0 THEN 1 END) as topics_unapproved,
						COUNT(CASE WHEN topic_visibility = 2 THEN 1 END) as topics_softdeleted
					FROM ' . $this->table_prefix . 'topics 
					WHERE forum_id = ' . $forum_id;
			$res = $this->db->sql_query($sql);
			$topic_counts = $this->db->sql_fetchrow($res);
			$this->db->sql_freeresult($res);

			// 2. Post counts by visibility
			$sql = 'SELECT 
						COUNT(CASE WHEN post_visibility = 1 THEN 1 END) as posts_approved,
						COUNT(CASE WHEN post_visibility = 0 THEN 1 END) as posts_unapproved,
						COUNT(CASE WHEN post_visibility = 2 THEN 1 END) as posts_softdeleted
					FROM ' . $this->table_prefix . 'posts 
					WHERE forum_id = ' . $forum_id;
			$res = $this->db->sql_query($sql);
			$post_counts = $this->db->sql_fetchrow($res);
			$this->db->sql_freeresult($res);

			// 3. Latest post pointer in forum
			$sql = 'SELECT post_id, poster_id, post_username, post_subject, post_time 
					FROM ' . $this->table_prefix . 'posts 
					WHERE forum_id = ' . $forum_id . ' AND post_visibility = 1 
					ORDER BY post_time DESC, post_id DESC';
			$res = $this->db->sql_query_limit($sql, 1);
			$last_post = $this->db->sql_fetchrow($res);
			$this->db->sql_freeresult($res);

			$update_data = [
				'forum_topics_approved'     => (int)($topic_counts['topics_approved'] ?? 0),
				'forum_topics_unapproved'   => (int)($topic_counts['topics_unapproved'] ?? 0),
				'forum_topics_softdeleted'  => (int)($topic_counts['topics_softdeleted'] ?? 0),
				'forum_posts_approved'      => (int)($post_counts['posts_approved'] ?? 0),
				'forum_posts_unapproved'    => (int)($post_counts['posts_unapproved'] ?? 0),
				'forum_posts_softdeleted'   => (int)($post_counts['posts_softdeleted'] ?? 0),
				'forum_last_post_id'        => (int)($last_post['post_id'] ?? 0),
				'forum_last_poster_id'      => (int)($last_post['poster_id'] ?? 0),
				'forum_last_poster_name'    => (string)($last_post['post_username'] ?? ''),
				'forum_last_post_subject'   => (string)($last_post['post_subject'] ?? ''),
				'forum_last_post_time'      => (int)($last_post['post_time'] ?? 0),
			];

			$sql = 'UPDATE ' . $this->table_prefix . 'forums 
					SET ' . $this->db->sql_build_array('UPDATE', $update_data) . ' 
					WHERE forum_id = ' . $forum_id;
			$this->db->sql_query($sql);

			$synchronized[$forum_id] = $update_data;
		}

		return $synchronized;
	}

	/**
	 * Write a batch of attachments
	 *
	 * @param attachment_dto[] $attachments
	 * @param array $options
	 * @return array
	 */
	public function write_attachments(array $attachments, array $options = []): array
	{
		$results = [];
		$run_id = (string)($options['run_id'] ?? '');
		$source_system = (string)($options['source_system'] ?? 'xenforo');
		$missing_file_policy = (string)($options['missing_file_policy'] ?? 'skip');
		$attachment_policy = (string)($options['attachment_policy'] ?? 'respect_target_policy');

		// phpBB files directory
		global $phpbb_root_path;
		$phpbb_root = !empty($phpbb_root_path) ? $phpbb_root_path : (defined('PHPBB_ROOT_PATH') ? PHPBB_ROOT_PATH : (dirname(__DIR__, 5) . '/'));
		$files_dir = rtrim($phpbb_root, '/\\') . '/files';
		if (!is_dir($files_dir))
		{
			@mkdir($files_dir, 0777, true);
		}

		$affected_posts = [];
		$affected_topics = [];
		$affected_pms = [];

		$disallowed_extensions = ['php', 'php3', 'php4', 'php5', 'phtml', 'phar', 'exe', 'sh', 'bat', 'cmd', 'cgi', 'pl'];

		foreach ($attachments as $att)
		{
			$source_id = $att->source_id;
			$is_pm = ($att->content_type === 'conversation_message' || $att->content_type === 'pm');
			$map_type = $is_pm ? 'pm_attachment' : 'attachment';

			try
			{
				// 1. Check Idempotency
				$existing_target_id = $this->id_mapper->get_target_id($source_system, $map_type, $source_id);
				if ($existing_target_id !== null)
				{
					$sql = 'SELECT attach_id FROM ' . $this->table_prefix . 'attachments WHERE attach_id = ' . (int)$existing_target_id;
					$res = $this->db->sql_query($sql);
					$still_exists = $this->db->sql_fetchfield('attach_id');
					$this->db->sql_freeresult($res);

					if ($still_exists)
					{
						$results[$source_id] = [
							'target_id' => (int)$existing_target_id,
							'status'    => 'success',
							'error'     => null,
							'note'      => 'already_mapped',
						];
						continue;
					}
				}

				// 2. Validate Content Type
				if (!$is_pm && $att->content_type !== 'post')
				{
					$results[$source_id] = [
						'target_id' => null,
						'status'    => 'skipped',
						'error'     => "Unsupported content type: {$att->content_type}",
					];
					continue;
				}

				// 3. Extension & Policy Validation
				$clean_filename = $this->sanitize_utf8($att->real_filename ?: "attachment_{$source_id}");
				$ext = strtolower(pathinfo($clean_filename, PATHINFO_EXTENSION));

				if (in_array($ext, $disallowed_extensions, true))
				{
					if ($attachment_policy === 'stop_on_disallowed')
					{
						throw new \RuntimeException("Disallowed executable attachment extension: .{$ext}");
					}

					$results[$source_id] = [
						'target_id' => null,
						'status'    => 'skipped',
						'error'     => "Disallowed executable attachment extension: .{$ext}",
					];
					continue;
				}

				// 4. Resolve Target Container & Poster
				$target_post_msg_id = 0;
				$target_topic_id = 0;
				$in_message = $is_pm ? 1 : 0;
				$user_target_id = 0;

				if ($is_pm)
				{
					$pm_target_id = $this->id_mapper->get_target_id($source_system, 'privmsg', $att->post_source_id);
					if (!$pm_target_id)
					{
						$results[$source_id] = [
							'target_id' => null,
							'status'    => 'skipped',
							'error'     => "Target private message mapping not found for source message ID {$att->post_source_id}",
						];
						continue;
					}

					$pm_target_id = (int)$pm_target_id;
					$sql = 'SELECT msg_id, author_id FROM ' . $this->table_prefix . 'privmsgs WHERE msg_id = ' . $pm_target_id;
					$res = $this->db->sql_query($sql);
					$pm_row = $this->db->sql_fetchrow($res);
					$this->db->sql_freeresult($res);

					if (!$pm_row)
					{
						$results[$source_id] = [
							'target_id' => null,
							'status'    => 'skipped',
							'error'     => "Target PM ID {$pm_target_id} not found in database",
						];
						continue;
					}

					$target_post_msg_id = $pm_target_id;
					$target_topic_id = 0;
					$user_target_id = (int)$pm_row['author_id'];
				}
				else
				{
					$post_target_id = $this->id_mapper->get_target_id($source_system, 'post', $att->post_source_id);
					if (!$post_target_id)
					{
						$results[$source_id] = [
							'target_id' => null,
							'status'    => 'skipped',
							'error'     => "Target post mapping not found for source post ID {$att->post_source_id}",
						];
						continue;
					}

					$post_target_id = (int)$post_target_id;
					$sql = 'SELECT topic_id, poster_id FROM ' . $this->table_prefix . 'posts WHERE post_id = ' . $post_target_id;
					$res = $this->db->sql_query($sql);
					$post_row = $this->db->sql_fetchrow($res);
					$this->db->sql_freeresult($res);

					if (!$post_row)
					{
						$results[$source_id] = [
							'target_id' => null,
							'status'    => 'skipped',
							'error'     => "Target post ID {$post_target_id} not found in database",
						];
						continue;
					}

					$target_post_msg_id = $post_target_id;
					$target_topic_id = (int)$post_row['topic_id'];
					$user_target_id = (int)$post_row['poster_id'];
				}

				if (!empty($att->user_source_id))
				{
					$mapped_user = $this->id_mapper->get_target_id($source_system, 'user', $att->user_source_id);
					if ($mapped_user)
					{
						$user_target_id = (int)$mapped_user;
					}
				}

				$src_file = $att->source_physical_path;
				if (empty($src_file) || !file_exists($src_file) || !is_readable($src_file))
				{
					if ($missing_file_policy === 'skip')
					{
						$results[$source_id] = [
							'target_id' => null,
							'status'    => 'skipped',
							'error'     => "Physical source attachment file not found for data ID {$att->data_id}",
						];
						continue;
					}
					else
					{
						throw new \RuntimeException("Missing physical attachment file: {$src_file}");
					}
				}

				$src_sha256 = hash_file('sha256', $src_file);

				// 6. Target Filename Planning & Persisted Metadata
				$existing_meta = $this->id_mapper->get_metadata($source_system, $map_type, $source_id);
				$physical_filename = '';

				if (!empty($existing_meta['physical_filename']))
				{
					$physical_filename = (string)$existing_meta['physical_filename'];
				}
				else
				{
					// Cryptographically random unique target physical filename
					$rand_token = bin2hex(random_bytes(16));
					$physical_filename = "{$user_target_id}_{$rand_token}";
				}

				$dest_file = $files_dir . '/' . $physical_filename;
				$need_copy = true;

				// Verify existing file by cryptographic hash (SHA-256)
				if (file_exists($dest_file))
				{
					$dest_size = (int)filesize($dest_file);
					if ($dest_size === (int)$att->filesize)
					{
						$dest_sha256 = hash_file('sha256', $dest_file);
						if ($dest_sha256 === $src_sha256)
						{
							$need_copy = false; // Verified exact match, safely reuse
						}
						else
						{
							$rand_token = bin2hex(random_bytes(16));
							$physical_filename = "{$user_target_id}_{$rand_token}";
							$dest_file = $files_dir . '/' . $physical_filename;
							$need_copy = true;
						}
					}
					else
					{
						$need_copy = true;
					}
				}

				if ($need_copy)
				{
					$temp_dest = $dest_file . '.tmp_' . bin2hex(random_bytes(8));
					if (!@copy($src_file, $temp_dest))
					{
						throw new \RuntimeException("Failed to copy attachment file to {$temp_dest}");
					}
					// Verify copied file hash
					if (hash_file('sha256', $temp_dest) !== $src_sha256)
					{
						@unlink($temp_dest);
						throw new \RuntimeException("Copied attachment hash mismatch for source ID {$source_id}");
					}
					if (!@rename($temp_dest, $dest_file))
					{
						@unlink($temp_dest);
						throw new \RuntimeException("Failed to atomically rename attachment file to {$dest_file}");
					}
				}

				// 7. Config-Driven Thumbnail Generation
				$thumbnail_flag = 0;
				
				$sql_cfg = 'SELECT config_name, config_value FROM ' . $this->table_prefix . "config 
							WHERE config_name IN ('img_create_thumbnail', 'img_max_thumb_width', 'img_min_thumb_filesize')";
				$res_cfg = $this->db->sql_query($sql_cfg);
				$phpbb_img_cfg = [];
				while ($rc = $this->db->sql_fetchrow($res_cfg))
				{
					$phpbb_img_cfg[$rc['config_name']] = $rc['config_value'];
				}
				$this->db->sql_freeresult($res_cfg);

				$cfg_create_thumb = !empty($phpbb_img_cfg['img_create_thumbnail']) || !empty($options['force_thumbnail']);
				$cfg_max_width = (int)($phpbb_img_cfg['img_max_thumb_width'] ?? 400) ?: 400;
				$cfg_min_size = (int)($phpbb_img_cfg['img_min_thumb_filesize'] ?? 12000);

				if ($cfg_create_thumb && (int)$att->filesize >= $cfg_min_size && in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp'], true) && function_exists('imagecreatefromstring'))
				{
					try
					{
						$img_data = @file_get_contents($dest_file);
						if ($img_data !== false)
						{
							$img = @imagecreatefromstring($img_data);
							if ($img)
							{
								$w = imagesx($img);
								$h = imagesy($img);

								if ($w > $cfg_max_width || $h > $cfg_max_width)
								{
									$ratio = min($cfg_max_width / $w, $cfg_max_width / $h);
									$new_w = max(1, (int)round($w * $ratio));
									$new_h = max(1, (int)round($h * $ratio));

									$thumb = imagecreatetruecolor($new_w, $new_h);
									if ($ext === 'png' || $ext === 'webp')
									{
										imagealphablending($thumb, false);
										imagesavealpha($thumb, true);
									}
									imagecopyresampled($thumb, $img, 0, 0, 0, 0, $new_w, $new_h, $w, $h);

									$thumb_path = $files_dir . '/thumb_' . $physical_filename;
									if ($ext === 'png')
									{
										imagepng($thumb, $thumb_path);
									}
									else if ($ext === 'webp' && function_exists('imagewebp'))
									{
										imagewebp($thumb, $thumb_path);
									}
									else
									{
										imagejpeg($thumb, $thumb_path, 85);
									}

									imagedestroy($thumb);

									if (file_exists($thumb_path))
									{
										$thumbnail_flag = 1;
									}
								}
								imagedestroy($img);
							}
						}
					}
					catch (\Throwable $e)
					{
						$thumbnail_flag = 0;
					}
				}

				$target_filetime = (int)($att->filetime ?: time());
				if ($is_pm)
				{
					if (!isset($pm_filetimes[$target_post_msg_id]))
					{
						$pm_filetimes[$target_post_msg_id] = [];
					}
					while (in_array($target_filetime, $pm_filetimes[$target_post_msg_id], true))
					{
						$target_filetime--;
					}
					$pm_filetimes[$target_post_msg_id][] = $target_filetime;
				}

				$att_row = [
					'post_msg_id'       => $target_post_msg_id,
					'topic_id'          => $target_topic_id,
					'in_message'        => $in_message,
					'poster_id'         => $user_target_id,
					'is_orphan'         => 0,
					'physical_filename' => $physical_filename,
					'real_filename'     => $clean_filename,
					'download_count'    => (int)$att->download_count,
					'attach_comment'    => $this->sanitize_utf8($att->attach_comment),
					'extension'         => $ext,
					'mimetype'          => (string)($att->mimetype ?: 'application/octet-stream'),
					'filesize'          => (int)$att->filesize,
					'filetime'          => $target_filetime,
					'thumbnail'         => $thumbnail_flag,
				];

				$sql = 'INSERT INTO ' . $this->table_prefix . 'attachments ' . $this->db->sql_build_array('INSERT', $att_row);
				$this->db->sql_query($sql);
				$target_id = (int)$this->db->sql_nextid();

				$this->id_mapper->set($run_id, $source_system, $map_type, $source_id, $target_id, 'mapped', '', [
					'post_msg_id'       => $target_post_msg_id,
					'in_message'        => $in_message,
					'physical_filename' => $physical_filename,
					'real_filename'     => $clean_filename,
					'source_sha256'     => $src_sha256,
					'source_filetime'   => (int)$att->filetime,
					'filesize'          => (int)$att->filesize,
					'run_id'            => $run_id,
					'source_system'     => $source_system,
				]);

				if ($is_pm)
				{
					$affected_pms[$target_post_msg_id] = true;
				}
				else
				{
					$affected_posts[$target_post_msg_id] = true;
					$affected_topics[$target_topic_id] = true;
				}

				$results[$source_id] = [
					'target_id' => $target_id,
					'status'    => 'success',
					'error'     => null,
				];
			}
			catch (\Throwable $e)
			{
				$results[$source_id] = [
					'target_id' => null,
					'status'    => 'failed',
					'error'     => $e->getMessage(),
				];
			}
		}

		// Finalize inline attachment markers and update flags
		if (!empty($affected_posts))
		{
			$this->finalize_post_attachments(array_keys($affected_posts), [
				'source_system' => $source_system,
			]);
		}

		if (!empty($affected_pms))
		{
			$this->finalize_pm_attachments(array_keys($affected_pms), [
				'source_system' => $source_system,
			]);
		}

		return $results;
	}

	/**
	 * Finalize deferred inline attachment markers in PM text and update message_attachment flag
	 *
	 * @param array $target_msg_ids
	 * @param array $options
	 * @return array
	 */
	public function finalize_pm_attachments(array $target_msg_ids, array $options = []): array
	{
		$source_system = (string)($options['source_system'] ?? 'xenforo');
		$finalized = [];

		foreach ($target_msg_ids as $msg_id)
		{
			$msg_id = (int)$msg_id;
			if ($msg_id <= 0)
			{
				continue;
			}

			// 1. Query attachments for this PM in native phpBB UCP order (ORDER BY filetime DESC, post_msg_id ASC)
			$sql = 'SELECT attach_id, real_filename FROM ' . $this->table_prefix . 'attachments 
					WHERE post_msg_id = ' . $msg_id . ' AND in_message = 1 
					ORDER BY filetime DESC, post_msg_id ASC';
			$res = $this->db->sql_query($sql);
			$attachments = [];
			while ($row = $this->db->sql_fetchrow($res))
			{
				$attachments[] = $row;
			}
			$this->db->sql_freeresult($res);

			// 2. Query target PM text
			$sql = 'SELECT msg_id, message_subject, message_text, bbcode_uid, bbcode_bitfield, enable_bbcode, enable_smilies, enable_magic_url 
					FROM ' . $this->table_prefix . 'privmsgs 
					WHERE msg_id = ' . $msg_id;
			$res = $this->db->sql_query($sql);
			$pm_row = $this->db->sql_fetchrow($res);
			$this->db->sql_freeresult($res);

			if (!$pm_row)
			{
				continue;
			}

			// 3. Build source_attachment_id -> inline index mapping
			$source_to_inline = [];
			foreach ($attachments as $idx => $att)
			{
				$src_att_id = $this->id_mapper->get_source_id($source_system, 'pm_attachment', $att['attach_id']);
				if ($src_att_id !== null)
				{
					$source_to_inline[(string)$src_att_id] = [
						'index'    => $idx,
						'filename' => $att['real_filename'],
					];
				}
			}

			$text = $pm_row['message_text'];

			// 4. Replace deferred markers [[MC_PM_ATTACH:{source_id}]]
			$text = preg_replace_callback('/\[\[MC_PM_ATTACH:([0-9]+)\]\]/', function ($m) use ($source_to_inline) {
				$src_id = (string)$m[1];
				if (isset($source_to_inline[$src_id]))
				{
					$idx = $source_to_inline[$src_id]['index'];
					$fname = $source_to_inline[$src_id]['filename'];
					return "[attachment={$idx}]{$fname}[/attachment]";
				}
				return "[Attachment unavailable: #{$src_id}]";
			}, $text);

			// 5. Regenerate storage XML and BBCode UID
			$uid = $bitfield = $flags = '';
			$allow_bbcode = !empty($pm_row['enable_bbcode']);
			$allow_urls = !empty($pm_row['enable_magic_url']);
			$allow_smilies = !empty($pm_row['enable_smilies']);

			generate_text_for_storage($text, $uid, $bitfield, $flags, $allow_bbcode, $allow_urls, $allow_smilies);

			// 6. Update privmsgs row
			$has_attachments = count($attachments) > 0 ? 1 : 0;
			$sql = 'UPDATE ' . $this->table_prefix . "privmsgs SET 
						message_text = '" . $this->db->sql_escape($text) . "',
						bbcode_uid = '" . $this->db->sql_escape($uid) . "',
						bbcode_bitfield = '" . $this->db->sql_escape($bitfield) . "',
						message_attachment = {$has_attachments} 
					WHERE msg_id = {$msg_id}";
			$this->db->sql_query($sql);

			$finalized[$msg_id] = [
				'attachments_count' => count($attachments),
				'status'            => 'finalized',
			];
		}

		return $finalized;
	}

	/**
	 * Finalize deferred inline attachment markers in post text and update post/topic flags
	 *
	 * @param array $target_post_ids
	 * @param array $options
	 * @return array
	 */
	public function finalize_post_attachments(array $target_post_ids, array $options = []): array
	{
		$source_system = (string)($options['source_system'] ?? 'xenforo');
		$finalized = [];

		foreach ($target_post_ids as $post_id)
		{
			$post_id = (int)$post_id;
			if ($post_id <= 0)
			{
				continue;
			}

			// 1. Query all attachments for this post in native phpBB viewtopic order (ORDER BY attach_id DESC)
			$sql = 'SELECT attach_id, real_filename FROM ' . $this->table_prefix . 'attachments 
					WHERE post_msg_id = ' . $post_id . ' 
					ORDER BY attach_id DESC';
			$res = $this->db->sql_query($sql);
			$att_rows = [];
			while ($r = $this->db->sql_fetchrow($res))
			{
				$att_rows[] = $r;
			}
			$this->db->sql_freeresult($res);

			if (empty($att_rows))
			{
				continue;
			}

			// Map: source_attachment_id => ['index' => $idx, 'filename' => $real_filename]
			$source_att_map = [];
			foreach ($att_rows as $idx => $att_row)
			{
				$tgt_attach_id = (int)$att_row['attach_id'];
				$src_attach_id = $this->id_mapper->get_source_id($source_system, 'attachment', $tgt_attach_id);
				if ($src_attach_id !== null)
				{
					$source_att_map[(int)$src_attach_id] = [
						'index'    => $idx,
						'filename' => $att_row['real_filename'],
					];
				}
			}

			// 2. Load post text
			$sql = 'SELECT topic_id, post_text FROM ' . $this->table_prefix . 'posts WHERE post_id = ' . $post_id;
			$res = $this->db->sql_query($sql);
			$post_data = $this->db->sql_fetchrow($res);
			$this->db->sql_freeresult($res);

			if (!$post_data)
			{
				continue;
			}

			$topic_id = (int)$post_data['topic_id'];
			$post_text = (string)$post_data['post_text'];

			// 3. Replace [[MC_ATTACH:{source_id}]] markers with [attachment=n]filename[/attachment]
			$marker_regex = '/\[\[MC_ATTACH:(\d+)\]\]/';
			$has_markers = (bool)preg_match($marker_regex, $post_text);

			if ($has_markers)
			{
				$post_text = preg_replace_callback($marker_regex, function ($m) use ($source_att_map) {
					$src_id = (int)$m[1];
					if (isset($source_att_map[$src_id]))
					{
						$idx = $source_att_map[$src_id]['index'];
						$fn = $source_att_map[$src_id]['filename'];
						return "[attachment={$idx}]{$fn}[/attachment]";
					}
					return "[Attachment unavailable: #{$src_id}]";
				}, $post_text);

				// Re-generate storage text
				$uid = $bitfield = '';
				$flags = 0;
				if (function_exists('generate_text_for_storage'))
				{
					try
					{
						$allow_bbcode = $allow_urls = $allow_smilies = true;
						generate_text_for_storage($post_text, $uid, $bitfield, $flags, $allow_bbcode, $allow_urls, $allow_smilies);
					}
					catch (\Throwable $e)
					{
					}
				}

				// Update post text with regenerated storage format
				$this->db->sql_query('UPDATE ' . $this->table_prefix . 'posts 
					SET post_text = ' . "'" . $this->db->sql_escape($post_text) . "'" . ', 
						post_attachment = 1 
					WHERE post_id = ' . $post_id);
			}
			else
			{
				// Non-inline attachment: just set post_attachment = 1
				$this->db->sql_query('UPDATE ' . $this->table_prefix . 'posts SET post_attachment = 1 WHERE post_id = ' . $post_id);
			}

			// Update topic_attachment = 1
			if ($topic_id > 0)
			{
				$this->db->sql_query('UPDATE ' . $this->table_prefix . 'topics SET topic_attachment = 1 WHERE topic_id = ' . $topic_id);
			}

			$finalized[$post_id] = true;
		}

		return $finalized;
	}

	/**
	 * Perform recount, synchronization, and finalization
	 *
	 * @param array $steps_run
	 * @return void
	 */
	public function finalize(array $steps_run = []): void
	{
		if (empty($steps_run) || in_array('posts', $steps_run, true) || in_array('topics', $steps_run, true) || in_array('forums', $steps_run, true))
		{
			// 1. Finalize all topics that have posts
			$sql = 'SELECT DISTINCT topic_id FROM ' . $this->table_prefix . 'posts WHERE topic_id > 0';
			$res = $this->db->sql_query($sql);
			$topic_ids = [];
			while ($row = $this->db->sql_fetchrow($res))
			{
				$topic_ids[] = (int)$row['topic_id'];
			}
			$this->db->sql_freeresult($res);

			if (!empty($topic_ids))
			{
				$this->finalize_topics($topic_ids);
			}

			// 2. Synchronize all forums that contain topics
			$sql = 'SELECT DISTINCT forum_id FROM ' . $this->table_prefix . 'topics WHERE forum_id > 0';
			$res = $this->db->sql_query($sql);
			$forum_ids = [];
			while ($row = $this->db->sql_fetchrow($res))
			{
				$forum_ids[] = (int)$row['forum_id'];
			}
			$this->db->sql_freeresult($res);

			if (!empty($forum_ids))
			{
				$this->synchronize_forums($forum_ids);
			}

			// 3. Synchronize user post counts (excluding anonymous / bot)
			$sql = 'UPDATE ' . $this->table_prefix . 'users u 
					SET user_posts = (
						SELECT COUNT(*) FROM ' . $this->table_prefix . 'posts p 
						WHERE p.poster_id = u.user_id AND p.post_visibility = 1
					) 
					WHERE u.user_id > 1 AND u.user_type <> 2';
			$this->db->sql_query($sql);
		}

		// 4. Ensure all forums have canonical permissions assigned
		$this->reconcile_all_forum_permissions();

		// 5. Reconcile newest user stats in config
		$this->reconcile_newest_user_config();

		// 6. Reconcile user signatures with BBCode
		$this->reconcile_user_signatures();

		// 7. Clear user permissions on all users to recompute fresh permissions
		$this->db->sql_query('UPDATE ' . $this->table_prefix . "users SET user_permissions = ''");

		// 8. Invalidate ACL cache and purge caches
		if (is_object($this->cache) && method_exists($this->cache, 'destroy'))
		{
			$this->cache->destroy('acl_options');
			$this->cache->destroy('_acl_options');
		}

		if (is_object($this->cache) && method_exists($this->cache, 'purge'))
		{
			$this->cache->purge();
		}
	}

	/**
	 * Assign default canonical forum ACL roles for a forum or category
	 *
	 * @param int $forum_id
	 * @param int $forum_type
	 * @return void
	 */
	public function assign_default_forum_permissions(int $forum_id, int $forum_type): void
	{
		$forum_id = (int)$forum_id;
		if ($forum_id <= 0)
		{
			return;
		}

		$sql = 'SELECT role_id, role_name FROM ' . $this->table_prefix . "acl_roles WHERE role_type IN ('f_', 'm_')";
		$res = $this->db->sql_query($sql);
		$roles = [];
		while ($row = $this->db->sql_fetchrow($res))
		{
			$roles[$row['role_name']] = (int)$row['role_id'];
		}
		$this->db->sql_freeresult($res);

		$role_forum_full     = $roles['ROLE_FORUM_FULL'] ?? 14;
		$role_forum_standard = $roles['ROLE_FORUM_STANDARD'] ?? 15;
		$role_forum_readonly = $roles['ROLE_FORUM_READONLY'] ?? 17;
		$role_forum_bot      = $roles['ROLE_FORUM_BOT'] ?? 19;
		$role_forum_polls    = $roles['ROLE_FORUM_POLLS'] ?? 21;
		$role_forum_new      = $roles['ROLE_FORUM_NEW_MEMBER'] ?? 24;
		$role_mod_full       = $roles['ROLE_MOD_FULL'] ?? 10;

		if ($forum_type === 0)
		{
			// Category permissions
			$acl_rows = [
				['group_id' => 1, 'forum_id' => $forum_id, 'auth_option_id' => 0, 'auth_role_id' => $role_forum_readonly, 'auth_setting' => 0],
				['group_id' => 2, 'forum_id' => $forum_id, 'auth_option_id' => 0, 'auth_role_id' => $role_forum_readonly, 'auth_setting' => 0],
				['group_id' => 3, 'forum_id' => $forum_id, 'auth_option_id' => 0, 'auth_role_id' => $role_forum_readonly, 'auth_setting' => 0],
				['group_id' => 4, 'forum_id' => $forum_id, 'auth_option_id' => 0, 'auth_role_id' => $role_forum_standard, 'auth_setting' => 0],
				['group_id' => 5, 'forum_id' => $forum_id, 'auth_option_id' => 0, 'auth_role_id' => $role_forum_full,     'auth_setting' => 0],
				['group_id' => 6, 'forum_id' => $forum_id, 'auth_option_id' => 0, 'auth_role_id' => $role_forum_readonly, 'auth_setting' => 0],
			];
		}
		else
		{
			// Forum permissions
			$acl_rows = [
				['group_id' => 1, 'forum_id' => $forum_id, 'auth_option_id' => 0, 'auth_role_id' => $role_forum_readonly, 'auth_setting' => 0],
				['group_id' => 2, 'forum_id' => $forum_id, 'auth_option_id' => 0, 'auth_role_id' => $role_forum_standard, 'auth_setting' => 0],
				['group_id' => 3, 'forum_id' => $forum_id, 'auth_option_id' => 0, 'auth_role_id' => $role_forum_standard, 'auth_setting' => 0],
				['group_id' => 4, 'forum_id' => $forum_id, 'auth_option_id' => 0, 'auth_role_id' => $role_forum_polls,    'auth_setting' => 0],
				['group_id' => 5, 'forum_id' => $forum_id, 'auth_option_id' => 0, 'auth_role_id' => $role_forum_full,     'auth_setting' => 0],
				['group_id' => 5, 'forum_id' => $forum_id, 'auth_option_id' => 0, 'auth_role_id' => $role_mod_full,       'auth_setting' => 0],
				['group_id' => 6, 'forum_id' => $forum_id, 'auth_option_id' => 0, 'auth_role_id' => $role_forum_bot,      'auth_setting' => 0],
				['group_id' => 7, 'forum_id' => $forum_id, 'auth_option_id' => 0, 'auth_role_id' => $role_forum_new,      'auth_setting' => 0],
			];
		}

		foreach ($acl_rows as $row)
		{
			$chk_sql = 'SELECT COUNT(*) as cnt FROM ' . $this->table_prefix . 'acl_groups WHERE group_id = ' . (int)$row['group_id'] . ' AND forum_id = ' . (int)$row['forum_id'] . ' AND auth_role_id = ' . (int)$row['auth_role_id'];
			$chk_res = $this->db->sql_query($chk_sql);
			$exists = (int)$this->db->sql_fetchfield('cnt');
			$this->db->sql_freeresult($chk_res);

			if (!$exists)
			{
				$this->db->sql_query('INSERT INTO ' . $this->table_prefix . 'acl_groups ' . $this->db->sql_build_array('INSERT', $row));
			}
		}
	}

	/**
	 * Reconcile permissions for all forums in target database that lack ACL assignments
	 *
	 * @return void
	 */
	public function reconcile_all_forum_permissions(): void
	{
		$sql = 'SELECT forum_id, forum_type FROM ' . $this->table_prefix . 'forums';
		$res = $this->db->sql_query($sql);
		$forums = [];
		while ($row = $this->db->sql_fetchrow($res))
		{
			$forums[] = $row;
		}
		$this->db->sql_freeresult($res);

		foreach ($forums as $f)
		{
			$fid = (int)$f['forum_id'];
			$ftype = (int)$f['forum_type'];
			$chk_sql = 'SELECT COUNT(*) as cnt FROM ' . $this->table_prefix . 'acl_groups WHERE forum_id = ' . $fid;
			$chk_res = $this->db->sql_query($chk_sql);
			$has_acl = (int)$this->db->sql_fetchfield('cnt');
			$this->db->sql_freeresult($chk_res);

			if ($has_acl === 0)
			{
				$this->assign_default_forum_permissions($fid, $ftype);
			}
		}
	}

	/**
	 * Reconcile newest user stats in phpBB config from registered users table
	 *
	 * @return void
	 */
	public function reconcile_newest_user_config(): void
	{
		$sql = 'SELECT user_id, username, user_colour FROM ' . $this->table_prefix . 'users WHERE user_type <> 2 AND user_id > 2 ORDER BY user_regdate DESC, user_id DESC';
		$res = $this->db->sql_query_limit($sql, 1);
		$newest = $this->db->sql_fetchrow($res);
		$this->db->sql_freeresult($res);

		if ($newest)
		{
			$this->update_newest_user_config((int)$newest['user_id'], (string)$newest['username'], (string)($newest['user_colour'] ?? ''));
		}
	}

	/**
	 * Update newest user configuration values
	 *
	 * @param int $user_id
	 * @param string $username
	 * @param string $user_colour
	 * @return void
	 */
	public function update_newest_user_config(int $user_id, string $username, string $user_colour = ''): void
	{
		$this->config->set('newest_user_id', $user_id);
		$this->config->set('newest_username', $username);
		$this->config->set('newest_user_colour', $user_colour);
	}

	/**
	 * Reconcile signatures containing raw BBCode to ensure proper uid and bitfield
	 *
	 * @return void
	 */
	public function reconcile_user_signatures(): void
	{
		$sql = 'SELECT user_id, user_sig, user_sig_bbcode_uid FROM ' . $this->table_prefix . "users WHERE user_sig <> ''";
		$res = $this->db->sql_query($sql);
		$converter = new \phpbbseo\migrationcenter\source\xenforo\content\xf_message_converter();
		$to_update = [];
		while ($row = $this->db->sql_fetchrow($res))
		{
			$to_update[] = $row;
		}
		$this->db->sql_freeresult($res);

		foreach ($to_update as $u)
		{
			$uid = (int)$u['user_id'];
			$raw_sig = (string)$u['user_sig'];

			// Unpack if already wrapped in XML or entities
			$clean_text = $raw_sig;
			while (preg_match('/<[a-zA-Z0-9_]+[^>]*>(.*?)<\/[a-zA-Z0-9_]+>/s', $clean_text))
			{
				$clean_text = strip_tags($clean_text);
				$clean_text = html_entity_decode($clean_text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
			}
			$clean_text = trim($clean_text);

			if ($clean_text === '')
			{
				continue;
			}

			$conv = $converter->convert($clean_text, null);
			$parsed = $conv->storage_text ?: $conv->normalized_bbcode;
			$bb_uid = $conv->bbcode_uid;
			$bb_bitfield = $conv->bbcode_bitfield;

			$sql_up = 'UPDATE ' . $this->table_prefix . "users SET 
						user_sig = '" . $this->db->sql_escape($parsed) . "',
						user_sig_bbcode_uid = '" . $this->db->sql_escape($bb_uid) . "',
						user_sig_bbcode_bitfield = '" . $this->db->sql_escape($bb_bitfield) . "'
					   WHERE user_id = " . $uid;
			$this->db->sql_query($sql_up);
		}
	}

	/**
	 * Write a batch of user avatars
	 *
	 * @param avatar_dto[] $avatars
	 * @param array $options
	 * @return array
	 */
	public function write_avatars(array $avatars, array $options = []): array
	{
		$results = [];
		$run_id = (string)($options['run_id'] ?? '');
		$source_system = (string)($options['source_system'] ?? 'xenforo');
		$avatar_policy = (string)($options['avatar_policy'] ?? 'resize_to_fit');
		$existing_avatar_policy = (string)($options['existing_avatar_policy'] ?? 'replace_only_if_empty');
		$gravatar_policy = (string)($options['gravatar_policy'] ?? 'preserve_as_gravatar');

		// 1. Read phpBB Avatar Configuration
		$sql_cfg = 'SELECT config_name, config_value FROM ' . $this->table_prefix . "config 
					WHERE config_name IN (
						'allow_avatar', 'allow_avatar_upload', 'allow_avatar_gravatar',
						'avatar_salt', 'avatar_path', 'avatar_max_width', 'avatar_max_height', 'avatar_filesize'
					)";
		$res_cfg = $this->db->sql_query($sql_cfg);
		$cfg = [];
		while ($rc = $this->db->sql_fetchrow($res_cfg))
		{
			$cfg[$rc['config_name']] = $rc['config_value'];
		}
		$this->db->sql_freeresult($res_cfg);

		$allow_avatar = !empty($cfg['allow_avatar']) || !empty($options['force_avatar']);
		$allow_upload = !empty($cfg['allow_avatar_upload']) || !empty($options['force_avatar_upload']);
		$allow_gravatar = !empty($cfg['allow_avatar_gravatar']) || !empty($options['force_gravatar']);

		$avatar_salt = (string)($cfg['avatar_salt'] ?? '');
		$avatar_path = (string)($cfg['avatar_path'] ?? 'images/avatars/upload');
		$max_width = isset($cfg['avatar_max_width']) ? (int)$cfg['avatar_max_width'] : 0;
		$max_height = isset($cfg['avatar_max_height']) ? (int)$cfg['avatar_max_height'] : 0;
		$min_width = isset($cfg['avatar_min_width']) ? (int)$cfg['avatar_min_width'] : 0;
		$min_height = isset($cfg['avatar_min_height']) ? (int)$cfg['avatar_min_height'] : 0;
		$max_filesize = isset($cfg['avatar_filesize']) ? (int)$cfg['avatar_filesize'] : 0;

		global $phpbb_root_path;
		$phpbb_root = !empty($phpbb_root_path) ? $phpbb_root_path : (defined('PHPBB_ROOT_PATH') ? PHPBB_ROOT_PATH : (dirname(__DIR__, 5) . '/'));
		$target_avatar_dir = rtrim($phpbb_root . $avatar_path, '/\\');
		if (!is_dir($target_avatar_dir))
		{
			@mkdir($target_avatar_dir, 0777, true);
		}

		$anonymous_id = defined('ANONYMOUS') ? ANONYMOUS : 1;

		foreach ($avatars as $av)
		{
			$source_id = $av->user_source_id;

			try
			{
				// 1. Resolve Target User ID
				$target_user_id = $this->id_mapper->get_target_id($source_system, 'user', $source_id);
				if (!$target_user_id)
				{
					$results[$source_id] = [
						'target_id' => null,
						'status'    => 'skipped',
						'error'     => "Target user mapping not found for source user ID {$source_id}",
					];
					continue;
				}

				$target_user_id = (int)$target_user_id;

				// 2. Query target user's current avatar state and user type
				$sql = 'SELECT user_id, user_type, user_avatar, user_avatar_type, user_avatar_width, user_avatar_height 
						FROM ' . $this->table_prefix . 'users 
						WHERE user_id = ' . $target_user_id;
				$res = $this->db->sql_query($sql);
				$target_user_row = $this->db->sql_fetchrow($res);
				$this->db->sql_freeresult($res);

				if (!$target_user_row)
				{
					$results[$source_id] = [
						'target_id' => null,
						'status'    => 'skipped',
						'error'     => "Target user row {$target_user_id} not found",
					];
					continue;
				}

				// Security Protection: Never touch Anonymous or Board Founder accounts
				$is_founder = ((int)($target_user_row['user_type'] ?? 0) === 3);
				if ($target_user_id === $anonymous_id || $is_founder)
				{
					$results[$source_id] = [
						'target_id' => $target_user_id,
						'status'    => 'skipped',
						'error'     => "Protected target user ID {$target_user_id} (founder/anonymous) cannot be modified",
					];
					continue;
				}

				$has_existing_avatar = !empty($target_user_row['user_avatar']);
				if ($has_existing_avatar)
				{
					if ($existing_avatar_policy === 'preserve_target' || $existing_avatar_policy === 'replace_only_if_empty')
					{
						$results[$source_id] = [
							'target_id' => $target_user_id,
							'status'    => 'skipped',
							'note'      => 'target_avatar_preserved',
							'error'     => null,
						];
						continue;
					}
					else if ($existing_avatar_policy === 'skip')
					{
						$results[$source_id] = [
							'target_id' => $target_user_id,
							'status'    => 'skipped',
							'note'      => 'existing_avatar_skipped',
							'error'     => null,
						];
						continue;
					}
				}

				// 3. Process by Avatar Type
				if ($av->avatar_type === 'gravatar')
				{
					if (!$allow_gravatar && $gravatar_policy !== 'force')
					{
						$results[$source_id] = [
							'target_id' => $target_user_id,
							'status'    => 'skipped',
							'error'     => 'Gravatar is disabled in target phpBB configuration',
						];
						continue;
					}

					$clean_email = trim((string)$av->gravatar_email);
					if (!filter_var($clean_email, FILTER_VALIDATE_EMAIL))
					{
						$results[$source_id] = [
							'target_id' => $target_user_id,
							'status'    => 'skipped',
							'error'     => 'Invalid Gravatar email format',
						];
						continue;
					}

					$sql = 'UPDATE ' . $this->table_prefix . "users SET 
								user_avatar = '" . $this->db->sql_escape($clean_email) . "',
								user_avatar_type = 'avatar.driver.gravatar',
								user_avatar_width = 80,
								user_avatar_height = 80 
							WHERE user_id = " . $target_user_id;
					$this->db->sql_query($sql);

					$this->id_mapper->set($run_id, $source_system, 'avatar', $source_id, $target_user_id, 'mapped', '', [
						'type'          => 'gravatar',
						'target_avatar' => $clean_email,
						'width'         => 80,
						'height'        => 80,
					]);

					$results[$source_id] = [
						'target_id' => $target_user_id,
						'status'    => 'success',
						'error'     => null,
					];
					continue;
				}
				else if ($av->avatar_type === 'upload')
				{
					if (!$allow_avatar || !$allow_upload)
					{
						if ($avatar_policy === 'respect_target_policy')
						{
							$results[$source_id] = [
								'target_id' => $target_user_id,
								'status'    => 'skipped',
								'error'     => 'Avatar uploads are disabled in target board configuration',
							];
							continue;
						}
					}

					$src_file = $av->source_physical_path;
					if (empty($src_file) || !file_exists($src_file) || !is_readable($src_file))
					{
						$results[$source_id] = [
							'target_id' => $target_user_id,
							'status'    => 'skipped',
							'error'     => 'Source avatar file missing or unreadable',
						];
						continue;
					}

					if ($max_filesize > 0 && filesize($src_file) > $max_filesize)
					{
						if ($avatar_policy === 'respect_target_policy')
						{
							$results[$source_id] = [
								'target_id' => $target_user_id,
								'status'    => 'skipped',
								'error'     => 'Avatar filesize exceeds avatar_filesize target limit',
							];
							continue;
						}
					}

					$src_sha256 = hash_file('sha256', $src_file);
					$clean_ext = strtolower(pathinfo($src_file, PATHINFO_EXTENSION)) ?: 'jpg';
					if ($clean_ext === 'jpeg')
					{
						$clean_ext = 'jpg';
					}

					// Disallow SVG/HTML/PHP executable uploads
					if (in_array($clean_ext, ['php', 'phtml', 'svg', 'html', 'htm', 'exe', 'sh'], true))
					{
						$results[$source_id] = [
							'target_id' => $target_user_id,
							'status'    => 'skipped',
							'error'     => "Disallowed avatar extension: .{$clean_ext}",
						];
						continue;
					}

					// Target phpBB filename conventions
					// user_avatar value: {$target_user_id}.{$clean_ext}
					// physical file: {$avatar_salt}_{$target_user_id}.{$clean_ext}
					$target_avatar_val = "{$target_user_id}.{$clean_ext}";
					$physical_filename = $avatar_salt !== '' 
						? "{$avatar_salt}_{$target_user_id}.{$clean_ext}"
						: "{$target_user_id}.{$clean_ext}";
					$dest_file = $target_avatar_dir . '/' . $physical_filename;

					// Load Image for Dimension Check and Resizing
					$img_data = @file_get_contents($src_file);
					if ($img_data === false)
					{
						throw new \RuntimeException("Failed to read source avatar file: {$src_file}");
					}

					$img = @imagecreatefromstring($img_data);
					if (!$img)
					{
						$results[$source_id] = [
							'target_id' => $target_user_id,
							'status'    => 'skipped',
							'error'     => 'Failed to decode image data',
						];
						continue;
					}

					$orig_w = imagesx($img);
					$orig_h = imagesy($img);

					// Min dimensions check
					if (($min_width > 0 && $orig_w < $min_width) || ($min_height > 0 && $orig_h < $min_height))
					{
						if ($avatar_policy === 'respect_target_policy')
						{
							imagedestroy($img);
							$results[$source_id] = [
								'target_id' => $target_user_id,
								'status'    => 'skipped',
								'error'     => 'Avatar dimensions below target minimum requirements',
							];
							continue;
						}
					}

					$final_w = $orig_w;
					$final_h = $orig_h;
					$transformed = 'none';

					// Resizing policy (0 means unlimited/no bound)
					$need_resize = ($max_width > 0 && $orig_w > $max_width) || ($max_height > 0 && $orig_h > $max_height);
					if ($need_resize && $avatar_policy === 'resize_to_fit')
					{
						if ($max_width > 0 && $max_height > 0)
						{
							$ratio = min($max_width / $orig_w, $max_height / $orig_h);
						}
						else if ($max_width > 0)
						{
							$ratio = $max_width / $orig_w;
						}
						else
						{
							$ratio = $max_height / $orig_h;
						}

						$final_w = max(1, (int)round($orig_w * $ratio));
						$final_h = max(1, (int)round($orig_h * $ratio));

						$new_img = imagecreatetruecolor($final_w, $final_h);
						if ($clean_ext === 'png' || $clean_ext === 'webp')
						{
							imagealphablending($new_img, false);
							imagesavealpha($new_img, true);
						}
						imagecopyresampled($new_img, $img, 0, 0, 0, 0, $final_w, $final_h, $orig_w, $orig_h);

						$temp_dest = $dest_file . '.tmp_' . bin2hex(random_bytes(8));
						if ($clean_ext === 'png')
						{
							imagepng($new_img, $temp_dest);
						}
						else if ($clean_ext === 'webp' && function_exists('imagewebp'))
						{
							imagewebp($new_img, $temp_dest);
						}
						else
						{
							imagejpeg($new_img, $temp_dest, 90);
						}

						imagedestroy($new_img);
						imagedestroy($img);

						if (!@rename($temp_dest, $dest_file))
						{
							@unlink($temp_dest);
							throw new \RuntimeException("Failed to rename resized avatar to {$dest_file}");
						}
						$transformed = 'resized';
					}
					else
					{
						imagedestroy($img);
						// Direct copy
						$temp_dest = $dest_file . '.tmp_' . bin2hex(random_bytes(8));
						if (!@copy($src_file, $temp_dest))
						{
							throw new \RuntimeException("Failed to copy avatar to {$temp_dest}");
						}
						if (!@rename($temp_dest, $dest_file))
						{
							@unlink($temp_dest);
							throw new \RuntimeException("Failed to rename avatar to {$dest_file}");
						}
						$transformed = 'copied';
					}

					$final_sha256 = hash_file('sha256', $dest_file);

					// Update target phpbb_users
					$sql = 'UPDATE ' . $this->table_prefix . "users SET 
								user_avatar = '" . $this->db->sql_escape($target_avatar_val) . "',
								user_avatar_type = 'avatar.driver.upload',
								user_avatar_width = " . (int)$final_w . ',
								user_avatar_height = ' . (int)$final_h . ' 
							WHERE user_id = ' . $target_user_id;
					$this->db->sql_query($sql);

					$this->id_mapper->set($run_id, $source_system, 'avatar', $source_id, $target_user_id, 'mapped', '', [
						'type'                     => 'upload',
						'target_avatar'            => $target_avatar_val,
						'physical_filename'        => $physical_filename,
						'source_sha256'            => $src_sha256,
						'final_sha256'             => $final_sha256,
						'width'                    => (int)$final_w,
						'height'                   => (int)$final_h,
						'transformation_performed' => $transformed,
						'source_size_variant'      => $av->source_size_variant,
					]);

					$results[$source_id] = [
						'target_id' => $target_user_id,
						'status'    => 'success',
						'error'     => null,
					];
				}
				else
				{
					// Default / No Avatar
					$results[$source_id] = [
						'target_id' => $target_user_id,
						'status'    => 'skipped',
						'note'      => 'no_avatar',
						'error'     => null,
					];
				}
			}
			catch (\Throwable $e)
			{
				$results[$source_id] = [
					'target_id' => null,
					'status'    => 'failed',
					'error'     => $e->getMessage(),
				];
			}
		}

		return $results;
	}

	/**
	 * Write a batch of conversation metadata
	 *
	 * @param conversation_dto[] $conversations
	 * @param array $options
	 * @return array
	 */
	public function write_conversations(array $conversations, array $options = []): array
	{
		$results = [];
		$run_id = (string)($options['run_id'] ?? '');
		$source_system = (string)($options['source_system'] ?? 'xenforo');

		foreach ($conversations as $conv)
		{
			$cid = $conv->source_id;

			try
			{
				$recip_data = [];
				foreach ($conv->recipients as $r)
				{
					$recip_data[(int)$r->user_source_id] = [
						'state'          => $r->recipient_state,
						'last_read_date' => $r->last_read_date,
						'is_starred'     => $r->is_starred ? 1 : 0,
						'is_unread'      => $r->is_unread ? 1 : 0,
						'join_date'      => (int)($r->raw_data['join_date'] ?? $r->join_date ?? 0),
					];
				}

				$this->id_mapper->set($run_id, $source_system, 'conversation', $cid, null, 'planned', '', [
					'title'              => $this->sanitize_utf8($conv->title),
					'user_source_id'     => $conv->user_source_id,
					'start_date'         => $conv->start_date,
					'first_message_id'   => $conv->first_message_id,
					'last_message_id'    => $conv->last_message_id,
					'recipients'         => $recip_data,
					'target_root_msg_id' => $conv->target_root_msg_id,
				]);

				$results[$cid] = [
					'target_id' => null,
					'status'    => 'success',
					'error'     => null,
				];
			}
			catch (\Throwable $e)
			{
				$results[$cid] = [
					'target_id' => null,
					'status'    => 'failed',
					'error'     => $e->getMessage(),
				];
			}
		}

		return $results;
	}

	/**
	 * Write a batch of private messages
	 *
	 * @param conversation_message_dto[] $messages
	 * @param array $options
	 * @return array
	 */
	public function write_privmsgs(array $messages, array $options = []): array
	{
		$results = [];
		$run_id = (string)($options['run_id'] ?? '');
		$source_system = (string)($options['source_system'] ?? 'xenforo');

		$is_vb = in_array($source_system, ['vbulletin', 'vbulletin3', 'vbulletin4', 'vb3', 'vb4'], true);
		$converter = $is_vb
			? new \phpbbseo\migrationcenter\source\vbulletin\content\vb_message_converter()
			: new \phpbbseo\migrationcenter\source\xenforo\content\xf_message_converter();
		$affected_user_ids = [];

		foreach ($messages as $msg)
		{
			$mid = $msg->source_id;

			try
			{
				// 1. Check Idempotency
				$existing_target_id = $this->id_mapper->get_target_id($source_system, 'privmsg', $mid);
				if ($existing_target_id !== null)
				{
					$sql = 'SELECT msg_id FROM ' . $this->table_prefix . 'privmsgs WHERE msg_id = ' . (int)$existing_target_id;
					$res = $this->db->sql_query($sql);
					$still_exists = $this->db->sql_fetchfield('msg_id');
					$this->db->sql_freeresult($res);

					if ($still_exists)
					{
						$results[$mid] = [
							'target_id' => (int)$existing_target_id,
							'status'    => 'success',
							'error'     => null,
							'note'      => 'already_mapped',
						];
						continue;
					}
				}

				// 2. Resolve Author Target ID
				$author_target_id = $this->id_mapper->get_target_id($source_system, 'user', $msg->user_source_id);
				if (!$author_target_id)
				{
					$results[$mid] = [
						'target_id' => null,
						'status'    => 'skipped',
						'error'     => "Author target mapping not found for source user ID {$msg->user_source_id}",
					];
					continue;
				}
				$author_target_id = (int)$author_target_id;

				// 3. Resolve Conversation Metadata
				$conv_meta = $this->id_mapper->get_metadata($source_system, 'conversation', $msg->conversation_source_id);
				if (empty($conv_meta))
				{
					$results[$mid] = [
						'target_id' => null,
						'status'    => 'skipped',
						'error'     => "Conversation metadata not found for source conversation ID {$msg->conversation_source_id}",
					];
					continue;
				}

				$conv_title = (string)($conv_meta['title'] ?? 'Private Conversation');
				$raw_recipients = (array)($conv_meta['recipients'] ?? []);

				// 4. Resolve Target Recipients (Respect join date boundaries)
				$target_recipients = [];
				foreach ($raw_recipients as $src_uid => $r_info)
				{
					$target_uid = $this->id_mapper->get_target_id($source_system, 'user', (int)$src_uid);
					if (!$target_uid || (int)$target_uid === $author_target_id)
					{
						continue;
					}

					// Check join date boundary: if recipient joined after message_date, omit from this message
					$join_date = (int)($r_info['join_date'] ?? 0);
					if ($join_date > 0 && (int)$msg->message_date < $join_date)
					{
						continue;
					}

					$target_recipients[(int)$target_uid] = $r_info;
				}

				// Ensure at least 1 recipient exists (total participants >= 2)
				if (empty($target_recipients))
				{
					$results[$mid] = [
						'target_id' => null,
						'status'    => 'skipped',
						'error'     => 'Conversation has fewer than two valid mapped participants for this message',
					];
					continue;
				}

				// 5. Root Level Threading
				$root_level = 0;
				if (!empty($conv_meta['target_root_msg_id']))
				{
					$root_level = (int)$conv_meta['target_root_msg_id'];
				}

				// 6. Format Address and Subject
				$to_address = implode(':', array_map(function ($uid) {
					return 'u_' . $uid;
				}, array_keys($target_recipients))) . ':';

				$clean_subject = $this->sanitize_utf8($conv_title);
				if (mb_strlen($clean_subject, 'UTF-8') > 100)
				{
					$clean_subject = mb_substr($clean_subject, 0, 97, 'UTF-8') . '...';
				}

				// 7. Message BBCode Conversion
				$conv_res = $converter->convert($msg->message_text);

				$pm_row = [
					'root_level'          => $root_level,
					'author_id'           => $author_target_id,
					'icon_id'             => 0,
					'author_ip'           => $this->sanitize_utf8((string)($msg->author_ip ?: '127.0.0.1')),
					'message_time'        => (int)($msg->message_date ?: time()),
					'enable_bbcode'       => 1,
					'enable_smilies'      => 1,
					'enable_magic_url'    => 1,
					'enable_sig'          => 1,
					'message_subject'     => $clean_subject,
					'message_text'        => $conv_res->storage_text,
					'message_edit_reason' => '',
					'message_edit_user'   => 0,
					'message_attachment'  => 0,
					'bbcode_bitfield'     => $conv_res->bbcode_bitfield,
					'bbcode_uid'          => $conv_res->bbcode_uid,
					'message_edit_time'   => 0,
					'message_edit_count'  => 0,
					'to_address'          => $to_address,
					'bcc_address'         => '',
					'message_reported'    => 0,
				];

				$sql = 'INSERT INTO ' . $this->table_prefix . 'privmsgs ' . $this->db->sql_build_array('INSERT', $pm_row);
				$this->db->sql_query($sql);
				$target_msg_id = (int)$this->db->sql_nextid();

				// If root_level was 0, this message establishes the conversation root!
				if ($root_level === 0)
				{
					$conv_meta['target_root_msg_id'] = $target_msg_id;
					$this->id_mapper->set($run_id, $source_system, 'conversation', $msg->conversation_source_id, null, 'planned', '', $conv_meta);
				}

				// 8. Insert privmsgs_to rows for recipients and sender
				$pm_to_rows = [];

				// Recipient Rows (Folder = PRIVMSGS_INBOX / 0)
				foreach ($target_recipients as $recip_uid => $r_state)
				{
					$is_deleted = ($r_state['state'] ?? 'active') !== 'active';
					$last_read = (int)($r_state['last_read_date'] ?? 0);
					$is_unread = ($last_read < (int)$msg->message_date) ? 1 : 0;
					$is_marked = !empty($r_state['is_starred']) ? 1 : 0;

					$pm_to_rows[] = [
						'msg_id'       => $target_msg_id,
						'user_id'      => $recip_uid,
						'author_id'    => $author_target_id,
						'pm_deleted'   => $is_deleted ? 1 : 0,
						'pm_new'       => 0, // No misleading new PM popups
						'pm_unread'    => $is_unread,
						'pm_replied'   => 0,
						'pm_marked'    => $is_marked,
						'pm_forwarded' => 0,
						'folder_id'    => 0, // PRIVMSGS_INBOX
					];

					$affected_user_ids[$recip_uid] = true;
				}

				// Sender Row (Folder = PRIVMSGS_SENTBOX / -1)
				$author_src_uid = $msg->user_source_id;
				$author_meta_state = $raw_recipients[$author_src_uid] ?? [];
				$author_deleted = (($author_meta_state['state'] ?? 'active') !== 'active') ? 1 : 0;
				$author_marked = !empty($author_meta_state['is_starred']) ? 1 : 0;

				$pm_to_rows[] = [
					'msg_id'       => $target_msg_id,
					'user_id'      => $author_target_id,
					'author_id'    => $author_target_id,
					'pm_deleted'   => $author_deleted,
					'pm_new'       => 0,
					'pm_unread'    => 0,
					'pm_replied'   => 0,
					'pm_marked'    => $author_marked,
					'pm_forwarded' => 0,
					'folder_id'    => -1, // PRIVMSGS_SENTBOX
				];

				$affected_user_ids[$author_target_id] = true;

				$this->db->sql_multi_insert($this->table_prefix . 'privmsgs_to', $pm_to_rows);

				// 9. Persist message ID mapping
				$this->id_mapper->set($run_id, $source_system, 'privmsg', $mid, $target_msg_id, 'mapped', '', [
					'conversation_source_id' => $msg->conversation_source_id,
					'root_msg_id'            => ($root_level === 0 ? $target_msg_id : $root_level),
					'author_id'              => $author_target_id,
					'message_date'           => $msg->message_date,
				]);

				$results[$mid] = [
					'target_id' => $target_msg_id,
					'status'    => 'success',
					'error'     => null,
				];
			}
			catch (\Throwable $e)
			{
				$results[$mid] = [
					'target_id' => null,
					'status'    => 'failed',
					'error'     => $e->getMessage(),
				];
			}
		}

		// Reconcile unread PM counts for affected target users
		if (!empty($affected_user_ids))
		{
			$in_uids = implode(',', array_keys($affected_user_ids));
			$sql = 'UPDATE ' . $this->table_prefix . 'users u SET
						user_unread_privmsg = (
							SELECT COUNT(*) FROM ' . $this->table_prefix . 'privmsgs_to pt 
							WHERE pt.user_id = u.user_id AND pt.pm_unread = 1 AND pt.pm_deleted = 0 AND pt.folder_id = 0
						),
						user_new_privmsg = 0 
					WHERE u.user_id IN (' . $in_uids . ')';
			$this->db->sql_query($sql);
		}

		return $results;
	}

	/**
	 * Write a batch of XenForo thread polls, options, and votes to phpBB topics
	 *
	 * @param \phpbbseo\migrationcenter\core\dto\poll_dto[] $polls
	 * @param array $options
	 * @return array
	 */
	public function write_polls(array $polls, array $options = []): array
	{
		$results = [];
		$run_id = (string)($options['run_id'] ?? '');
		$source_system = (string)($options['source_system'] ?? 'xenforo');

		foreach ($polls as $poll)
		{
			$pid = $poll->source_id;

			try
			{
				// 1. Check Idempotency
				$existing_target_id = $this->id_mapper->get_target_id($source_system, 'poll', $pid);
				if ($existing_target_id !== null)
				{
					$sql = 'SELECT topic_id, poll_title FROM ' . $this->table_prefix . 'topics WHERE topic_id = ' . (int)$existing_target_id;
					$res = $this->db->sql_query($sql);
					$existing_row = $this->db->sql_fetchrow($res);
					$this->db->sql_freeresult($res);

					if ($existing_row && !empty($existing_row['poll_title']))
					{
						$results[$pid] = [
							'target_id' => (int)$existing_target_id,
							'status'    => 'success',
							'error'     => null,
							'note'      => 'already_mapped',
						];
						continue;
					}
				}

				// 2. Resolve Target Topic ID
				$topic_target_id = $this->id_mapper->get_target_id($source_system, 'topic', $poll->thread_source_id);
				if (!$topic_target_id)
				{
					$results[$pid] = [
						'target_id' => null,
						'status'    => 'skipped',
						'error'     => "Target topic mapping not found for source thread ID {$poll->thread_source_id}",
					];
					continue;
				}
				$topic_target_id = (int)$topic_target_id;

				$sql = 'SELECT topic_id, topic_time, topic_poster FROM ' . $this->table_prefix . 'topics WHERE topic_id = ' . $topic_target_id;
				$res = $this->db->sql_query($sql);
				$topic_row = $this->db->sql_fetchrow($res);
				$this->db->sql_freeresult($res);

				if (!$topic_row)
				{
					$results[$pid] = [
						'target_id' => null,
						'status'    => 'skipped',
						'error'     => "Target topic row {$topic_target_id} not found in database",
					];
					continue;
				}

				// 3. Format Poll Title & Calculation of Dates
				$clean_title = $this->sanitize_utf8($poll->question ?: "Poll #{$pid}");
				if (mb_strlen($clean_title, 'UTF-8') > 255)
				{
					$clean_title = mb_substr($clean_title, 0, 252, 'UTF-8') . '...';
				}

				$poll_start = (int)($poll->start_date ?: ($topic_row['topic_time'] ?: time()));
				$poll_length = 0;

				if ($poll->close_date > 0)
				{
					if ($poll->close_date > $poll_start)
					{
						$poll_length = (int)($poll->close_date - $poll_start);
					}
					else
					{
						// Already expired in source: ensure poll_start + poll_length < current time so it remains closed
						$poll_length = 1;
						$poll_start = (int)$poll->close_date - 1;
					}
				}

				$num_options = count($poll->responses);
				$max_options = max(1, min($num_options, (int)$poll->max_votes));
				$vote_change = !empty($poll->change_vote) ? 1 : 0;

				// 4. Insert Poll Options into phpbb_poll_options
				// Clean up any existing options for this topic for clean idempotency
				$db_clean_opt = 'DELETE FROM ' . $this->table_prefix . 'poll_options WHERE topic_id = ' . $topic_target_id;
				$this->db->sql_query($db_clean_opt);

				$option_id_map = []; // source_response_id -> target_poll_option_id
				$option_order = 1;

				foreach ($poll->responses as $opt)
				{
					$opt_id = $option_order++;
					$opt_text = $this->sanitize_utf8($opt->option_text ?: "Option {$opt_id}");

					$opt_row = [
						'poll_option_id'    => $opt_id,
						'topic_id'          => $topic_target_id,
						'poll_option_text'  => $opt_text,
						'poll_option_total' => 0,
					];

					$sql_opt = 'INSERT INTO ' . $this->table_prefix . 'poll_options ' . $this->db->sql_build_array('INSERT', $opt_row);
					$this->db->sql_query($sql_opt);

					$option_id_map[$opt->source_id] = $opt_id;

					// Persist option mapping
					$this->id_mapper->set($run_id, $source_system, 'poll_option', $opt->source_id, $opt_id, 'mapped', '', [
						'topic_id'    => $topic_target_id,
						'poll_id'     => $pid,
						'option_text' => $opt_text,
					]);
				}

				// 5. Insert Poll Votes into phpbb_poll_votes
				$db_clean_votes = 'DELETE FROM ' . $this->table_prefix . 'poll_votes WHERE topic_id = ' . $topic_target_id;
				$this->db->sql_query($db_clean_votes);

				$reconciled_option_totals = [];
				$user_voted_options = [];
				$poll_last_vote = 0;
				$valid_votes_inserted = 0;

				foreach ($poll->votes as $v)
				{
					$target_voter_id = $this->id_mapper->get_target_id($source_system, 'user', $v->user_source_id);
					if (!$target_voter_id)
					{
						continue; // Skip vote if user not mapped
					}
					$target_voter_id = (int)$target_voter_id;

					$target_opt_id = $option_id_map[$v->response_source_id] ?? null;
					if (!$target_opt_id)
					{
						continue;
					}

					// Deduplicate user-option vote pair
					$pair_key = "{$target_voter_id}_{$target_opt_id}";
					if (isset($user_voted_options[$pair_key]))
					{
						continue;
					}
					$user_voted_options[$pair_key] = true;

					$vote_row = [
						'topic_id'       => $topic_target_id,
						'poll_option_id' => $target_opt_id,
						'vote_user_id'   => $target_voter_id,
						'vote_user_ip'   => '127.0.0.1',
					];

					$sql_vote = 'INSERT INTO ' . $this->table_prefix . 'poll_votes ' . $this->db->sql_build_array('INSERT', $vote_row);
					$this->db->sql_query($sql_vote);
					$valid_votes_inserted++;

					$reconciled_option_totals[$target_opt_id] = ($reconciled_option_totals[$target_opt_id] ?? 0) + 1;
					$poll_last_vote = max($poll_last_vote, (int)$v->vote_date);
				}

				// 6. Update Reconciled Option Totals in phpbb_poll_options
				foreach ($option_id_map as $src_resp_id => $tgt_opt_id)
				{
					$tot = (int)($reconciled_option_totals[$tgt_opt_id] ?? 0);
					$sql_up_opt = 'UPDATE ' . $this->table_prefix . 'poll_options 
								   SET poll_option_total = ' . $tot . ' 
								   WHERE topic_id = ' . $topic_target_id . ' AND poll_option_id = ' . $tgt_opt_id;
					$this->db->sql_query($sql_up_opt);
				}

				// 7. Update Poll Fields on Target Topic
				$sql_up_topic = 'UPDATE ' . $this->table_prefix . "topics SET 
									poll_title = '" . $this->db->sql_escape($clean_title) . "',
									poll_start = " . (int)$poll_start . ',
									poll_length = ' . (int)$poll_length . ',
									poll_max_options = ' . (int)$max_options . ',
									poll_last_vote = ' . (int)$poll_last_vote . ',
									poll_vote_change = ' . (int)$vote_change . ' 
								 WHERE topic_id = ' . (int)$topic_target_id;
				$this->db->sql_query($sql_up_topic);

				// 8. Persist Poll Master ID mapping
				$this->id_mapper->set($run_id, $source_system, 'poll', $pid, $topic_target_id, 'mapped', '', [
					'topic_id'       => $topic_target_id,
					'question'       => $clean_title,
					'options_count'  => $num_options,
					'votes_inserted' => $valid_votes_inserted,
					'poll_start'     => $poll_start,
					'poll_length'    => $poll_length,
				]);

				$results[$pid] = [
					'target_id' => $topic_target_id,
					'status'    => 'success',
					'error'     => null,
				];
			}
			catch (\Throwable $e)
			{
				$results[$pid] = [
					'target_id' => null,
					'status'    => 'failed',
					'error'     => $e->getMessage(),
				];
			}
		}

		return $results;
	}

	/**
	 * Write a batch of XenForo bans (user, email, IP) to phpBB banlist
	 *
	 * @param \phpbbseo\migrationcenter\core\dto\ban_dto[] $bans
	 * @param array $options
	 * @return array
	 */
	public function write_bans(array $bans, array $options = []): array
	{
		$results = [];
		$run_id = (string)($options['run_id'] ?? '');
		$source_system = (string)($options['source_system'] ?? 'xenforo');
		$expired_policy = (string)($options['expired_ban_policy'] ?? 'skip');
		$existing_user_policy = (string)($options['existing_user_policy'] ?? 'preserve_target');
		$now = time();

		foreach ($bans as $ban)
		{
			$bid = $ban->source_id;

			try
			{
				// 1. User Bans
				if ($ban->ban_type === 'user')
				{
					$target_user_id = $this->id_mapper->get_target_id($source_system, 'user', $ban->user_source_id);
					if (!$target_user_id)
					{
						$results[$bid] = [
							'target_id' => null,
							'status'    => 'skipped',
							'error'     => "Target user mapping not found for source user ID {$ban->user_source_id}",
						];
						continue;
					}
					$target_user_id = (int)$target_user_id;

					// Protection Rules:
					// A. Never ban Anonymous (ID 1)
					if ($target_user_id === 1)
					{
						$results[$bid] = [
							'target_id' => null,
							'status'    => 'skipped',
							'note'      => 'protected_anonymous',
							'error'     => 'Anonymous user is strictly protected from bans',
						];
						continue;
					}

					// B. Check user info for Founder / Protected Admins
					$sql = 'SELECT user_id, user_type, username FROM ' . $this->table_prefix . 'users WHERE user_id = ' . $target_user_id;
					$res = $this->db->sql_query($sql);
					$user_row = $this->db->sql_fetchrow($res);
					$this->db->sql_freeresult($res);

					if (!$user_row)
					{
						$results[$bid] = [
							'target_id' => null,
							'status'    => 'skipped',
							'error'     => "Target user row {$target_user_id} not found in database",
						];
						continue;
					}

					// Founder protection (user_type = 3)
					if ((int)$user_row['user_type'] === 3)
					{
						$results[$bid] = [
							'target_id' => null,
							'status'    => 'skipped',
							'note'      => 'protected_founder',
							'error'     => "Founder user {$user_row['username']} (ID: {$target_user_id}) is protected from bans",
						];
						continue;
					}

					// Check pre-existing target user policy
					if ($existing_user_policy === 'preserve_target')
					{
						// If target user was created outside this migration run (e.g. pre-existing admin ID 2)
						if ($target_user_id === 2)
						{
							$results[$bid] = [
								'target_id' => null,
								'status'    => 'skipped',
								'note'      => 'protected_admin',
								'error'     => 'Pre-existing admin user is protected from bans',
							];
							continue;
						}
					}

					// C. Expiration check
					if ($ban->ban_end > 0 && $ban->ban_end <= $now)
					{
						if ($expired_policy === 'skip')
						{
							$results[$bid] = [
								'target_id' => null,
								'status'    => 'skipped',
								'note'      => 'expired_ban',
								'error'     => null,
							];
							continue;
						}
					}

					// D. Duplicate check in phpbb_banlist
					$sql = 'SELECT ban_id FROM ' . $this->table_prefix . 'banlist WHERE ban_userid = ' . $target_user_id;
					$res = $this->db->sql_query($sql);
					$existing_ban_id = (int)$this->db->sql_fetchfield('ban_id');
					$this->db->sql_freeresult($res);

					if ($existing_ban_id > 0)
					{
						$this->id_mapper->set($run_id, $source_system, 'ban', $bid, $existing_ban_id, 'mapped', '', [
							'ban_type' => 'user',
							'target_user_id' => $target_user_id,
						]);

						$results[$bid] = [
							'target_id' => $existing_ban_id,
							'status'    => 'success',
							'note'      => 'already_mapped',
							'error'     => null,
						];
						continue;
					}

					// Insert ban row
					$ban_row = [
						'ban_userid'      => $target_user_id,
						'ban_ip'          => '',
						'ban_email'       => '',
						'ban_start'       => $ban->ban_start ?: $now,
						'ban_end'         => (int)$ban->ban_end,
						'ban_exclude'     => 0,
						'ban_reason'      => $this->sanitize_utf8($ban->ban_reason ?: 'Imported user ban'),
						'ban_give_reason' => $this->sanitize_utf8($ban->ban_give_reason),
					];

					$sql_ins = 'INSERT INTO ' . $this->table_prefix . 'banlist ' . $this->db->sql_build_array('INSERT', $ban_row);
					$this->db->sql_query($sql_ins);
					$ban_target_id = (int)$this->db->sql_nextid();

					$this->id_mapper->set($run_id, $source_system, 'ban', $bid, $ban_target_id, 'mapped', '', [
						'ban_type'       => 'user',
						'target_user_id' => $target_user_id,
						'ban_start'      => $ban->ban_start,
						'ban_end'        => $ban->ban_end,
					]);

					$results[$bid] = [
						'target_id' => $ban_target_id,
						'status'    => 'success',
						'error'     => null,
					];
				}

				// 2. Email Bans
				else if ($ban->ban_type === 'email')
				{
					$clean_email = strtolower(trim($ban->ban_email));
					if ($clean_email === '')
					{
						$results[$bid] = [
							'target_id' => null,
							'status'    => 'skipped',
							'error'     => 'Empty email ban pattern',
						];
						continue;
					}

					// Check regex / unsupported syntax
					if (strpos($clean_email, '/') !== false || strpos($clean_email, '^') !== false || strpos($clean_email, '$') !== false)
					{
						$results[$bid] = [
							'target_id' => null,
							'status'    => 'skipped',
							'note'      => 'unsupported_regex_pattern',
							'error'     => 'Regex email ban pattern cannot be translated without broadening',
						];
						continue;
					}

					// Check existing
					$sql = 'SELECT ban_id FROM ' . $this->table_prefix . "banlist WHERE ban_email = '" . $this->db->sql_escape($clean_email) . "'";
					$res = $this->db->sql_query($sql);
					$existing_ban_id = (int)$this->db->sql_fetchfield('ban_id');
					$this->db->sql_freeresult($res);

					if ($existing_ban_id > 0)
					{
						$results[$bid] = [
							'target_id' => $existing_ban_id,
							'status'    => 'success',
							'note'      => 'already_mapped',
							'error'     => null,
						];
						continue;
					}

					$ban_row = [
						'ban_userid'      => 0,
						'ban_ip'          => '',
						'ban_email'       => $clean_email,
						'ban_start'       => $ban->ban_start ?: $now,
						'ban_end'         => (int)$ban->ban_end,
						'ban_exclude'     => 0,
						'ban_reason'      => $this->sanitize_utf8($ban->ban_reason ?: 'Imported email ban'),
						'ban_give_reason' => $this->sanitize_utf8($ban->ban_give_reason),
					];

					$sql_ins = 'INSERT INTO ' . $this->table_prefix . 'banlist ' . $this->db->sql_build_array('INSERT', $ban_row);
					$this->db->sql_query($sql_ins);
					$ban_target_id = (int)$this->db->sql_nextid();

					$this->id_mapper->set($run_id, $source_system, 'ban', $bid, $ban_target_id, 'mapped', '', [
						'ban_type'  => 'email',
						'ban_email' => $clean_email,
					]);

					$results[$bid] = [
						'target_id' => $ban_target_id,
						'status'    => 'success',
						'error'     => null,
					];
				}

				// 3. IP Bans
				else if ($ban->ban_type === 'ip')
				{
					$clean_ip = trim($ban->ban_ip);
					if ($clean_ip === '')
					{
						$results[$bid] = [
							'target_id' => null,
							'status'    => 'skipped',
							'error'     => 'Empty IP ban rule',
						];
						continue;
					}

					// Protect localhost / current server IP
					if ($clean_ip === '127.0.0.1' || $clean_ip === '::1' || strtolower($clean_ip) === 'localhost')
					{
						$results[$bid] = [
							'target_id' => null,
							'status'    => 'skipped',
							'note'      => 'protected_localhost',
							'error'     => 'Localhost IP is protected from banlist',
						];
						continue;
					}

					// CIDR notation check (e.g. 192.168.1.0/24)
					if (strpos($clean_ip, '/') !== false)
					{
						$results[$bid] = [
							'target_id' => null,
							'status'    => 'skipped',
							'note'      => 'incompatible_cidr_range',
							'error'     => 'CIDR range IP ban cannot be translated to phpBB without broadening',
						];
						continue;
					}

					// Check existing
					$sql = 'SELECT ban_id FROM ' . $this->table_prefix . "banlist WHERE ban_ip = '" . $this->db->sql_escape($clean_ip) . "'";
					$res = $this->db->sql_query($sql);
					$existing_ban_id = (int)$this->db->sql_fetchfield('ban_id');
					$this->db->sql_freeresult($res);

					if ($existing_ban_id > 0)
					{
						$results[$bid] = [
							'target_id' => $existing_ban_id,
							'status'    => 'success',
							'note'      => 'already_mapped',
							'error'     => null,
						];
						continue;
					}

					$ban_row = [
						'ban_userid'      => 0,
						'ban_ip'          => $clean_ip,
						'ban_email'       => '',
						'ban_start'       => $ban->ban_start ?: $now,
						'ban_end'         => (int)$ban->ban_end,
						'ban_exclude'     => 0,
						'ban_reason'      => $this->sanitize_utf8($ban->ban_reason ?: 'Imported IP ban'),
						'ban_give_reason' => $this->sanitize_utf8($ban->ban_give_reason),
					];

					$sql_ins = 'INSERT INTO ' . $this->table_prefix . 'banlist ' . $this->db->sql_build_array('INSERT', $ban_row);
					$this->db->sql_query($sql_ins);
					$ban_target_id = (int)$this->db->sql_nextid();

					$this->id_mapper->set($run_id, $source_system, 'ban', $bid, $ban_target_id, 'mapped', '', [
						'ban_type' => 'ip',
						'ban_ip'   => $clean_ip,
					]);

					$results[$bid] = [
						'target_id' => $ban_target_id,
						'status'    => 'success',
						'error'     => null,
					];
				}
			}
			catch (\Throwable $e)
			{
				$results[$bid] = [
					'target_id' => null,
					'status'    => 'failed',
					'error'     => $e->getMessage(),
				];
			}
		}

		return $results;
	}

	/**
	 * Sanitize UTF-8 text, safely encoding 4-byte astral emojis as HTML numeric character references
	 *
	 * @param string $str
	 * @return string
	 */
	public function sanitize_utf8(string $str): string
	{
		$str = (string)mb_convert_encoding($str, 'UTF-8', 'UTF-8');
		return (string)preg_replace_callback('/[\x{10000}-\x{10FFFF}]/u', function ($m) {
			return '&#x' . dechex(mb_ord($m[0], 'UTF-8')) . ';';
		}, $str);
	}
}
