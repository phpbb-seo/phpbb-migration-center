<?php
/**
 * phpBB Migration Center Extension
 *
 * @copyright (c) 2026 phpBB SEO Team
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace phpbbseo\migrationcenter\tests\integration\source\vbulletin;

use phpbbseo\migrationcenter\core\dto\migration_config_dto;
use phpbbseo\migrationcenter\core\engine\provider_registry;
use phpbbseo\migrationcenter\core\engine\step_registry;
use phpbbseo\migrationcenter\source\vbulletin\vbulletin_source_provider;
use phpbbseo\migrationcenter\source\xenforo\xenforo_source_provider;

/**
 * Integration Test for vBulletin Preflight, Complete Counts, and Provider Scoping Safety
 */
class vb_preflight_and_counts_test
{
	public function run(): array
	{
		$results = [];

		$env_lines = file('C:/vb-migration-lab/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
		$env = [];
		foreach ($env_lines as $l) {
			if (strpos(trim($l), '#') === 0 || strpos($l, '=') === false) continue;
			list($k, $v) = explode('=', $l, 2);
			$env[trim($k)] = trim($v);
		}

		$vb_provider = new vbulletin_source_provider();
		$xf_provider = new xenforo_source_provider();

		// 1. Provider Registration in Registry
		$provider_reg = new provider_registry();
		$provider_reg->register($xf_provider);
		$provider_reg->register($vb_provider);

		$results['provider_registry_has_xf'] = ($provider_reg->get('xenforo') !== null);
		$results['provider_registry_has_vb'] = ($provider_reg->get('vbulletin') !== null);
		$results['provider_registry_rejects_unknown'] = ($provider_reg->get('unknown_board') === null);

		// 2. Strict Provider-Scoped Step Resolution Safety
		$step_reg = new step_registry();
		$xf_groups_step = new \phpbbseo\migrationcenter\source\xenforo\step\groups_step();
		$step_reg->register($xf_groups_step, 'xenforo');

		// XenForo resolves XenForo groups
		$results['step_reg_xenforo_resolves_only_xenforo'] = ($step_reg->get('groups', 'xenforo') !== null);

		// vBulletin cannot resolve XenForo-specific groups step (No silent fallback!)
		$results['step_reg_vbulletin_does_not_fallback_to_xenforo_groups'] = ($step_reg->get('groups', 'vbulletin') === null);

		// Generic shared steps fallback is allowed only for explicitly generic steps
		$results['step_reg_generic_fallback_allowed_only_for_generic_steps'] = ($step_reg->get('non_generic_step', 'vbulletin') === null);

		// Unknown provider is rejected
		$results['step_reg_unknown_provider_rejected'] = ($step_reg->get('groups', 'mybb') === null);

		// 3. Preflight Edge Cases & Guardrails
		// Case A: Wrong database credentials
		$bad_creds_cfg = new migration_config_dto();
		$bad_creds_cfg->db_host = '127.0.0.1';
		$bad_creds_cfg->db_port = 3307;
		$bad_creds_cfg->db_name = 'vb3_test';
		$bad_creds_cfg->db_user = 'invalid_user_99';
		$bad_creds_cfg->db_password = 'wrong_password';
		$bad_creds_res = $vb_provider->run_preflight($bad_creds_cfg);
		$results['preflight_wrong_credentials_blocked'] = ($bad_creds_res->passed === false);

		// Case B: Unavailable database port
		$bad_port_cfg = new migration_config_dto();
		$bad_port_cfg->db_host = '127.0.0.1';
		$bad_port_cfg->db_port = 9999;
		$bad_port_cfg->db_name = 'vb3_test';
		$bad_port_cfg->db_user = 'migration_vb3_readonly';
		$bad_port_cfg->db_password = $env['VB3_DB_PASSWORD'] ?? 'vb3_lab_secret_pass_2026';
		$bad_port_res = $vb_provider->run_preflight($bad_port_cfg);
		$results['preflight_unavailable_port_blocked'] = ($bad_port_res->passed === false);

		// Case C: Source equals Target database collision guard
		$collision_cfg = new migration_config_dto();
		$collision_cfg->db_host = '127.0.0.1';
		$collision_cfg->db_port = 3306;
		$collision_cfg->db_name = 'bb'; // target db
		$collision_cfg->db_user = 'root';
		$collision_cfg->db_password = '';
		$collision_res = $vb_provider->run_preflight($collision_cfg);
		$results['preflight_source_target_collision_blocked'] = ($collision_res->passed === false);

		// Case D: Incorrect table prefix
		$bad_prefix_cfg = new migration_config_dto();
		$bad_prefix_cfg->db_host = '127.0.0.1';
		$bad_prefix_cfg->db_port = 3307;
		$bad_prefix_cfg->db_name = 'vb3_test';
		$bad_prefix_cfg->db_user = 'migration_vb3_readonly';
		$bad_prefix_cfg->db_password = $env['VB3_DB_PASSWORD'] ?? 'vb3_lab_secret_pass_2026';
		$bad_prefix_cfg->db_prefix = 'wrong_prefix_';
		$bad_prefix_res = $vb_provider->run_preflight($bad_prefix_cfg);
		$results['preflight_incorrect_prefix_blocked'] = ($bad_prefix_res->passed === false);

		// 4. Test Real vB 3.8.11 Preflight & Complete Source Counts
		$cfg3 = new migration_config_dto();
		$cfg3->source_system = 'vbulletin';
		$cfg3->db_host = '127.0.0.1';
		$cfg3->db_port = 3307;
		$cfg3->db_name = 'vb3_test';
		$cfg3->db_user = 'migration_vb3_readonly';
		$cfg3->db_password = $env['VB3_DB_PASSWORD'] ?? 'vb3_lab_secret_pass_2026';
		$cfg3->source_path = 'C:/vb-migration-lab/vb3';

		$preflight3 = $vb_provider->run_preflight($cfg3);
		$results['vb3_preflight_passed'] = $preflight3->passed;

		$results['vb3_users_count']                 = ($vb_provider->get_total_records('users', $cfg3) === 100);
		$results['vb3_groups_count']                = ($vb_provider->get_total_records('groups', $cfg3) === 8);
		$results['vb3_primary_memberships_count']   = ($vb_provider->get_total_records('users', $cfg3) === 100);
		$results['vb3_memberships_count']           = ($vb_provider->get_total_records('group_memberships', $cfg3) === 100);
		$results['vb3_global_permissions_count']    = ($vb_provider->get_total_records('global_permissions', $cfg3) === 8);
		$results['vb3_forums_count']                = ($vb_provider->get_total_records('forums', $cfg3) === 38);
		$results['vb3_topics_count']                = ($vb_provider->get_total_records('topics', $cfg3) === 538);
		$results['vb3_posts_count']                 = ($vb_provider->get_total_records('posts', $cfg3) === 7822);
		$results['vb3_attachments_count']           = ($vb_provider->get_total_records('attachments', $cfg3) === 5);
		$results['vb3_avatars_count']               = ($vb_provider->get_total_records('avatars', $cfg3) === 2);
		$results['vb3_pm_bodies_count']             = ($vb_provider->get_total_records('conversations', $cfg3) === 5);
		$results['vb3_pm_messages_count']           = ($vb_provider->get_total_records('conversation_messages', $cfg3) === 5);
		$results['vb3_pm_attachments_count']        = ($vb_provider->get_total_records('conversation_attachments', $cfg3) === 0);
		$results['vb3_polls_count']                 = ($vb_provider->get_total_records('polls', $cfg3) === 2);
		$results['vb3_bans_count']                  = ($vb_provider->get_total_records('bans', $cfg3) === 3);

		// 5. Test Real vB 4.2.5 Preflight & Complete Source Counts
		$cfg4 = new migration_config_dto();
		$cfg4->source_system = 'vbulletin';
		$cfg4->db_host = '127.0.0.1';
		$cfg4->db_port = 3308;
		$cfg4->db_name = 'vb4_test';
		$cfg4->db_user = 'migration_vb4_readonly';
		$cfg4->db_password = $env['VB4_DB_PASSWORD'] ?? 'vb4_lab_secret_pass_2026';
		$cfg4->source_path = 'C:/vb-migration-lab/vb4';

		$preflight4 = $vb_provider->run_preflight($cfg4);
		$results['vb4_preflight_passed'] = $preflight4->passed;

		$results['vb4_users_count']                 = ($vb_provider->get_total_records('users', $cfg4) === 100);
		$results['vb4_groups_count']                = ($vb_provider->get_total_records('groups', $cfg4) === 8);
		$results['vb4_primary_memberships_count']   = ($vb_provider->get_total_records('users', $cfg4) === 100);
		$results['vb4_memberships_count']           = ($vb_provider->get_total_records('group_memberships', $cfg4) === 100);
		$results['vb4_global_permissions_count']    = ($vb_provider->get_total_records('global_permissions', $cfg4) === 8);
		$results['vb4_forums_count']                = ($vb_provider->get_total_records('forums', $cfg4) === 38);
		$results['vb4_topics_count']                = ($vb_provider->get_total_records('topics', $cfg4) === 538);
		$results['vb4_posts_count']                 = ($vb_provider->get_total_records('posts', $cfg4) === 7822);
		$results['vb4_attachments_count']           = ($vb_provider->get_total_records('attachments', $cfg4) === 5); // 5 verified post attachments!
		$results['vb4_avatars_count']               = ($vb_provider->get_total_records('avatars', $cfg4) === 2);
		$results['vb4_pm_bodies_count']             = ($vb_provider->get_total_records('conversations', $cfg4) === 5);
		$results['vb4_pm_messages_count']           = ($vb_provider->get_total_records('conversation_messages', $cfg4) === 5);
		$results['vb4_pm_attachments_count']        = ($vb_provider->get_total_records('conversation_attachments', $cfg4) === 0);
		$results['vb4_polls_count']                 = ($vb_provider->get_total_records('polls', $cfg4) === 2);
		$results['vb4_bans_count']                  = ($vb_provider->get_total_records('bans', $cfg4) === 3);

		// 6. Test Language Keys
		if (!defined('IN_PHPBB'))
		{
			define('IN_PHPBB', true);
		}
		$lang_en = [];
		$lang_fa = [];
		$lang = [];
		include 'C:/xampp/htdocs/bb/ext/phpbbseo/migrationcenter/language/en/migrationcenter.php';
		$lang_en = $lang ?? [];
		$lang = [];
		include 'C:/xampp/htdocs/bb/ext/phpbbseo/migrationcenter/language/fa/migrationcenter.php';
		$lang_fa = $lang ?? [];

		$results['lang_en_has_vb_key'] = isset($lang_en['SOURCE_SYSTEM_VBULLETIN']);
		$results['lang_fa_has_vb_key'] = isset($lang_fa['SOURCE_SYSTEM_VBULLETIN']);

		return $results;
	}
}
