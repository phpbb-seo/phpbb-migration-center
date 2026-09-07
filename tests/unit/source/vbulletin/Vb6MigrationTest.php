<?php
/**
 * phpBB Migration Center Extension
 *
 * @copyright (c) 2026 phpBB SEO Team
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace phpbbseo\migrationcenter\tests\unit\source\vbulletin;

use phpbbseo\migrationcenter\core\engine\provider_registry;
use phpbbseo\migrationcenter\core\engine\step_registry;
use phpbbseo\migrationcenter\source\vbulletin\vb6_source_provider;
use phpbbseo\migrationcenter\source\vbulletin\step\groups_step;
use phpbbseo\migrationcenter\source\vbulletin\step\vb_users_step;
use phpbbseo\migrationcenter\source\vbulletin\step\vb6_forums_step;
use phpbbseo\migrationcenter\source\vbulletin\step\vb6_topics_step;
use phpbbseo\migrationcenter\source\vbulletin\step\vb6_posts_step;
use phpbbseo\migrationcenter\source\vbulletin\password\vb_password_handler;
use phpbbseo\migrationcenter\source\vbulletin\password\vb_password_driver;
use phpbbseo\migrationcenter\source\vbulletin\normalizer\vb_user_normalizer;
use phpbbseo\migrationcenter\source\vbulletin\config\vb_config_detector;
use phpbbseo\migrationcenter\acp\main_module;

/**
 * vBulletin 6 Migration Unit Test Suite
 */
class Vb6MigrationTest
{
	public function run(): bool
	{
		echo "[RUN] Vb6MigrationTest...\n";

		// 1. Provider metadata & registration
		$vb6 = new vb6_source_provider();
		if ($vb6->get_system_name() !== 'vbulletin6')
		{
			throw new \RuntimeException("vb6_source_provider get_system_name() must return 'vbulletin6'");
		}
		if ($vb6->get_title() !== 'vBulletin 6.x')
		{
			throw new \RuntimeException("vb6_source_provider get_title() must return 'vBulletin 6.x'");
		}

		$reg = new provider_registry();
		$reg->register($vb6);

		if (!$reg->has('vbulletin6') || !$reg->has('vb6'))
		{
			throw new \RuntimeException("Provider registry must recognize 'vbulletin6' and alias 'vb6'");
		}

		// 2. Step Registry resolution
		$s_reg = new step_registry();
		$s_reg->register(new groups_step(), 'vbulletin');
		$s_reg->register(new vb_users_step(), 'vbulletin');
		$s_reg->register(new vb6_forums_step(), 'vbulletin6');
		$s_reg->register(new vb6_topics_step(), 'vbulletin6');
		$s_reg->register(new vb6_posts_step(), 'vbulletin6');

		$s_reg->register(new \phpbbseo\migrationcenter\source\vbulletin\step\vb_attachments_step(), 'vbulletin');
		$s_reg->register(new \phpbbseo\migrationcenter\source\vbulletin\step\vb_node_permissions_step(), 'vbulletin');
		$s_reg->register(new \phpbbseo\migrationcenter\source\vbulletin\step\vb_global_permissions_step(), 'vbulletin');
		$s_reg->register(new \phpbbseo\migrationcenter\source\vbulletin\step\vb_bans_step(), 'vbulletin');
		$s_reg->register(new \phpbbseo\migrationcenter\source\vbulletin\step\vb_avatars_step(), 'vbulletin');

		$s_reg->register(new \phpbbseo\migrationcenter\source\vbulletin\step\vb_conversations_step(), 'vbulletin');
		$s_reg->register(new \phpbbseo\migrationcenter\source\vbulletin\step\vb_conversation_messages_step(), 'vbulletin');
		$s_reg->register(new \phpbbseo\migrationcenter\source\vbulletin\step\vb_conversation_attachments_step(), 'vbulletin');
		$s_reg->register(new \phpbbseo\migrationcenter\source\vbulletin\step\vb_polls_step(), 'vbulletin');

		$step_forums = $s_reg->get('forums', 'vbulletin6');
		$step_topics = $s_reg->get('topics', 'vbulletin6');
		$step_posts  = $s_reg->get('posts', 'vbulletin6');
		$step_users  = $s_reg->get('users', 'vbulletin6'); // fallback to vbulletin
		$step_groups = $s_reg->get('groups', 'vb6');       // fallback to vbulletin via alias
		$step_attach = $s_reg->get('attachments', 'vbulletin6');
		$step_bans   = $s_reg->get('bans', 'vbulletin6');
		$step_nperm  = $s_reg->get('node_permissions', 'vbulletin6');
		$step_gperm  = $s_reg->get('global_permissions', 'vbulletin6');
		$step_avatars = $s_reg->get('avatars', 'vbulletin6');
		$step_convs  = $s_reg->get('conversations', 'vbulletin6');
		$step_cmsgs  = $s_reg->get('conversation_messages', 'vbulletin6');
		$step_catt   = $s_reg->get('conversation_attachments', 'vbulletin6');
		$step_polls  = $s_reg->get('polls', 'vbulletin6');

		if (!$step_convs || $step_convs->get_name() !== 'conversations')
		{
			throw new \RuntimeException("step_registry must fallback conversations step for vbulletin6");
		}
		if (!$step_cmsgs || $step_cmsgs->get_name() !== 'conversation_messages')
		{
			throw new \RuntimeException("step_registry must fallback conversation_messages step for vbulletin6");
		}
		if (!$step_catt || $step_catt->get_name() !== 'conversation_attachments')
		{
			throw new \RuntimeException("step_registry must fallback conversation_attachments step for vbulletin6");
		}
		if (!$step_polls || $step_polls->get_name() !== 'polls')
		{
			throw new \RuntimeException("step_registry must fallback polls step for vbulletin6");
		}
		if (!in_array('polls', $vb6->get_supported_steps(), true) || !in_array('conversations', $vb6->get_supported_steps(), true))
		{
			throw new \RuntimeException("vb6_source_provider must include polls and conversations in get_supported_steps()");
		}

		if (!$step_forums instanceof vb6_forums_step)
		{
			throw new \RuntimeException("step_registry must resolve forums to vb6_forums_step for vbulletin6");
		}
		if (!$step_topics instanceof vb6_topics_step)
		{
			throw new \RuntimeException("step_registry must resolve topics to vb6_topics_step for vbulletin6");
		}
		if (!$step_posts instanceof vb6_posts_step)
		{
			throw new \RuntimeException("step_registry must resolve posts to vb6_posts_step for vbulletin6");
		}
		if (!$step_users || $step_users->get_name() !== 'users')
		{
			throw new \RuntimeException("step_registry must fallback users step for vbulletin6");
		}
		if (!$step_groups || $step_groups->get_name() !== 'groups')
		{
			throw new \RuntimeException("step_registry must fallback groups step for vb6 alias");
		}
		if (!$step_attach || $step_attach->get_name() !== 'attachments')
		{
			throw new \RuntimeException("step_registry must fallback attachments step for vbulletin6");
		}
		if (!$step_bans || $step_bans->get_name() !== 'bans')
		{
			throw new \RuntimeException("step_registry must fallback bans step for vbulletin6");
		}
		if (!$step_nperm || $step_nperm->get_name() !== 'node_permissions')
		{
			throw new \RuntimeException("step_registry must fallback node_permissions step for vbulletin6");
		}
		if (!$step_gperm || $step_gperm->get_name() !== 'global_permissions')
		{
			throw new \RuntimeException("step_registry must fallback global_permissions step for vbulletin6");
		}
		if (!$step_avatars || $step_avatars->get_name() !== 'avatars')
		{
			throw new \RuntimeException("step_registry must fallback avatars step for vbulletin6");
		}

		// 3. Argon2id Password Handler & Driver
		$pwd_handler = new vb_password_handler();
		$test_plain = 'P@ssw0rd_vB6_T3st!';
		$argon_hash = defined('PASSWORD_ARGON2ID')
			? password_hash($test_plain, PASSWORD_ARGON2ID, ['memory_cost' => 1024, 'time_cost' => 2, 'threads' => 1])
			: '$argon2id$v=19$m=65536,t=4,p=1$c3F1YXJlZGV2ZWxvcGVyMTIzNA$DummyHashForEnvironmentWithoutArgonSupport';

		if (!$pwd_handler->is_supported('vbulletin', $argon_hash))
		{
			throw new \RuntimeException("vb_password_handler must support Argon2id hash");
		}

		$converted = $pwd_handler->convert_password('vbulletin', $argon_hash);
		if ($converted['type'] !== 'vbulletin' || $converted['requires_reset'] !== false)
		{
			throw new \RuntimeException("vb_password_handler convert_password failed for Argon2id");
		}
		if (strpos($converted['hash'], '$mcvb$6$') !== 0)
		{
			throw new \RuntimeException("vb_password_handler must encode Argon2id with \$mcvb\$6\$ prefix");
		}

		// Driver Verification (only verify if PHP runtime has Argon2id support)
		if (defined('PASSWORD_ARGON2ID'))
		{
			$driver = new vb_password_driver();
			if (!$driver->check($test_plain, $converted['hash']))
			{
				throw new \RuntimeException("vb_password_driver failed to verify correct Argon2id password");
			}
			if ($driver->check('WrongPassword123!', $converted['hash']))
			{
				throw new \RuntimeException("vb_password_driver incorrectly verified wrong password");
			}
		}

		// 4. User Normalizer with vB6 'token' column
		$normalizer = new vb_user_normalizer($pwd_handler);
		$user_row = [
			'userid'       => 42,
			'username'     => 'DevOpsGuru',
			'email'        => 'guru@example.com',
			'token'        => $argon_hash,
			'usergroupid'  => 2,
			'joindate'     => 1786000000,
			'lastvisit'    => 1786100000,
			'posts'        => 58,
			'birthday'     => '06-15-1992',
		];
		$user_dto = $normalizer->normalize($user_row);
		if ($user_dto->username !== 'DevOpsGuru')
		{
			throw new \RuntimeException("User normalizer username mismatch");
		}
		if ($user_dto->source_auth_scheme !== 'vbulletin_argon2')
		{
			throw new \RuntimeException("User normalizer must tag Argon2id users with 'vbulletin_argon2'");
		}
		if (strpos($user_dto->password_hash, '$mcvb$6$') !== 0)
		{
			throw new \RuntimeException("User normalizer must populate password_hash with \$mcvb\$6\$ token");
		}

		// 5. Config Detector vB6 core/includes path
		$temp_dir = sys_get_temp_dir() . '/vb6_test_cfg_' . uniqid();
		mkdir($temp_dir . '/core/includes', 0777, true);
		$mock_config = "<?php\n"
			. "\$config['Database']['dbname'] = 'vb6_automated_test';\n"
			. "\$config['MasterServer']['servername'] = '127.0.0.1';\n"
			. "\$config['MasterServer']['port'] = 3306;\n"
			. "\$config['MasterServer']['username'] = 'vb6_tester';\n"
			. "\$config['MasterServer']['password'] = 'tester_pass';\n";
		file_put_contents($temp_dir . '/core/includes/config.php', $mock_config);

		$detected_cfg = vb_config_detector::detect_from_path($temp_dir);
		@unlink($temp_dir . '/core/includes/config.php');
		@rmdir($temp_dir . '/core/includes');
		@rmdir($temp_dir . '/core');
		@rmdir($temp_dir);

		if (!$detected_cfg)
		{
			throw new \RuntimeException("vb_config_detector failed to detect config from vB6 core/includes/config.php");
		}
		if ($detected_cfg->db_name !== 'vb6_automated_test' || $detected_cfg->db_user !== 'vb6_tester')
		{
			throw new \RuntimeException("vb_config_detector parsed incorrect database credentials for vB6");
		}

		// 6. ACP Label Formatter
		if (main_module::format_source_label('vbulletin6') !== 'vBulletin 6.x')
		{
			throw new \RuntimeException("format_source_label('vbulletin6') failed");
		}
		if (main_module::format_source_label('vb6') !== 'vBulletin 6.x')
		{
			throw new \RuntimeException("format_source_label('vb6') failed");
		}

		echo "  [PASS] Vb6MigrationTest passed\n";
		return true;
	}
}
