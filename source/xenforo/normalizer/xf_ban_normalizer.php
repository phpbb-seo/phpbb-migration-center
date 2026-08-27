<?php
/**
 * phpBB Migration Center Extension
 *
 * @copyright (c) 2026 phpBB SEO Team
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace phpbbseo\migrationcenter\source\xenforo\normalizer;

use phpbbseo\migrationcenter\core\dto\ban_dto;

/**
 * XenForo Ban Normalizer
 */
class xf_ban_normalizer
{
	/**
	 * Normalize XenForo user ban record (xf_user_ban)
	 *
	 * @param array $row
	 * @return ban_dto
	 */
	public function normalize_user_ban(array $row): ban_dto
	{
		$dto = new ban_dto();
		$dto->ban_type = 'user';
		$dto->source_id = 'user_' . (int)$row['user_id'];
		$dto->user_source_id = (int)$row['user_id'];
		$dto->ban_start = max(0, (int)($row['ban_date'] ?? time()));
		$dto->ban_end = max(0, (int)($row['end_date'] ?? 0));
		$dto->ban_give_reason = trim((string)($row['user_reason'] ?? ''));
		$dto->ban_reason = 'Imported from XenForo user ban';
		$dto->raw_data = $row;

		return $dto;
	}

	/**
	 * Normalize XenForo email ban record (xf_ban_email)
	 *
	 * @param array $row
	 * @return ban_dto
	 */
	public function normalize_email_ban(array $row): ban_dto
	{
		$email_pattern = strtolower(trim((string)($row['banned_email'] ?? '')));

		$dto = new ban_dto();
		$dto->ban_type = 'email';
		$dto->source_id = 'email_' . md5($email_pattern);
		$dto->ban_email = $email_pattern;
		$dto->ban_start = max(0, (int)($row['create_date'] ?? time()));
		$dto->ban_end = 0; // XenForo email bans are permanent
		$dto->ban_give_reason = trim((string)($row['reason'] ?? ''));
		$dto->ban_reason = 'Imported from XenForo email ban';
		$dto->raw_data = $row;

		return $dto;
	}

	/**
	 * Normalize XenForo IP ban record (xf_ip_match)
	 *
	 * @param array $row
	 * @return ban_dto
	 */
	public function normalize_ip_ban(array $row): ban_dto
	{
		$ip_rule = trim((string)($row['ip'] ?? $row['ip_rule'] ?? ''));

		$dto = new ban_dto();
		$dto->ban_type = 'ip';
		$dto->source_id = 'ip_' . md5($ip_rule);
		$dto->ban_ip = $ip_rule;
		$dto->ban_start = max(0, (int)($row['create_date'] ?? time()));
		$dto->ban_end = 0; // XenForo IP bans are permanent
		$dto->ban_give_reason = trim((string)($row['reason'] ?? ''));
		$dto->ban_reason = 'Imported from XenForo IP ban';
		$dto->raw_data = $row;

		return $dto;
	}
}
