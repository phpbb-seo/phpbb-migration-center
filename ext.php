<?php
/**
 * phpBB Migration Center Extension
 *
 * @copyright (c) 2026 phpBB SEO Team
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace phpbbseo\migrationcenter;

/**
 * Extension class for phpbbseo/migrationcenter
 */
class ext extends \phpbb\extension\base
{
	/**
	 * Single point of verification for whether the extension can be enabled
	 *
	 * @return bool
	 */
	public function is_enableable()
	{
		$config = $this->container->get('config');
		return version_compare($config['version'], '3.3.0', '>=');
	}
}
