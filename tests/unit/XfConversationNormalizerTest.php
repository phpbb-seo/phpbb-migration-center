<?php
/**
 * XenForo Conversation & Message Normalizer Unit Test
 */

namespace phpbbseo\migrationcenter\tests\unit;

use phpbbseo\migrationcenter\core\dto\migration_config_dto;
use phpbbseo\migrationcenter\source\xenforo\conversation\xf_conversation_normalizer;
use phpbbseo\migrationcenter\source\xenforo\conversation\xf_conversation_message_normalizer;

class XfConversationNormalizerTest
{
	public function run()
	{
		$config = new migration_config_dto();
		$conv_normalizer = new xf_conversation_normalizer();
		$msg_normalizer = new xf_conversation_message_normalizer();

		// Master row
		$master_row = [
			'conversation_id'     => 101,
			'title'               => "XXXXXY XXXXY XX UnicodeRunner\xE2\x80\x8CXXX X Emoji 🚀",
			'user_id'             => 5,
			'username'            => 'SenderUser',
			'start_date'          => 1785000000,
			'open_invite'         => 0,
			'conversation_open'   => 1,
			'reply_count'         => 2,
			'recipient_count'     => 2,
			'first_message_id'    => 501,
			'last_message_date'   => 1785000200,
			'last_message_id'     => 503,
			'last_message_user_id'=> 6,
		];

		// Recipients
		$recipient_rows = [
			[
				'conversation_id' => 101,
				'user_id'         => 5,
				'recipient_state' => 'active',
				'last_read_date'  => 1785000200,
			],
			[
				'conversation_id' => 101,
				'user_id'         => 6,
				'recipient_state' => 'deleted',
				'last_read_date'  => 1785000100,
			],
		];

		// Per-user state
		$user_rows = [
			5 => [
				'conversation_id' => 101,
				'owner_user_id'   => 5,
				'is_unread'       => 0,
				'is_starred'      => 1,
			],
			6 => [
				'conversation_id' => 101,
				'owner_user_id'   => 6,
				'is_unread'       => 1,
				'is_starred'      => 0,
			],
		];

		// Test 1: Normalize Conversation Master
		$dto = $conv_normalizer->normalize_conversation($master_row, $recipient_rows, $user_rows, $config);

		if ($dto->source_id !== 101 || $dto->user_source_id !== 5)
		{
			throw new \Exception("Conversation master normalization failed");
		}
		if (count($dto->recipients) !== 2)
		{
			throw new \Exception("Expected 2 recipients");
		}
		if (!$dto->recipients[5]->is_starred || $dto->recipients[6]->recipient_state !== 'deleted')
		{
			throw new \Exception("Recipient state mapping failed");
		}

		// Test 2: Normalize Conversation Message
		$msg_row = [
			'message_id'      => 501,
			'conversation_id' => 101,
			'message_date'    => 1785000000,
			'user_id'         => 5,
			'username'        => 'SenderUser',
			'message'         => '[b]Hello![/b] [attach]8001[/attach]',
			'attach_count'    => 1,
			'ip_id'           => 12,
		];

		$msg_dto = $msg_normalizer->normalize_message($msg_row, $config, '192.168.1.100');
		if ($msg_dto->source_id !== 501 || $msg_dto->author_ip !== '192.168.1.100' || $msg_dto->attach_count !== 1)
		{
			throw new \Exception("Conversation message normalization failed");
		}

		return true;
	}
}
