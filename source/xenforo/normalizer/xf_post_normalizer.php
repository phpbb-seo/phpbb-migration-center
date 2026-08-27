<?php
/**
 * phpBB Migration Center Extension
 *
 * @copyright (c) 2026 phpBB SEO Team
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace phpbbseo\migrationcenter\source\xenforo\normalizer;

use phpbbseo\migrationcenter\core\dto\post_dto;
use phpbbseo\migrationcenter\core\dto\migration_config_dto;
use phpbbseo\migrationcenter\source\xenforo\content\xf_message_converter;

/**
 * XenForo Post Normalizer
 */
class xf_post_normalizer
{
	/** @var xf_message_converter */
	protected $converter;

	/**
	 * Constructor
	 *
	 * @param xf_message_converter|null $converter
	 */
	public function __construct(?xf_message_converter $converter = null)
	{
		$this->converter = $converter ?: new xf_message_converter();
	}

	/**
	 * Normalize a XenForo post row into PostDto
	 *
	 * @param array $row
	 * @param migration_config_dto $config
	 * @param array $deletion_log
	 * @return post_dto
	 */
	public function normalize_post(array $row, migration_config_dto $config, array $deletion_log = []): post_dto
	{
		$post = new post_dto();
		$post->source_id = (int)$row['post_id'];
		$post->topic_source_id = (int)($row['thread_id'] ?? 0);
		$post->user_source_id = (int)($row['user_id'] ?? 0);
		$post->username = trim((string)($row['username'] ?? ''));
		$post->post_time = (int)($row['post_date'] ?? time());
		$post->position = (int)($row['position'] ?? 0);
		$post->raw_source_message = (string)($row['message'] ?? '');
		$post->raw_source_data = $row;

		// 1. IP Address Conversion
		$raw_ip = $row['ip'] ?? $row['ip_address'] ?? null;
		$post->poster_ip = $this->format_ip($raw_ip);

		// 2. Visibility / Message State
		$state = (string)($row['message_state'] ?? 'visible');
		switch ($state)
		{
			case 'visible':
				$post->post_visibility = 1; // ITEM_APPROVED
				break;

			case 'moderated':
				$post->post_visibility = 0; // ITEM_UNAPPROVED
				break;

			case 'deleted':
				$post->post_visibility = 2; // ITEM_DELETED (soft-deleted)
				if (!empty($deletion_log))
				{
					$post->delete_time = (int)($deletion_log['delete_date'] ?? time());
					$post->delete_user_source_id = (int)($deletion_log['delete_user_id'] ?? 0);
					$post->delete_username = trim((string)($deletion_log['delete_username'] ?? ''));
					$post->delete_reason = trim((string)($deletion_log['delete_reason'] ?? ''));
				}
				break;

			default:
				$post->post_visibility = 0; // ITEM_UNAPPROVED default safe
				$post->warnings[] = "Unknown message state '{$state}', defaulting to unapproved";
				break;
		}

		// 3. Edit Metadata
		$post->post_edit_count = max(0, (int)($row['edit_count'] ?? 0));
		$post->post_edit_time = (int)($row['last_edit_date'] ?? 0);
		$post->post_edit_source_user_id = (int)($row['last_edit_user_id'] ?? 0);

		// 4. BBCode & Message Conversion
		$conversion = $this->converter->convert($post->raw_source_message, $config);
		$post->normalized_message = $conversion->normalized_bbcode;
		$post->post_text = $conversion->storage_text;
		$post->bbcode_uid = $conversion->bbcode_uid;
		$post->bbcode_bitfield = $conversion->bbcode_bitfield;
		$post->attachment_source_ids = $conversion->detected_attachments;
		$post->has_attachment = !empty($conversion->detected_attachments) || !empty($row['attach_count']);
		$post->unsupported_features = $conversion->unsupported_tags;
		$post->warnings = array_merge($post->warnings, $conversion->warnings);

		return $post;
	}

	/**
	 * Convert packed binary or string IP to valid IPv4/IPv6
	 *
	 * @param mixed $raw_ip
	 * @return string
	 */
	public function format_ip($raw_ip): string
	{
		if (empty($raw_ip))
		{
			return '127.0.0.1';
		}

		// If binary packed IP (4 bytes for IPv4, 16 bytes for IPv6)
		if (is_string($raw_ip) && (strlen($raw_ip) === 4 || strlen($raw_ip) === 16))
		{
			$unpacked = @inet_ntop($raw_ip);
			if ($unpacked !== false && filter_var($unpacked, FILTER_VALIDATE_IP))
			{
				return $unpacked;
			}
		}

		// If plain string IP
		if (is_string($raw_ip) && filter_var($raw_ip, FILTER_VALIDATE_IP))
		{
			return $raw_ip;
		}

		return '127.0.0.1';
	}
}
