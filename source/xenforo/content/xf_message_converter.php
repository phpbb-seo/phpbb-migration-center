<?php
/**
 * phpBB Migration Center Extension
 *
 * @copyright (c) 2026 phpBB SEO Team
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace phpbbseo\migrationcenter\source\xenforo\content;

use phpbbseo\migrationcenter\core\dto\migration_config_dto;

/**
 * Robust, Clean-Room XenForo Message & BBCode Converter
 */
class xf_message_converter
{
	/** @var int Maximum allowed recursion / nesting depth */
	protected $max_nesting_depth = 20;

	/**
	 * Convert a XenForo raw message into normalized phpBB BBCode and storage text
	 *
	 * @param string $raw_message
	 * @param migration_config_dto|null $config
	 * @return xf_conversion_result
	 */
	public function convert(string $raw_message, ?migration_config_dto $config = null): xf_conversion_result
	{
		$result = new xf_conversion_result();
		$unknown_tag_policy = $config ? ($config->options['unknown_tag_policy'] ?? 'strip') : 'strip';

		if (trim($raw_message) === '')
		{
			$result->normalized_bbcode = '';
			$result->storage_text = '';
			return $result;
		}

		$text = $raw_message;

		// 1. Protect and extract Code blocks [CODE]...[/CODE] (including [CODE=php], etc.)
		$code_blocks = [];
		$text = preg_replace_callback('/\[CODE(?:=[^\]]*)?\](.*?)\[\/CODE\]/is', function ($matches) use (&$code_blocks) {
			$idx = count($code_blocks);
			$code_blocks[$idx] = $matches[1];
			return "___MIGRATIONCENTER_CODE_BLOCK_{$idx}___";
		}, $text);

		// 2. Protect and convert Inline Code [ICODE]...[/ICODE]
		$text = preg_replace_callback('/\[ICODE\](.*?)\[\/ICODE\]/is', function ($matches) use (&$code_blocks) {
			$idx = count($code_blocks);
			$code_blocks[$idx] = $matches[1];
			return "___MIGRATIONCENTER_CODE_BLOCK_{$idx}___";
		}, $text);

		// 3. Process XenForo Attachments: [ATTACH=full]123[/ATTACH] and [ATTACH]123[/ATTACH]
		$text = preg_replace_callback('/\[ATTACH(?:=[^\]]*)?\]\s*(\d+)\s*\[\/ATTACH\]/i', function ($matches) use ($result) {
			$attach_id = (int)$matches[1];
			if ($attach_id > 0)
			{
				$result->detected_attachments[] = $attach_id;
				return xf_attachment_marker::create_marker($attach_id);
			}
			return $matches[0];
		}, $text);

		// Handle malformed non-numeric attachment tags
		$text = preg_replace_callback('/\[ATTACH(?:=[^\]]*)?\](.*?)\[\/ATTACH\]/i', function ($matches) use ($result) {
			$inner = trim($matches[1]);
			if (!ctype_digit($inner))
			{
				$result->warnings[] = "Malformed non-numeric ATTACH tag: {$matches[0]}";
				return "[Attachment: {$inner}]";
			}
			return $matches[0];
		}, $text);

		// 4. Process XenForo Quotes: [QUOTE="Username, post: 1234, member: 56"] -> [quote="Username"]
		// Iterative loop to handle nested quotes up to max_nesting_depth
		for ($i = 0; $i < $this->max_nesting_depth; $i++)
		{
			$prev_text = $text;

			// Quote with attributes (Author + post/member IDs)
			$text = preg_replace_callback('/\[QUOTE="([^",\]]+)(?:,\s*[^"\]]+)?"\]/i', function ($m) {
				$author = trim($m[1]);
				return '[quote="' . $author . '"]';
			}, $text);

			// Quote with single attribute without quotes [QUOTE=Username]
			$text = preg_replace_callback('/\[QUOTE=([^",\]]+)\]/i', function ($m) {
				$author = trim($m[1]);
				return '[quote="' . $author . '"]';
			}, $text);

			// Plain [QUOTE]
			$text = preg_replace('/\[QUOTE\]/i', '[quote]', $text);
			$text = preg_replace('/\[\/QUOTE\]/i', '[/quote]', $text);

			if ($text === $prev_text)
			{
				break;
			}
		}

		// 5. Process User mentions: [USER=123]Username[/USER] -> @Username
		$text = preg_replace('/\[USER=\d+\](.*?)\[\/USER\]/i', '@$1', $text);

		// 6. Process Spoilers: [SPOILER="Title"]...[/SPOILER] -> [quote="Spoiler: Title"]...[/quote]
		$text = preg_replace('/\[SPOILER="([^"\]]+)"\]/i', '[quote="Spoiler: $1"]', $text);
		$text = preg_replace('/\[SPOILER\]/i', '[quote="Spoiler"]', $text);
		$text = preg_replace('/\[\/SPOILER\]/i', '[/quote]', $text);

		// 7. Process Headings: [HEADING=1]Title[/HEADING] -> [b][size=150]Title[/size][/b]
		$text = preg_replace('/\[HEADING=1\](.*?)\[\/HEADING\]/is', '[b][size=150]$1[/size][/b]', $text);
		$text = preg_replace('/\[HEADING=2\](.*?)\[\/HEADING\]/is', '[b][size=125]$1[/size][/b]', $text);
		$text = preg_replace('/\[HEADING=3\](.*?)\[\/HEADING\]/is', '[b]$1[/b]', $text);
		$text = preg_replace('/\[HEADING\](.*?)\[\/HEADING\]/is', '[b]$1[/b]', $text);

		// 8. Process Media: [MEDIA=youtube]ID[/MEDIA] -> https://www.youtube.com/watch?v=ID
		$text = preg_replace_callback('/\[MEDIA=([a-z0-9_-]+)\](.*?)\[\/MEDIA\]/i', function ($m) use ($result) {
			$provider = strtolower($m[1]);
			$media_id = trim($m[2]);

			if ($provider === 'youtube')
			{
				return "https://www.youtube.com/watch?v={$media_id}";
			}
			else if ($provider === 'vimeo')
			{
				return "https://vimeo.com/{$media_id}";
			}

			$result->unsupported_tags[] = "MEDIA:{$provider}";
			return "[Media: {$provider} - {$media_id}]";
		}, $text);

		// 9. Process Tables -> Structured Text
		$text = preg_replace('/\[TABLE\]/i', "\n", $text);
		$text = preg_replace('/\[\/TABLE\]/i', "\n", $text);
		$text = preg_replace('/\[TR\]/i', '', $text);
		$text = preg_replace('/\[\/TR\]/i', "\n", $text);
		$text = preg_replace('/\[TH\](.*?)\[\/TH\]/is', '[b]$1[/b] | ', $text);
		$text = preg_replace('/\[TD\](.*?)\[\/TD\]/is', '$1 | ', $text);

		// 10. Sanitize URLs & IMGs from dangerous schemes (javascript:, data:, file:, etc.)
		$text = preg_replace_callback('/\[(URL|IMG)(?:=([^\]]+))?\](.*?)\[\/\1\]/is', function ($m) use ($result) {
			$tag = strtoupper($m[1]);
			$attr_url = isset($m[2]) ? trim($m[2]) : '';
			$content_url = trim($m[3]);
			$check_url = $attr_url ?: $content_url;

			$scheme = parse_url($check_url, PHP_URL_SCHEME);
			$lower_scheme = strtolower((string)$scheme);

			if ($lower_scheme !== '' && !in_array($lower_scheme, ['http', 'https', 'ftp', 'mailto'], true))
			{
				$result->warnings[] = "Neutralized dangerous {$tag} scheme: {$lower_scheme}";
				return "[Unsafe Link Removed]";
			}

			return $m[0];
		}, $text);

		// 11. Normalize Standard BBCode tag cases (B, I, U, S, COLOR, SIZE, LIST, URL, IMG, EMAIL)
		$standard_tags = ['b', 'i', 'u', 's', 'color', 'size', 'list', 'url', 'img', 'email'];
		foreach ($standard_tags as $st)
		{
			$text = preg_replace_callback("/\[{$st}(?:=([^\]]*))?\]/i", function ($m) use ($st) {
				return isset($m[1]) ? "[{$st}={$m[1]}]" : "[{$st}]";
			}, $text);
			$text = preg_replace("/\[\/{$st}\]/i", "[/{$st}]", $text);
		}

		// 12. Restore Protected Code blocks
		foreach ($code_blocks as $idx => $code_content)
		{
			$placeholder = "___MIGRATIONCENTER_CODE_BLOCK_{$idx}___";
			$text = str_replace($placeholder, "[code]{$code_content}[/code]", $text);
		}

		$result->normalized_bbcode = $text;
		$result->detected_attachments = array_values(array_unique($result->detected_attachments));

		// 13. Prepare phpBB Storage Formatted Text
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
				$result->warnings[] = "phpBB parser exception: " . $e->getMessage();
				$storage_text = $text;
			}
		}

		$result->storage_text = $storage_text;
		$result->bbcode_uid = $uid;
		$result->bbcode_bitfield = $bitfield;

		if (!empty($result->warnings) || !empty($result->unsupported_tags))
		{
			$result->status = 'warning';
		}

		return $result;
	}
}
