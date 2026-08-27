<?php
/**
 * phpBB Migration Center Extension
 *
 * @copyright (c) 2026 phpBB SEO Team
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace phpbbseo\migrationcenter\source\xenforo\permission;

/**
 * XenForo to phpBB Permission Capability Matrix
 */
class xf_permission_matrix
{
	/**
	 * Returns the comprehensive mapping table between XenForo permissions and phpBB ACL options
	 *
	 * @return array
	 */
	public static function get_matrix(): array
	{
		return [
			// ==========================================
			// 1. GENERAL & PROFILE PERMISSIONS (Global)
			// ==========================================
			'general.viewProfile' => [
				'phpbb_option' => 'u_viewprofile',
				'scope'        => 'global',
				'confidence'   => 'exact',
				'notes'        => 'Permission to view user profile details and member info.',
			],
			'general.viewMemberList' => [
				'phpbb_option' => 'u_viewprofile',
				'scope'        => 'global',
				'confidence'   => 'reduced',
				'notes'        => 'phpBB combines memberlist viewing under u_viewprofile capability.',
			],
			'general.search' => [
				'phpbb_option' => 'u_search',
				'scope'        => 'global',
				'confidence'   => 'exact',
				'notes'        => 'Permission to execute forum searches.',
			],
			'general.editBasicProfile' => [
				'phpbb_option' => 'u_chgprofileinfo',
				'scope'        => 'global',
				'confidence'   => 'exact',
				'notes'        => 'Permission to modify own basic profile information.',
			],
			'general.bypassFloodCheck' => [
				'phpbb_option' => 'u_ignoreflood',
				'scope'        => 'global',
				'confidence'   => 'exact',
				'notes'        => 'Bypass rate limit / flood control delay.',
			],
			'general.bypassUserPrivacy' => [
				'phpbb_option' => 'u_hideonline',
				'scope'        => 'reduced',
				'confidence'   => 'reduced',
				'notes'        => 'Viewing hidden user states / bypassing privacy preferences.',
			],

			// ==========================================
			// 2. CONVERSATION / PRIVATE MESSAGING (Global)
			// ==========================================
			'conversation.start' => [
				'phpbb_option' => 'u_sendpm',
				'scope'        => 'global',
				'confidence'   => 'exact',
				'notes'        => 'Permission to create new direct/private conversations.',
			],
			'conversation.reply' => [
				'phpbb_option' => 'u_readpm',
				'scope'        => 'global',
				'confidence'   => 'exact',
				'notes'        => 'Permission to read and reply to direct messages.',
			],
			'conversation.uploadAttachment' => [
				'phpbb_option' => 'u_pm_attach',
				'scope'        => 'global',
				'confidence'   => 'exact',
				'notes'        => 'Permission to attach files inside PMs.',
			],
			'conversation.editOwnMessage' => [
				'phpbb_option' => 'u_pm_edit',
				'scope'        => 'global',
				'confidence'   => 'exact',
				'notes'        => 'Permission to edit own PM contents.',
			],
			'conversation.maxRecipients' => [
				'phpbb_option' => 'u_masspm',
				'scope'        => 'global',
				'confidence'   => 'reduced',
				'notes'        => 'Integer limit on conversation recipients translated to mass PM capability.',
			],

			// ==========================================
			// 3. AVATARS & SIGNATURES (Global)
			// ==========================================
			'avatar.allowed' => [
				'phpbb_option' => 'u_chgavatar',
				'scope'        => 'global',
				'confidence'   => 'exact',
				'notes'        => 'Permission to upload/change user avatar.',
			],
			'signature.basic' => [
				'phpbb_option' => 'u_sig',
				'scope'        => 'global',
				'confidence'   => 'exact',
				'notes'        => 'Permission to define user signature.',
			],
			'signature.maxLines' => [
				'phpbb_option' => null,
				'scope'        => 'global',
				'confidence'   => 'unsupported',
				'notes'        => 'phpBB manages signature line limits via board-level settings, not group ACL.',
			],

			// ==========================================
			// 4. GLOBAL MODERATION PERMISSIONS (Global)
			// ==========================================
			'general.banUser' => [
				'phpbb_option' => 'm_ban',
				'scope'        => 'global',
				'confidence'   => 'exact',
				'notes'        => 'Global moderator capability to ban user accounts.',
			],
			'general.viewIps' => [
				'phpbb_option' => 'm_info',
				'scope'        => 'global',
				'confidence'   => 'exact',
				'notes'        => 'Global moderator capability to inspect user IP logs and information.',
			],
			'general.warn' => [
				'phpbb_option' => 'm_warn',
				'scope'        => 'global',
				'confidence'   => 'exact',
				'notes'        => 'Capability to issue formal moderator warnings.',
			],
			'general.manageWarning' => [
				'phpbb_option' => 'm_warn',
				'scope'        => 'global',
				'confidence'   => 'exact',
				'notes'        => 'Manage and delete moderator warnings.',
			],
			'general.cleanSpam' => [
				'phpbb_option' => 'm_delete',
				'scope'        => 'reduced',
				'confidence'   => 'reduced',
				'notes'        => 'Mass spam cleaning mapped to moderation deletion capability.',
			],
			'forum.approveUnapprove' => [
				'phpbb_option' => 'm_approve',
				'scope'        => 'global',
				'confidence'   => 'exact',
				'notes'        => 'Global approval/unapproval of unmoderated queue.',
			],
			'forum.deleteAnyPost' => [
				'phpbb_option' => 'm_delete',
				'scope'        => 'global',
				'confidence'   => 'exact',
				'notes'        => 'Global capability to delete any post.',
			],
			'forum.editAnyPost' => [
				'phpbb_option' => 'm_edit',
				'scope'        => 'global',
				'confidence'   => 'exact',
				'notes'        => 'Global capability to edit any post.',
			],
			'forum.lockUnlockThread' => [
				'phpbb_option' => 'm_lock',
				'scope'        => 'global',
				'confidence'   => 'exact',
				'notes'        => 'Global capability to lock or unlock threads.',
			],
			'forum.stickUnstickThread' => [
				'phpbb_option' => 'f_sticky',
				'scope'        => 'global',
				'confidence'   => 'reduced',
				'notes'        => 'Sticky capability in global moderation.',
			],

			// ==========================================
			// 5. GLOBAL ADMINISTRATIVE PERMISSIONS (Global)
			// ==========================================
			'admin.view' => [
				'phpbb_option' => null,
				'scope'        => 'global',
				'confidence'   => 'unsupported',
				'notes'        => 'Security Policy: phpBB root wildcard a_ is not granted to individual permissions.',
			],
			'admin.user' => [
				'phpbb_option' => 'a_user',
				'scope'        => 'global',
				'confidence'   => 'reduced',
				'notes'        => 'Manage user accounts in ACP (reduced fidelity due to scope differences).',
			],
			'admin.node' => [
				'phpbb_option' => 'a_forum',
				'scope'        => 'global',
				'confidence'   => 'reduced',
				'notes'        => 'Manage forums and categories in ACP (reduced fidelity due to scope differences).',
			],
			'admin.permission' => [
				'phpbb_option' => 'a_authgroups',
				'scope'        => 'global',
				'confidence'   => 'reduced',
				'notes'        => 'Manage permissions in ACP (reduced fidelity due to scope differences).',
			],

			// ==========================================
			// 6. NODE / FORUM LOCAL PERMISSIONS (Deferred to Phase 4B)
			// ==========================================
			'forum.viewNode' => [
				'phpbb_option' => 'f_list',
				'scope'        => 'forum',
				'confidence'   => 'exact',
				'notes'        => 'Forum-level listing visibility (Deferred to Phase 4B forum mapping).',
			],
			'forum.viewContent' => [
				'phpbb_option' => 'f_read',
				'scope'        => 'forum',
				'confidence'   => 'exact',
				'notes'        => 'Forum-level topic reading access (Deferred to Phase 4B).',
			],
			'forum.postThread' => [
				'phpbb_option' => 'f_post',
				'scope'        => 'forum',
				'confidence'   => 'exact',
				'notes'        => 'Forum-level new topic creation (Deferred to Phase 4B).',
			],
			'forum.postReply' => [
				'phpbb_option' => 'f_reply',
				'scope'        => 'forum',
				'confidence'   => 'exact',
				'notes'        => 'Forum-level reply posting (Deferred to Phase 4B).',
			],
			'forum.uploadAttachment' => [
				'phpbb_option' => 'f_attach',
				'scope'        => 'forum',
				'confidence'   => 'exact',
				'notes'        => 'Forum-level attachment upload (Deferred to Phase 4B).',
			],
		];
	}
}
