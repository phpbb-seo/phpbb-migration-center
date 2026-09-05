<?php
/**
 * phpBB Migration Center Extension
 *
 * @copyright (c) 2026 phpBB SEO Team
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace phpbbseo\migrationcenter\core\dto;

/**
 * Migration Configuration DTO
 */
class migration_config_dto implements \JsonSerializable
{
	/** @var string */
	public $source_system = 'xenforo';

	/** @var string */
	public $source_path = '';

	/** @var string */
	public $db_host = 'localhost';

	/** @var int */
	public $db_port = 3306;

	/** @var string */
	public $db_name = '';

	/** @var string */
	public $db_user = '';

	/** @var string */
	public $db_password = '';

	/** @var string */
	public $db_prefix = 'xf_';

	/** @var string */
	public $db_charset = 'utf8mb4';

	/** @var int */
	public $batch_size = 500;

	/** @var bool */
	public $preserve_ids = true;

	/** @var string */
	public $duplicate_username_policy = 'rename'; // rename, skip, merge

	/** @var string */
	public $duplicate_email_policy = 'keep'; // keep, anonymize, skip

	/** @var string */
	public $missing_file_policy = 'skip'; // skip, error

	/** @var bool */
	public $dry_run = false;

	/** @var int|null Optional minimum source ID */
	public $min_id = null;

	/** @var int|null Optional maximum source ID */
	public $max_id = null;

	/** @var int Optional limit on total records */
	public $limit = 0;

	/** @var array */
	public $selected_steps = [];

	/** @var array */
	public $options = [];

	/**
	 * Create instance from array
	 *
	 * @param array $data
	 * @return self
	 */
	public static function from_array(array $data): self
	{
		$dto = new self();
		foreach ($data as $key => $value)
		{
			if (property_exists($dto, $key))
			{
				$dto->$key = $value;
			}
		}
		return $dto;
	}

	/**
	 * Convert to array (never expose db_password by default!)
	 *
	 * @param bool $include_password
	 * @return array
	 */
	public function to_array(bool $include_password = false): array
	{
		$data = get_object_vars($this);
		if (!$include_password)
		{
			unset($data['db_password']);
		}
		return $data;
	}

	/**
	 * JSON serialization implementation
	 *
	 * @return array
	 */
	public function jsonSerialize(): array
	{
		return $this->to_array(false);
	}

	/**
	 * Debug info redaction for var_dump and print_r
	 *
	 * @return array
	 */
	public function __debugInfo(): array
	{
		$data = $this->to_array(false);
		$data['db_password'] = '[REDACTED]';
		return $data;
	}

	/**
	 * Get an option from config
	 *
	 * @param string $key
	 * @param mixed $default
	 * @return mixed
	 */
	public function get_option(string $key, $default = null)
	{
		if (isset($this->options[$key]))
		{
			return $this->options[$key];
		}
		if (isset($this->$key))
		{
			return $this->$key;
		}
		return $default;
	}
}
