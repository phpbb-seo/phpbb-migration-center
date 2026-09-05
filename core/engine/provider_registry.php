<?php
/**
 * phpBB Migration Center Extension
 *
 * @copyright (c) 2026 phpBB SEO Team
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace phpbbseo\migrationcenter\core\engine;

use phpbbseo\migrationcenter\core\contract\source_provider_interface;

/**
 * Registry of available Source Providers
 */
class provider_registry
{
	/** @var source_provider_interface[] */
	protected $providers = [];

	/**
	 * Register a source provider
	 *
	 * @param source_provider_interface $provider
	 * @return void
	 */
	public function register(source_provider_interface $provider): void
	{
		$this->providers[$provider->get_system_name()] = $provider;
	}

	/**
	 * Get a registered source provider
	 *
	 * @param string $system_name
	 * @return source_provider_interface|null
	 */
	public function get(string $system_name): ?source_provider_interface
	{
		if (isset($this->providers[$system_name]))
		{
			return $this->providers[$system_name];
		}
		if (($system_name === 'vb3' || $system_name === 'vbulletin3') && isset($this->providers['vbulletin3']))
		{
			return $this->providers['vbulletin3'];
		}
		if (($system_name === 'vb4' || $system_name === 'vbulletin4') && isset($this->providers['vbulletin4']))
		{
			return $this->providers['vbulletin4'];
		}
		if ($system_name === 'vbulletin')
		{
			return $this->providers['vbulletin'] ?? $this->providers['vbulletin4'] ?? $this->providers['vbulletin3'] ?? null;
		}
		if (in_array($system_name, ['vb3', 'vb4', 'vbulletin3', 'vbulletin4'], true) && isset($this->providers['vbulletin']))
		{
			return $this->providers['vbulletin'];
		}
		return null;
	}

	/**
	 * Get all registered providers
	 *
	 * @return source_provider_interface[]
	 */
	public function get_all(): array
	{
		return $this->providers;
	}

	/**
	 * Check if provider exists
	 *
	 * @param string $system_name
	 * @return bool
	 */
	public function has(string $system_name): bool
	{
		if (isset($this->providers[$system_name]))
		{
			return true;
		}
		if (in_array($system_name, ['vbulletin', 'vb3', 'vb4', 'vbulletin3', 'vbulletin4'], true))
		{
			return isset($this->providers['vbulletin3']) || isset($this->providers['vbulletin4']) || isset($this->providers['vbulletin']);
		}
		return false;
	}
}
