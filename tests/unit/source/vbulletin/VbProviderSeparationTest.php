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
use phpbbseo\migrationcenter\source\vbulletin\vb3_source_provider;
use phpbbseo\migrationcenter\source\vbulletin\vb4_source_provider;
use phpbbseo\migrationcenter\source\vbulletin\vbulletin_source_provider;
use phpbbseo\migrationcenter\source\vbulletin\step\groups_step;
use phpbbseo\migrationcenter\source\vbulletin\step\vb_users_step;
use phpbbseo\migrationcenter\acp\main_module;

class VbProviderSeparationTest
{
	public function run(): bool
	{
		echo "[RUN] VbProviderSeparationTest...\n";

		// 1. Providers Initialization
		$vb3 = new vb3_source_provider();
		$vb4 = new vb4_source_provider();
		$vb_generic = new vbulletin_source_provider();

		if ($vb3->get_system_name() !== 'vbulletin3')
		{
			throw new \RuntimeException("vb3_source_provider get_system_name() must return 'vbulletin3'");
		}
		if ($vb4->get_system_name() !== 'vbulletin4')
		{
			throw new \RuntimeException("vb4_source_provider get_system_name() must return 'vbulletin4'");
		}

		// 2. Provider Registry
		$reg = new provider_registry();
		$reg->register($vb3);
		$reg->register($vb4);
		$reg->register($vb_generic);

		if (!$reg->has('vbulletin3') || !$reg->has('vb3'))
		{
			throw new \RuntimeException("Provider registry missing vbulletin3");
		}
		if (!$reg->has('vbulletin4') || !$reg->has('vb4'))
		{
			throw new \RuntimeException("Provider registry missing vbulletin4");
		}

		if ($reg->get('vbulletin3')->get_title() !== 'vBulletin 3.8.x')
		{
			throw new \RuntimeException("vbulletin3 title mismatch");
		}
		if ($reg->get('vbulletin4')->get_title() !== 'vBulletin 4.2.x')
		{
			throw new \RuntimeException("vbulletin4 title mismatch");
		}

		// 3. Step Registry Fallback & Resolution
		$s_reg = new step_registry();
		$s_reg->register(new groups_step(), 'vbulletin');
		$s_reg->register(new vb_users_step(), 'vbulletin');

		$step_vb3 = $s_reg->get('groups', 'vbulletin3');
		$step_vb4 = $s_reg->get('groups', 'vbulletin4');
		$step_users_vb3 = $s_reg->get('users', 'vb3');

		if (!$step_vb3 || $step_vb3->get_name() !== 'groups')
		{
			throw new \RuntimeException("step_registry failed to resolve groups step for vbulletin3");
		}
		if (!$step_vb4 || $step_vb4->get_name() !== 'groups')
		{
			throw new \RuntimeException("step_registry failed to resolve groups step for vbulletin4");
		}
		if (!$step_users_vb3 || $step_users_vb3->get_name() !== 'users')
		{
			throw new \RuntimeException("step_registry failed to resolve users step for vb3 alias");
		}

		// 4. ACP Label Formatter
		if (main_module::format_source_label('vbulletin3') !== 'vBulletin 3.8')
		{
			throw new \RuntimeException("format_source_label('vbulletin3') failed");
		}
		if (main_module::format_source_label('vbulletin4') !== 'vBulletin 4.2')
		{
			throw new \RuntimeException("format_source_label('vbulletin4') failed");
		}

		echo "  [PASS] VbProviderSeparationTest passed\n";
		return true;
	}
}
