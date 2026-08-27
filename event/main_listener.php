<?php
/**
 * phpBB Migration Center Extension
 *
 * @copyright (c) 2026 phpBB SEO Team
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace phpbbseo\migrationcenter\event;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Event listener for privacy enforcement and migration hooks
 */
class main_listener implements EventSubscriberInterface
{
	/** @var \phpbb\db\driver\driver_interface */
	protected $db;

	/** @var string */
	protected $table_prefix;

	/**
	 * Constructor
	 *
	 * @param \phpbb\db\driver\driver_interface $db
	 * @param string $table_prefix
	 */
	public function __construct(\phpbb\db\driver\driver_interface $db, string $table_prefix)
	{
		$this->db = $db;
		$this->table_prefix = $table_prefix;
	}

	/**
	 * Assign functions in this class to phpBB core events
	 *
	 * @return array
	 */
	public static function getSubscribedEvents()
	{
		return [
			'core.modify_pm_attach_download_auth' => 'modify_pm_attach_download_auth',
		];
	}

	/**
	 * Enforce strict private message attachment privacy:
	 * - Only users with an active (pm_deleted = 0) copy in privmsgs_to may download
	 * - Deleted recipients (pm_deleted = 1) or deleted senders cannot download
	 * - Non-participants, late-joiners without access to earlier messages cannot download
	 *
	 * @param \phpbb\event\data $event
	 */
	public function modify_pm_attach_download_auth($event)
	{
		$allowed = $event['allowed'];
		$msg_id = (int)$event['msg_id'];
		$user_id = (int)$event['user_id'];

		if ($allowed)
		{
			// 1. Verify if this PM is owned/imported by Migration Center
			$sql = 'SELECT target_id FROM ' . $this->table_prefix . "migration_id_map 
					WHERE content_type IN ('privmsg', 'conversation_message') AND target_id = '" . $this->db->sql_escape((string)$msg_id) . "'";
			$res = $this->db->sql_query_limit($sql, 1);
			$is_migration_owned = (bool)$this->db->sql_fetchfield('target_id');
			$this->db->sql_freeresult($res);

			// If this is a native non-migration PM, leave native phpBB authorization untouched
			if (!$is_migration_owned)
			{
				return;
			}

			// 2. Stale mapping check: Verify target PM message actually exists in phpbb_privmsgs
			$sql = 'SELECT msg_id FROM ' . $this->table_prefix . 'privmsgs WHERE msg_id = ' . $msg_id;
			$res = $this->db->sql_query_limit($sql, 1);
			$msg_exists = (bool)$this->db->sql_fetchfield('msg_id');
			$this->db->sql_freeresult($res);

			if (!$msg_exists)
			{
				$event['allowed'] = false;
				return;
			}

			// 3. Strict Privacy Guard for migration-owned PM attachments:
			// Requester must have an active (pm_deleted = 0) copy in privmsgs_to for this specific message.
			$sql = 'SELECT msg_id FROM ' . $this->table_prefix . 'privmsgs_to 
					WHERE msg_id = ' . $msg_id . ' 
						AND user_id = ' . $user_id . ' 
						AND pm_deleted = 0';
			$result = $this->db->sql_query_limit($sql, 1);
			$has_active_copy = (bool)$this->db->sql_fetchfield('msg_id');
			$this->db->sql_freeresult($result);

			if (!$has_active_copy)
			{
				$event['allowed'] = false;
			}
		}
	}
}
