<?php
/**
 * phpBB Migration Center Extension
 *
 * @copyright (c) 2026 phpBB SEO Team
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace phpbbseo\migrationcenter\source\vbulletin\content;

use phpbbseo\migrationcenter\core\dto\migration_config_dto;
use phpbbseo\migrationcenter\source\xenforo\content\xf_conversion_result;

/**
 * Robust, Clean-Room vBulletin 3.8 / 4.2 Message & BBCode Converter
 */
class vb_message_converter
{
	/** @var int Maximum allowed recursion / nesting depth */
	protected $max_nesting_depth = 20;

	/**
	 * Convert a vBulletin raw message into normalized phpBB BBCode and storage text
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

		// 1. Protect and extract Code blocks [CODE]...[/CODE], [PHP]...[/PHP], [HTML]...[/HTML], [HIGHLIGHT]...[/HIGHLIGHT]
		$code_blocks = [];
		$text = preg_replace_callback('/\[CODE(?:=[^\]]*)?\](.*?)\[\/CODE\]/is', function ($matches) use (&$code_blocks) {
			$idx = count($code_blocks);
			$code_blocks[$idx] = "[code]{$matches[1]}[/code]";
			return "___MIGRATIONCENTER_VB_CODE_{$idx}___";
		}, $text);

		$text = preg_replace_callback('/\[PHP\](.*?)\[\/PHP\]/is', function ($matches) use (&$code_blocks) {
			$idx = count($code_blocks);
			$code_blocks[$idx] = "[code=php]{$matches[1]}[/code]";
			return "___MIGRATIONCENTER_VB_CODE_{$idx}___";
		}, $text);

		$text = preg_replace_callback('/\[HTML\](.*?)\[\/HTML\]/is', function ($matches) use (&$code_blocks) {
			$idx = count($code_blocks);
			$code_blocks[$idx] = "[code=html]{$matches[1]}[/code]";
			return "___MIGRATIONCENTER_VB_CODE_{$idx}___";
		}, $text);

		$text = preg_replace_callback('/\[HIGHLIGHT(?:=[^\]]*)?\](.*?)\[\/HIGHLIGHT\]/is', function ($matches) use (&$code_blocks) {
			$idx = count($code_blocks);
			$code_blocks[$idx] = "[code]{$matches[1]}[/code]";
			return "___MIGRATIONCENTER_VB_CODE_{$idx}___";
		}, $text);

		// 2. Process vBulletin Attachments: [ATTACH=CONFIG]123[/ATTACH], [ATTACH]123[/ATTACH], [ATTACH=full]123[/ATTACH]
		$text = preg_replace_callback('/\[ATTACH(?:=[^\]]*)?\]\s*(\d+)\s*\[\/ATTACH\]/i', function ($matches) use ($result) {
			$attach_id = (int)$matches[1];
			if ($attach_id > 0)
			{
				$result->detected_attachments[] = $attach_id;
				return "[attachment={$attach_id}]<!-- ia{$attach_id} -->attachment_{$attach_id}<!-- ia{$attach_id} -->[/attachment]";
			}
			return $matches[0];
		}, $text);

		// 3. Process vBulletin Quotes: [QUOTE=author;12345] -> [quote="author"] or [QUOTE=author] -> [quote="author"]
		for ($i = 0; $i < $this->max_nesting_depth; $i++)
		{
			$prev_text = $text;

			// [QUOTE="author";123] or [QUOTE="author"]
			$text = preg_replace_callback('/\[QUOTE\s*=\s*"([^"]+)"(?:\s*;\s*\d+)?\]/i', function ($m) {
				$author = trim($m[1]);
				return '[quote="' . $author . '"]';
			}, $text);

			// [QUOTE='author';123] or [QUOTE='author']
			$text = preg_replace_callback('/\[QUOTE\s*=\s*\'([^\']+)\'(?:\s*;\s*\d+)?\]/i', function ($m) {
				$author = trim($m[1]);
				return '[quote="' . $author . '"]';
			}, $text);

			// [QUOTE=author;123] or [QUOTE=author]
			$text = preg_replace_callback('/\[QUOTE\s*=\s*([^;\]]+?)(?:\s*;\s*\d+)?\]/i', function ($m) {
				$author = trim(trim($m[1]), '"\'');
				return '[quote="' . $author . '"]';
			}, $text);

			$text = preg_replace('/\[QUOTE\]/i', '[quote]', $text);
			$text = preg_replace('/\[\/QUOTE\]/i', '[/quote]', $text);

			if ($text === $prev_text)
			{
				break;
			}
		}

		// Convert vB numeric font sizes (1 to 7) to phpBB percentage sizes
		$text = preg_replace_callback('/\[SIZE\s*=\s*["\']?(\d+)["\']?\]/i', function ($m) {
			$size = (int)$m[1];
			$map = [
				1 => 85,
				2 => 100,
				3 => 120,
				4 => 150,
				5 => 180,
				6 => 200,
				7 => 240,
			];
			$pct = $map[$size] ?? ($size > 30 ? min(250, $size) : 100);
			return "[size={$pct}]";
		}, $text);
		$text = preg_replace('/\[\/SIZE\]/i', '[/size]', $text);

		// Strip unsupported [FONT=...]...[/FONT] tags while keeping content
		$text = preg_replace('/\[FONT=[^\]]*\]/i', '', $text);
		$text = preg_replace('/\[\/FONT\]/i', '', $text);

		// 4. Process vBulletin Video / Media: [VIDEO=youtube;abc123xyz]...[/VIDEO]
		$text = preg_replace_callback('/\[VIDEO=([a-zA-Z0-9_-]+);([^\]]+)\](.*?)\[\/VIDEO\]/i', function ($m) {
			$provider = strtolower($m[1]);
			$video_id = trim($m[2]);
			if ($provider === 'youtube')
			{
				return "[url]https://www.youtube.com/watch?v={$video_id}[/url]";
			}
			if ($provider === 'vimeo')
			{
				return "[url]https://vimeo.com/{$video_id}[/url]";
			}
			return "[url]{$m[3]}[/url]";
		}, $text);

		// 5. Process Spoiler tags
		$text = preg_replace('/\[SPOILER(?:=[^\]]*)?\]/i', '[spoiler]', $text);
		$text = preg_replace('/\[\/SPOILER\]/i', '[/spoiler]', $text);

		// 6. Process Thread and Post links
		$text = preg_replace('/\[THREAD=(\d+)\](.*?)\[\/THREAD\]/i', '[url=viewtopic.php?t=$1]$2[/url]', $text);
		$text = preg_replace('/\[POST=(\d+)\](.*?)\[\/POST\]/i', '[url=viewtopic.php?p=$1#p$1]$2[/url]', $text);

		// 7. Sanitize raw HTML tags while preserving text formatting
		$text = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '', $text);
		$text = preg_replace('/<iframe\b[^>]*>(.*?)<\/iframe>/is', '', $text);
		$text = preg_replace('/<object\b[^>]*>(.*?)<\/object>/is', '', $text);
		$text = preg_replace('/<embed\b[^>]*>(.*?)<\/embed>/is', '', $text);
		$text = preg_replace('/<applet\b[^>]*>(.*?)<\/applet>/is', '', $text);
		$text = preg_replace('/on[a-zA-Z]+\s*=\s*"[^"]*"/i', '', $text);
		$text = preg_replace('/on[a-zA-Z]+\s*=\s*\'[^\']*\'/i', '', $text);

		// 8. Normalize standard tags
		$standard_tags = ['b', 'i', 'u', 's', 'color', 'size', 'list', 'url', 'img', 'email'];
		foreach ($standard_tags as $st)
		{
			$text = preg_replace_callback("/\[{$st}(?:=([^\]]*))?\]/i", function ($m) use ($st) {
				return isset($m[1]) ? "[{$st}={$m[1]}]" : "[{$st}]";
			}, $text);
			$text = preg_replace("/\[\/{$st}\]/i", "[/{$st}]", $text);
		}

		// 9. Restore protected Code blocks
		foreach ($code_blocks as $idx => $code_block)
		{
			$text = str_replace("___MIGRATIONCENTER_VB_CODE_{$idx}___", $code_block, $text);
		}

		$result->normalized_bbcode = $text;

		// 10. Generate phpBB Storage Formatted Text (s9e\TextFormatter in phpBB 3.2+)
		if (!function_exists('generate_text_for_storage'))
		{
			global $phpbb_root_path, $phpEx;
			if (!empty($phpbb_root_path) && file_exists($phpbb_root_path . 'includes/functions_posting.' . ($phpEx ?: 'php')))
			{
				require_once $phpbb_root_path . 'includes/functions_posting.' . ($phpEx ?: 'php');
			}
		}

		$uid = '';
		$bitfield = '';
		$flags = 0;
		$storage_text = $text;

		if (function_exists('generate_text_for_storage'))
		{
			try
			{
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
		$result->bbcode_uid = $uid;
		$result->bbcode_bitfield = $bitfield;

		return $result;
	}
}
