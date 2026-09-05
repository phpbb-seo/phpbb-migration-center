<?php
/**
 * phpBB Migration Center Extension
 *
 * @copyright (c) 2026 phpBB SEO Team
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace phpbbseo\migrationcenter\source\mybb\content;

use phpbbseo\migrationcenter\core\dto\migration_config_dto;
use phpbbseo\migrationcenter\source\xenforo\content\xf_conversion_result;

/**
 * Robust, Clean-Room MyBB 1.8 Message & BBCode Converter
 */
class mybb_message_converter
{
	/** @var int Maximum allowed recursion / nesting depth */
	protected $max_nesting_depth = 20;

	/**
	 * Convert a MyBB raw message into normalized phpBB BBCode and storage text
	 *
	 * @param string $raw_message
	 * @param migration_config_dto|null $config
	 * @return xf_conversion_result
	 */
	public function convert(string $raw_message, ?migration_config_dto $config = null): xf_conversion_result
	{
		$result = new xf_conversion_result();

		if (trim($raw_message) === '')
		{
			$result->normalized_bbcode = '';
			$result->storage_text = '';
			return $result;
		}

		$text = $raw_message;

		// 1. Protect and extract Code and PHP blocks
		$code_blocks = [];
		$text = preg_replace_callback('/\[CODE(?:=[^\]]*)?\](.*?)\[\/CODE\]/is', function ($matches) use (&$code_blocks) {
			$idx = count($code_blocks);
			$code_blocks[$idx] = "[code]{$matches[1]}[/code]";
			return "___MIGRATIONCENTER_MYBB_CODE_{$idx}___";
		}, $text);

		$text = preg_replace_callback('/\[PHP\](.*?)\[\/PHP\]/is', function ($matches) use (&$code_blocks) {
			$idx = count($code_blocks);
			$code_blocks[$idx] = "[code=php]{$matches[1]}[/code]";
			return "___MIGRATIONCENTER_MYBB_CODE_{$idx}___";
		}, $text);

		// 2. Process MyBB Attachments: [attachment=123]
		$text = preg_replace_callback('/\[attachment\s*=\s*(\d+)\]/i', function ($matches) use ($result) {
			$attach_id = (int)$matches[1];
			if ($attach_id > 0)
			{
				$result->detected_attachments[] = $attach_id;
				return "[attachment={$attach_id}]<!-- ia{$attach_id} -->attachment_{$attach_id}<!-- ia{$attach_id} -->[/attachment]";
			}
			return $matches[0];
		}, $text);

		// 3. Process MyBB Quotes:
		// [quote='author' pid='123' dateline='456'] or [quote="author"] or [quote='author'] or [quote=author] or [quote]
		for ($i = 0; $i < $this->max_nesting_depth; $i++)
		{
			$prev_text = $text;

			// [quote='Author' pid='123' ...] or [quote="Author" pid="123" ...]
			$text = preg_replace_callback('/\[quote\s*=\s*[\'"]([^\'"]+)[\'"](?:\s+pid=[\'"]?\d+[\'"]?)?(?:\s+dateline=[\'"]?\d+[\'"]?)?\]/i', function ($m) {
				$author = trim($m[1]);
				return '[quote="' . $author . '"]';
			}, $text);

			// [quote=Author pid='123' ...] or [quote=Author]
			$text = preg_replace_callback('/\[quote\s*=\s*([^\]\'"\s]+)(?:\s+[^\]]*)?\]/i', function ($m) {
				$author = trim($m[1]);
				return '[quote="' . $author . '"]';
			}, $text);

			if ($prev_text === $text)
			{
				break;
			}
		}

		// 4. Video BBCode: [video=youtube]https://...[/video]
		$text = preg_replace_callback('/\[video=([a-z0-9_-]+)\](.*?)\[\/video\]/i', function ($m) {
			$provider = strtolower($m[1]);
			$url = trim($m[2]);
			return "[url={$url}]{$url}[/url]";
		}, $text);

		// 5. Size Normalization: [size=large], [size=medium], [size=small], [size=xx-large]
		$size_map = [
			'xx-small' => '50',
			'x-small'  => '70',
			'small'    => '85',
			'medium'   => '100',
			'large'    => '130',
			'x-large'  => '170',
			'xx-large' => '200',
		];
		$text = preg_replace_callback('/\[size=([a-z-]+)\]/i', function ($m) use ($size_map) {
			$name = strtolower($m[1]);
			$pct = $size_map[$name] ?? '100';
			return "[size={$pct}]";
		}, $text);

		// Numeric sizes in pt/px or numbers
		$text = preg_replace_callback('/\[size=(\d+)(?:pt|px)?\]/i', function ($m) {
			$val = (int)$m[1];
			if ($val <= 7)
			{
				// MyBB 1-7 scale
				$scales = [1 => 50, 2 => 85, 3 => 100, 4 => 130, 5 => 160, 6 => 200, 7 => 250];
				$pct = $scales[$val] ?? 100;
			}
			else if ($val <= 36)
			{
				// Pt size
				$pct = round(($val / 12) * 100);
			}
			else
			{
				$pct = min(200, max(50, $val));
			}
			return "[size={$pct}]";
		}, $text);

		// 6. Align tags: [align=center] -> [align=center] (standard in phpBB extensions or kept as align)

		// 7. Strip dangerous HTML/scripts
		$text = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '', $text);
		$text = preg_replace('/<iframe\b[^>]*>(.*?)<\/iframe>/is', '', $text);
		$text = preg_replace('/<object\b[^>]*>(.*?)<\/object>/is', '', $text);
		$text = preg_replace('/<embed\b[^>]*>(.*?)<\/embed>/is', '', $text);
		$text = preg_replace('/<applet\b[^>]*>(.*?)<\/applet>/is', '', $text);
		$text = preg_replace('/on[a-zA-Z]+\s*=\s*"[^"]*"/i', '', $text);
		$text = preg_replace('/on[a-zA-Z]+\s*=\s*\'[^\']*\'/i', '', $text);

		// 8. Restore protected Code & PHP blocks
		foreach ($code_blocks as $idx => $code_replacement)
		{
			$text = str_replace("___MIGRATIONCENTER_MYBB_CODE_{$idx}___", $code_replacement, $text);
		}

		$result->normalized_bbcode = $text;

		// 9. Generate phpBB Storage Formatted Text if available
		if (!function_exists('generate_text_for_storage'))
		{
			global $phpbb_root_path, $phpEx;
			if (!empty($phpbb_root_path) && file_exists($phpbb_root_path . 'includes/functions_posting.' . ($phpEx ?: 'php')))
			{
				require_once $phpbb_root_path . 'includes/functions_posting.' . ($phpEx ?: 'php');
			}
		}

		$storage_text = $text;
		if (function_exists('generate_text_for_storage'))
		{
			try
			{
				$uid = '';
				$bitfield = '';
				$flags = 0;
				$allow_bbcode = true;
				$allow_urls = true;
				$allow_smilies = true;
				generate_text_for_storage($storage_text, $uid, $bitfield, $flags, $allow_bbcode, $allow_urls, $allow_smilies);
			}
			catch (\Throwable $e)
			{
				$storage_text = $text;
			}
		}

		$result->storage_text = $storage_text;

		return $result;
	}
}
