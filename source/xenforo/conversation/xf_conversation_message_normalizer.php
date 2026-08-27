<?php
/**
 * phpBB Migration Center Extension
 *
 * @copyright (c) 2026 phpBB SEO Team
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace phpbbseo\migrationcenter\source\xenforo\conversation;

use phpbbseo\migrationcenter\core\dto\conversation_message_dto;
use phpbbseo\migrationcenter\core\dto\migration_config_dto;

/**
 * XenForo Conversation Message Normalizer
 */
class xf_conversation_message_normalizer
{
	/**
	 * Normalize a conversation message row into ConversationMessageDto
	 *
	 * @param array $row
	 * @param migration_config_dto $config
	 * @param string $ip_address Optional resolved author IP
	 * @return conversation_message_dto
	 */
	public function normalize_message(array $row, migration_config_dto $config, string $ip_address = ''): conversation_message_dto
	{
		$dto = new conversation_message_dto();
		$dto->source_id = (int)$row['message_id'];
		$dto->conversation_source_id = (int)$row['conversation_id'];
		$dto->message_date = (int)($row['message_date'] ?? time());
		$dto->user_source_id = (int)($row['user_id'] ?? 0);
		$dto->username = (string)($row['username'] ?? '');
		$dto->message_text = (string)($row['message'] ?? '');
		$dto->attach_count = (int)($row['attach_count'] ?? 0);
		$dto->author_ip = $ip_address ?: '127.0.0.1';
		$dto->raw_source_data = $row;

		return $dto;
	}
}
