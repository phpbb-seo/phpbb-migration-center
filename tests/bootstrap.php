<?php
/**
 * phpBB Migration Center Test Bootstrap
 */

define('IN_PHPBB', true);
$phpbb_root_path = 'C:/xampp/htdocs/bb/';
$phpEx = 'php';

define('PHPBB_ROOT_PATH', $phpbb_root_path);
define('PHP_EXT', $phpEx);

if (file_exists($phpbb_root_path . 'common.php'))
{
	require_once $phpbb_root_path . 'common.php';
}

// Autoloader for extension classes
spl_autoload_register(function ($class) {
	$prefix = 'phpbbseo\\migrationcenter\\';
	$base_dir = __DIR__ . '/../';

	$len = strlen($prefix);
	if (strncmp($prefix, $class, $len) !== 0)
	{
		return;
	}

	$relative_class = substr($class, $len);
	$file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';

	if (file_exists($file))
	{
		require_once $file;
	}
});

if (!empty($phpbb_container))
{
	if (!$phpbb_container->has('phpbbseo.migrationcenter.id_mapper'))
	{
		try
		{
			$db = $phpbb_container->get('dbal.conn');
			$prefix = $phpbb_container->getParameter('core.table_prefix');
			$root_path = $phpbb_container->getParameter('core.root_path');

			$id_mapper = new \phpbbseo\migrationcenter\core\mapping\id_mapper($db, $prefix);
			$state_mgr = new \phpbbseo\migrationcenter\core\state\state_manager($db, $prefix);
			$lock_mgr  = new \phpbbseo\migrationcenter\core\state\lock_manager($db, $prefix);
			$writer    = new \phpbbseo\migrationcenter\core\writer\phpbb_target_writer($db, $prefix, $id_mapper, $state_mgr);

			$xf_provider = new \phpbbseo\migrationcenter\source\xenforo\xenforo_source_provider($root_path);
			$vb_provider = new \phpbbseo\migrationcenter\source\vbulletin\vbulletin_source_provider($root_path);

			$provider_reg = new \phpbbseo\migrationcenter\core\engine\provider_registry();
			$provider_reg->register($xf_provider);
			$provider_reg->register($vb_provider);

			$step_reg = new \phpbbseo\migrationcenter\core\engine\step_registry();
			$step_classes = [
				'groups' => \phpbbseo\migrationcenter\source\xenforo\step\groups_step::class,
				'users' => \phpbbseo\migrationcenter\source\xenforo\step\users_step::class,
				'group_memberships' => \phpbbseo\migrationcenter\source\xenforo\step\group_memberships_step::class,
				'global_permissions' => \phpbbseo\migrationcenter\source\xenforo\step\global_permissions_step::class,
				'forums' => \phpbbseo\migrationcenter\source\xenforo\step\forums_step::class,
				'node_permissions' => \phpbbseo\migrationcenter\source\xenforo\step\node_permissions_step::class,
				'topics' => \phpbbseo\migrationcenter\source\xenforo\step\topics_step::class,
				'posts' => \phpbbseo\migrationcenter\source\xenforo\step\posts_step::class,
				'attachments' => \phpbbseo\migrationcenter\source\xenforo\step\attachments_step::class,
				'avatars' => \phpbbseo\migrationcenter\source\xenforo\step\avatars_step::class,
				'conversations' => \phpbbseo\migrationcenter\source\xenforo\step\conversations_step::class,
				'conversation_messages' => \phpbbseo\migrationcenter\source\xenforo\step\conversation_messages_step::class,
				'conversation_attachments' => \phpbbseo\migrationcenter\source\xenforo\step\conversation_attachments_step::class,
				'polls' => \phpbbseo\migrationcenter\source\xenforo\step\polls_step::class,
				'bans' => \phpbbseo\migrationcenter\source\xenforo\step\bans_step::class,
			];
			foreach ($step_classes as $stk => $stc)
			{
				if (class_exists($stc))
				{
					$st = new $stc();
					$step_reg->register($st, 'xenforo');
					$step_reg->register($st, '');
				}
			}

			// Register vBulletin steps
			if (class_exists(\phpbbseo\migrationcenter\source\vbulletin\step\groups_step::class))
			{
				$step_reg->register(new \phpbbseo\migrationcenter\source\vbulletin\step\groups_step(), 'vbulletin');
			}
			if (class_exists(\phpbbseo\migrationcenter\source\vbulletin\step\vb_users_step::class))
			{
				$step_reg->register(new \phpbbseo\migrationcenter\source\vbulletin\step\vb_users_step(), 'vbulletin');
			}

			$engine = new \phpbbseo\migrationcenter\core\engine\migration_engine(
				$provider_reg, $step_reg, $state_mgr, $lock_mgr, $id_mapper, $writer
			);

			$phpbb_container->set('phpbbseo.migrationcenter.id_mapper', $id_mapper);
			$phpbb_container->set('phpbbseo.migrationcenter.state_manager', $state_mgr);
			$phpbb_container->set('phpbbseo.migrationcenter.lock_manager', $lock_mgr);
			$phpbb_container->set('phpbbseo.migrationcenter.target_writer', $writer);
			$phpbb_container->set('phpbbseo.migrationcenter.provider_registry', $provider_reg);
			$phpbb_container->set('phpbbseo.migrationcenter.step_registry', $step_reg);
			$phpbb_container->set('phpbbseo.migrationcenter.engine', $engine);

			// Register vBulletin password driver
			if (class_exists(\phpbbseo\migrationcenter\source\vbulletin\password\vb_password_driver::class))
			{
				$cfg_obj = $phpbb_container->get('config');
				$hlp_obj = $phpbb_container->get('passwords.driver_helper');
				$vb_pwd_driver = new \phpbbseo\migrationcenter\source\vbulletin\password\vb_password_driver($cfg_obj, $hlp_obj);
				$phpbb_container->set('phpbbseo.migrationcenter.passwords.driver.vbulletin', $vb_pwd_driver);
			}
		}
		catch (\Throwable $e)
		{
			// Ignore if container is read-only
		}
	}
}

function get_test_db()
{
	global $phpbb_root_path, $table_prefix;
	require $phpbb_root_path . 'config.php';
	$db = new \phpbb\db\driver\mysqli();
	$db->sql_connect($dbhost, $dbuser, $dbpasswd, $dbname, (int)$dbport);
	return [$db, $table_prefix];
}
