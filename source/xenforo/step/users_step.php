<?php
/**
 * phpBB Migration Center Extension
 *
 * @copyright (c) 2026 phpBB SEO Team
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace phpbbseo\migrationcenter\source\xenforo\step;

use phpbbseo\migrationcenter\core\contract\step_interface;
use phpbbseo\migrationcenter\core\contract\source_provider_interface;
use phpbbseo\migrationcenter\core\contract\target_writer_interface;
use phpbbseo\migrationcenter\core\dto\migration_config_dto;
use phpbbseo\migrationcenter\core\dto\step_result_dto;
use phpbbseo\migrationcenter\core\dto\user_dto;
use phpbbseo\migrationcenter\source\xenforo\adapter\xf_db_adapter;
use phpbbseo\migrationcenter\source\xenforo\password\xf_password_handler;

/**
 * XenForo Users Migration Step
 */
class users_step implements step_interface
{
	/** @var xf_password_handler */
	protected $password_handler;

	/** @var \phpbbseo\migrationcenter\source\xenforo\content\xf_message_converter */
	protected $message_converter;

	/**
	 * Constructor
	 *
	 * @param xf_password_handler|null $password_handler
	 * @param \phpbbseo\migrationcenter\source\xenforo\content\xf_message_converter|null $message_converter
	 */
	public function __construct(
		?xf_password_handler $password_handler = null,
		?\phpbbseo\migrationcenter\source\xenforo\content\xf_message_converter $message_converter = null
	) {
		$this->password_handler = $password_handler ?: new xf_password_handler();
		$this->message_converter = $message_converter ?: new \phpbbseo\migrationcenter\source\xenforo\content\xf_message_converter();
	}

	/**
	 * Unique step identifier
	 *
	 * @return string
	 */
	public function get_name(): string
	{
		return 'users';
	}

	/**
	 * Human-readable step label
	 *
	 * @return string
	 */
	public function get_label(): string
	{
		return 'Users';
	}

	/**
	 * Step dependencies
	 *
	 * @return array
	 */
	public function get_dependencies(): array
	{
		return ['groups'];
	}

	/**
	 * Execute a single batch for users
	 *
	 * @param string $run_id
	 * @param string|int $cursor
	 * @param int $batch_size
	 * @param migration_config_dto $config
	 * @param source_provider_interface $provider
	 * @param target_writer_interface $writer
	 * @return step_result_dto
	 */
	public function process_batch(
		string $run_id,
		$cursor,
		int $batch_size,
		migration_config_dto $config,
		source_provider_interface $provider,
		target_writer_interface $writer
	): step_result_dto {
		$result = new step_result_dto('users');
		$db = new xf_db_adapter($config);
		$prefix = $config->db_prefix ?: 'xf_';

		$cursor_id = (int)$cursor;
		$max_id = (int)$provider->get_max_source_id('users', $config);

		// Build keyset query with required joins
		$sql = "SELECT 
					u.*,
					a.scheme_class,
					a.data AS auth_data,
					p.signature,
					p.website,
					p.location,
					p.about,
					p.dob_day,
					p.dob_month,
					p.dob_year,
					p.custom_fields,
					b.ban_date,
					b.end_date,
					b.user_reason
				FROM `{$prefix}user` u
				LEFT JOIN `{$prefix}user_authenticate` a ON (u.user_id = a.user_id)
				LEFT JOIN `{$prefix}user_profile` p ON (u.user_id = p.user_id)
				LEFT JOIN `{$prefix}user_ban` b ON (u.user_id = b.user_id)
				WHERE u.user_id > :cursor
				ORDER BY u.user_id ASC
				LIMIT :batch_limit";

		$stmt = $db->get_pdo()->prepare($sql);
		$stmt->bindValue(':cursor', $cursor_id, \PDO::PARAM_INT);
		$stmt->bindValue(':batch_limit', $batch_size, \PDO::PARAM_INT);
		$stmt->execute();
		$rows = $stmt->fetchAll();

		$result->read_count = count($rows);

		if (empty($rows))
		{
			$result->is_completed = true;
			$result->next_cursor = $cursor;
			return $result;
		}

		$normalized_users = [];
		$last_id = $cursor_id;

		foreach ($rows as $row)
		{
			$source_id = (int)$row['user_id'];
			$last_id = $source_id;

			// Handle XenForo guest ID 0 (should not be in xf_user, but guard anyway)
			if ($source_id <= 0)
			{
				$result->skipped_count++;
				continue;
			}

			$user = new user_dto();
			$user->source_id = $source_id;
			$user->username = trim((string)$row['username']);
			$user->username_clean = function_exists('utf8_clean_string') ? utf8_clean_string($user->username) : mb_strtolower($user->username, 'UTF-8');
			$user->email = trim((string)$row['email']);

			// Handle passwords safely
			$scheme = (string)($row['scheme_class'] ?? '');
			$auth_data = $row['auth_data'] ?? null;
			$user->source_auth_scheme = $scheme;
			
			$conv_pass = $this->password_handler->convert_password($scheme, $auth_data);
			$user->password_hash = $conv_pass['hash'];
			$user->password_type = $conv_pass['type'];
			$user->requires_password_reset = $conv_pass['requires_reset'];

			if ($user->requires_password_reset)
			{
				$result->add_error(
					'UNSUPPORTED_PASSWORD_SCHEME',
					"User '{$user->username}' (ID: {$source_id}) uses unsupported scheme '{$scheme}'. Password reset will be required.",
					'warning',
					$source_id
				);
			}

			// Group metadata
			$user->primary_group_source_id = (int)($row['user_group_id'] ?? 2);
			if (!empty($row['secondary_group_ids']))
			{
				$user->secondary_group_source_ids = array_filter(array_map('intval', explode(',', $row['secondary_group_ids'])));
			}
			$user->group_id = 2; // Default phpBB Registered Users

			// Timestamps & counts
			$user->registered_date = (int)($row['register_date'] ?? time());
			$user->last_visit_date = (int)($row['last_activity'] ?? 0);
			$user->post_count = (int)($row['message_count'] ?? 0);
			$user->timezone = !empty($row['timezone']) ? $row['timezone'] : 'UTC';

			// User state mapping
			$xf_state = (string)($row['user_state'] ?? 'valid');
			$user->user_state = $xf_state;

			switch ($xf_state)
			{
				case 'valid':
					$user->user_type = 0; // USER_NORMAL
					$user->user_inactive_reason = 0;
					$user->user_inactive_time = 0;
					break;

				case 'email_confirm':
					$user->user_type = 1; // USER_INACTIVE
					$user->user_inactive_reason = 1; // INACTIVE_REGISTER
					$user->user_inactive_time = $user->registered_date;
					break;

				case 'email_confirm_edit':
					$user->user_type = 1;
					$user->user_inactive_reason = 2; // INACTIVE_PROFILE
					$user->user_inactive_time = $user->last_visit_date ?: time();
					break;

				case 'moderated':
				case 'rejected':
				case 'disabled':
					$user->user_type = 1;
					$user->user_inactive_reason = 3; // INACTIVE_MANUAL
					$user->user_inactive_time = $user->registered_date;
					break;

				default:
					$user->user_type = 0;
					break;
			}

			// Ban status
			if (!empty($row['is_banned']) || !empty($row['ban_date']))
			{
				$user->banned_state = true;
				$user->ban_info = [
					'ban_start'  => (int)($row['ban_date'] ?? time()),
					'ban_end'    => (int)($row['end_date'] ?? 0),
					'ban_reason' => (string)($row['user_reason'] ?? ''),
				];
			}

			// Profile metadata
			$raw_sig = (string)($row['signature'] ?? '');
			if (!empty($raw_sig))
			{
				$conv_sig = $this->message_converter->convert($raw_sig, $config);
				$user->signature = $conv_sig->storage_text ?: $conv_sig->normalized_bbcode;
				$user->sig_bbcode_uid = $conv_sig->bbcode_uid;
				$user->sig_bbcode_bitfield = $conv_sig->bbcode_bitfield;
			}
			else
			{
				$user->signature = '';
				$user->sig_bbcode_uid = '';
				$user->sig_bbcode_bitfield = '';
			}
			$user->website = (string)($row['website'] ?? '');
			$user->location = (string)($row['location'] ?? '');
			$user->about = (string)($row['about'] ?? '');
			$user->custom_title = (string)($row['custom_title'] ?? '');
			$user->is_admin = !empty($row['is_admin']);
			$user->is_moderator = !empty($row['is_moderator']);
			$user->visibility = !empty($row['visible']) ? 1 : 0;

			if (!empty($row['dob_year']) && !empty($row['dob_month']) && !empty($row['dob_day']))
			{
				$user->birthday = sprintf('%02d-%02d-%04d', $row['dob_day'], $row['dob_month'], $row['dob_year']);
			}

			// Unserialize custom fields if present
			if (!empty($row['custom_fields']))
			{
				$cf = @json_decode($row['custom_fields'], true) ?: @unserialize($row['custom_fields'], ['allowed_classes' => false]);
				if (is_array($cf))
				{
					$user->custom_fields = $cf;
				}
			}

			$normalized_users[] = $user;
		}

		$result->next_cursor = $last_id;

		// Dry-Run Handling
		if ($config->dry_run)
		{
			$result->imported_count = count($normalized_users);
			if (count($rows) < $batch_size || $last_id >= $max_id)
			{
				$result->is_completed = true;
			}
			return $result;
		}

		// Write users to target phpBB
		$write_options = [
			'run_id'                    => $run_id,
			'source_system'             => $config->source_system ?: 'xenforo',
			'preserve_ids'              => $config->preserve_ids,
			'duplicate_username_policy' => $config->duplicate_username_policy ?: 'rename',
			'duplicate_email_policy'    => $config->duplicate_email_policy ?: 'keep',
		];

		$write_results = $writer->write_users($normalized_users, $write_options);

		foreach ($write_results as $src_id => $res)
		{
			if ($res['status'] === 'success')
			{
				$result->imported_count++;
			}
			else if ($res['status'] === 'skipped')
			{
				$result->skipped_count++;
			}
			else
			{
				$result->add_error(
					'USER_WRITE_FAILED',
					"User ID {$src_id} write failed: " . ($res['error'] ?? 'Unknown error'),
					'error',
					$src_id
				);
			}
		}

		if (count($rows) < $batch_size || $last_id >= $max_id)
		{
			$result->is_completed = true;
		}

		return $result;
	}
}
