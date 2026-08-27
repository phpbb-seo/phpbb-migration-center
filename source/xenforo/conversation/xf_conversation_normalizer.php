<?php
/**
 * phpBB Migration Center Extension
 *
 * @copyright (c) 2026 phpBB SEO Team
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace phpbbseo\migrationcenter\source\xenforo\conversation;

use phpbbseo\migrationcenter\core\dto\conversation_dto;
use phpbbseo\migrationcenter\core\dto\conversation_recipient_dto;
use phpbbseo\migrationcenter\core\dto\migration_config_dto;

/**
 * XenForo Conversation Normalizer
 */
class xf_conversation_normalizer
{
	/**
	 * Normalize a conversation master row and recipient rows into ConversationDto
	 *
	 * @param array $master_row
	 * @param array $recipient_rows List of xf_conversation_recipient rows
	 * @param array $user_rows List of xf_conversation_user rows keyed by owner_user_id
	 * @param migration_config_dto $config
	 * @return conversation_dto
	 */
	public function normalize_conversation(
		array $master_row,
		array $recipient_rows,
		array $user_rows,
		migration_config_dto $config
	): conversation_dto {
		$dto = new conversation_dto();
		$dto->source_id = (int)$master_row['conversation_id'];
		$dto->title = trim((string)($master_row['title'] ?? 'Private Conversation'));
		if ($dto->title === '')
		{
			$dto->title = 'Private Conversation';
		}
		$dto->user_source_id = (int)($master_row['user_id'] ?? 0);
		$dto->username = (string)($master_row['username'] ?? '');
		$dto->start_date = (int)($master_row['start_date'] ?? time());
		$dto->open_invite = !empty($master_row['open_invite']);
		$dto->conversation_open = !empty($master_row['conversation_open']);
		$dto->reply_count = (int)($master_row['reply_count'] ?? 0);
		$dto->recipient_count = (int)($master_row['recipient_count'] ?? 0);
		$dto->first_message_id = (int)($master_row['first_message_id'] ?? 0);
		$dto->last_message_date = (int)($master_row['last_message_date'] ?? $dto->start_date);
		$dto->last_message_id = (int)($master_row['last_message_id'] ?? 0);
		$dto->last_message_user_id = (int)($master_row['last_message_user_id'] ?? 0);
		$dto->raw_source_data = $master_row;

		// Map recipients and merge per-user state (starred, unread)
		$recipients = [];
		foreach ($recipient_rows as $r)
		{
			$uid = (int)$r['user_id'];
			$recip = new conversation_recipient_dto();
			$recip->user_source_id = $uid;
			$recip->recipient_state = (string)($r['recipient_state'] ?? 'active');
			$recip->last_read_date = (int)($r['last_read_date'] ?? 0);

			if (isset($user_rows[$uid]))
			{
				$recip->is_starred = !empty($user_rows[$uid]['is_starred']);
				$recip->is_unread = !empty($user_rows[$uid]['is_unread']);
			}

			$recipients[$uid] = $recip;
		}

		$dto->recipients = $recipients;

		return $dto;
	}
}
