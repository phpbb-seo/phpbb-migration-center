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

function get_test_db()
{
	global $phpbb_root_path, $table_prefix;
	require $phpbb_root_path . 'config.php';
	$db = new \phpbb\db\driver\mysqli();
	$db->sql_connect($dbhost, $dbuser, $dbpasswd, $dbname, (int)$dbport);
	return [$db, $table_prefix];
}
