<?php
/**
 * phpBB Migration Center Extension
 *
 * @copyright (c) 2026 phpBB SEO Team
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace phpbbseo\migrationcenter\source\smf\content;

use phpbbseo\migrationcenter\core\dto\migration_config_dto;
use phpbbseo\migrationcenter\source\xenforo\content\xf_conversion_result;

/**
 * Clean-Room SMF 2.x Message & BBCode Converter
 */
class smf_message_converter
{
	/** @var int Maximum allowed recursion / nesting depth */
	protected $max_nesting_depth = 20;

	/**
	 * Convert an SMF raw message into normalized phpBB BBCode and storage text
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

		// 1. Remove "Code:<br>" or "Code:\n" or "کد:<br>" preceding [pre] or [code]
		$text = preg_replace('/(?:Code|کد):\s*(?:<br\s*\/?>|\r?\n)*/iu', '', $text);

		// 2. Convert [pre]...[/pre] to [code]...[/code] with entity decoding and linebreak normalization
		$text = preg_replace_callback('/\[pre\](.*?)\[\/pre\]/is', function ($m) {
			$code = $m[1];
			$code = preg_replace('#<br\s*/?>#i', "\n", $code);
			$code = html_entity_decode($code, ENT_QUOTES | ENT_HTML5, 'UTF-8');
			return '[code]' . trim($code) . '[/code]';
		}, $text);

		// 3. Normalize existing [code] blocks (decode entities and convert internal <br> to \n)
		$text = preg_replace_callback('/\[code(?:=[^\]]*)?\](.*?)\[\/code\]/is', function ($m) {
			$code = $m[1];
			$code = preg_replace('#<br\s*/?>#i', "\n", $code);
			$code = html_entity_decode($code, ENT_QUOTES | ENT_HTML5, 'UTF-8');
			return '[code]' . trim($code) . '[/code]';
		}, $text);

		// 4. Convert and normalize [php]...[/php] blocks
		$code_blocks = [];
		$text = preg_replace_callback('/\[php\](.*?)\[\/php\]/is', function ($matches) use (&$code_blocks) {
			$idx = count($code_blocks);
			$code = preg_replace('#<br\s*/?>#i', "\n", $matches[1]);
			$code = html_entity_decode($code, ENT_QUOTES | ENT_HTML5, 'UTF-8');
			$code_blocks[$idx] = '[code=php]' . trim($code) . '[/code]';
			return "___MIGRATIONCENTER_SMF_CODE_{$idx}___";
		}, $text);

		// Protect all [code] and [code=...] blocks
		$text = preg_replace_callback('/\[code(?:=[^\]]*)?\](.*?)\[\/code\]/is', function ($matches) use (&$code_blocks) {
			$idx = count($code_blocks);
			$code_blocks[$idx] = $matches[0];
			return "___MIGRATIONCENTER_SMF_CODE_{$idx}___";
		}, $text);

		// 5. Convert HTML linebreaks to newlines in message text
		$text = preg_replace('#<br\s*/?>#i', "\n", $text);

		// 6. Decode standard SMF HTML entities in body text
		$text = str_replace(
			['&nbsp;', '&quot;', '&#039;', '&#39;', '&apos;', '&lt;', '&gt;', '&amp;'],
			[' ', '"', "'", "'", "'", '<', '>', '&'],
			$text
		);

		// 7. Process Horizontal rules: <hr> or [hr] or [hr/] -> [hr]
		$text = preg_replace('#<hr\s*/?>#i', '[hr]', $text);
		$text = preg_replace('#\[hr\s*/?\]#i', '[hr]', $text);

		// 8. Process SMF Lists and List Items:
		// [list type=decimal] or [list type=1] -> [list=1]
		$text = preg_replace('/\[list\s+type=(?:decimal|decimal-leading-zero|1)\]/i', '[list=1]', $text);
		$text = preg_replace('/\[list\s+type=(?:lower-alpha|lower-latin|a)\]/i', '[list=a]', $text);
		$text = preg_replace('/\[list\s+type=(?:upper-alpha|upper-latin|A)\]/i', '[list=A]', $text);
		$text = preg_replace('/\[list\s+type=(?:lower-roman|i)\]/i', '[list=i]', $text);
		$text = preg_replace('/\[list\s+type=(?:upper-roman|I)\]/i', '[list=I]', $text);
		// Any other list type (disc, circle, square, none) -> standard [list]
		$text = preg_replace('/\[list\s+type=[^\]]+\]/i', '[list]', $text);

		// Convert [li]...[/li] -> [*]...
		$text = preg_replace('/\[li\]\s*/i', '[*]', $text);
		$text = preg_replace('/\s*\[\/li\]/i', '', $text);

		// 9. Process SMF Attachments: [attach]123[/attach] or [attach=123] or [attach id=123] or [attachment=123]
		// Pattern A: [attach]123[/attach]
		$text = preg_replace_callback('/\[attach(?:ment)?\](\d+)\[\/attach(?:ment)?\]/is', function ($matches) use ($result) {
			$attach_id = (int)$matches[1];
			if ($attach_id > 0)
			{
				$result->detected_attachments[] = $attach_id;
				return "[attachment={$attach_id}]<!-- ia{$attach_id} -->attachment_{$attach_id}<!-- ia{$attach_id} -->[/attachment]";
			}
			return $matches[0];
		}, $text);

		// Pattern B: [attach=123]...[/attach] or [attach id=123]...[/attach] or bare [attach=123] / [attachment=123]
		$text = preg_replace_callback('/\[attach(?:ment)?(?:\s*=\s*|\s+id=)(\d+)\](?:.*?\[\/attach(?:ment)?\])?/is', function ($matches) use ($result) {
			$attach_id = (int)$matches[1];
			if ($attach_id > 0)
			{
				$result->detected_attachments[] = $attach_id;
				return "[attachment={$attach_id}]<!-- ia{$attach_id} -->attachment_{$attach_id}<!-- ia{$attach_id} -->[/attachment]";
			}
			return $matches[0];
		}, $text);

		// 10. Process SMF Quotes:
		// [quote author=User link=topic=... date=...] or [quote author="User" ...] or [quote=User] or [quote]
		for ($i = 0; $i < $this->max_nesting_depth; $i++)
		{
			$prev_text = $text;

			// [quote author="Author" ...] or [quote author='Author' ...]
			$text = preg_replace_callback('/\[quote\s+author=[\'"]([^\'"]+)[\'"][^\]]*\]/i', function ($m) {
				$author = trim($m[1]);
				return '[quote="' . $author . '"]';
			}, $text);

			// [quote author=Author ...]
			$text = preg_replace_callback('/\[quote\s+author=([^\s\]]+)[^\]]*\]/i', function ($m) {
				$author = trim($m[1]);
				return '[quote="' . $author . '"]';
			}, $text);

			// [quote=Author] or [quote="Author"]
			$text = preg_replace_callback('/\[quote\s*=\s*[\'"]?([^\'"\]]+)[\'"]?\]/i', function ($m) {
				$author = trim($m[1]);
				return '[quote="' . $author . '"]';
			}, $text);

			if ($prev_text === $text)
			{
				break;
			}
		}

		// 11. Links & Images
		// SMF iurl (internal url) -> standard url
		$text = preg_replace('/\[iurl=([^\]]+)\](.*?)\[\/iurl\]/is', '[url=$1]$2[/url]', $text);
		$text = preg_replace('/\[iurl\](.*?)\[\/iurl\]/is', '[url]$1[/url]', $text);
		$text = preg_replace('/\[ftp=([^\]]+)\](.*?)\[\/ftp\]/is', '[url=$1]$2[/url]', $text);
		$text = preg_replace('/\[ftp\](.*?)\[\/ftp\]/is', '[url]$1[/url]', $text);

		// [img width=... height=...] -> standard [img]
		$text = preg_replace('/\[img\b[^\]]*\](.*?)\[\/img\]/is', '[img]$1[/img]', $text);

		// Video / Youtube BBCode
		$text = preg_replace('/\[youtube\](.*?)\[\/youtube\]/is', '[url=$1]$1[/url]', $text);

		// 12. Text styles: [strike] -> [s]
		$text = preg_replace('/\[strike\](.*?)\[\/strike\]/is', '[s]$1[/s]', $text);

		// 13. Mentions: [member=123]Name[/member] -> @Name
		$text = preg_replace('/\[member=\d+\](.*?)\[\/member\]/is', '@$1', $text);

		// 14. Tables -> Structured layout
		$text = preg_replace('/\[table\]/i', "\n", $text);
		$text = preg_replace('/\[\/table\]/i', "\n", $text);
		$text = preg_replace('/\[tr\]/i', '', $text);
		$text = preg_replace('/\[\/tr\]/i', "\n", $text);
		$text = preg_replace('/\[th\](.*?)\[\/th\]/is', '[b]$1[/b] | ', $text);
		$text = preg_replace('/\[td\](.*?)\[\/td\]/is', '$1 | ', $text);

		// 15. Alignment & Fonts:
		$text = preg_replace('/\[left\](.*?)\[\/left\]/is', '$1', $text);
		$text = preg_replace('/\[right\](.*?)\[\/right\]/is', '$1', $text);
		$text = preg_replace('/\[justify\](.*?)\[\/justify\]/is', '$1', $text);
		$text = preg_replace('/\[font=[^\]]*\](.*?)\[\/font\]/is', '$1', $text);

		// 16. Size Normalization: [size=12pt], [size=14px], [size=150%], [size=2em]
		$text = preg_replace_callback('/\[size=([0-9\.]+)(pt|px|em|%)?\]/i', function ($m) {
			$val = (float)$m[1];
			$unit = strtolower($m[2] ?? '');

			if ($unit === '%' || empty($unit))
			{
				$pct = (int)round($val);
			}
			else if ($unit === 'pt')
			{
				$pct = (int)round(($val / 10.0) * 100);
			}
			else if ($unit === 'px')
			{
				$pct = (int)round(($val / 13.0) * 100);
			}
			else if ($unit === 'em')
			{
				$pct = (int)round($val * 100);
			}
			else
			{
				$pct = 100;
			}

			$pct = max(50, min(250, $pct));
			return "[size={$pct}]";
		}, $text);

		// 17. Strip dangerous HTML/scripts
		$text = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '', $text);
		$text = preg_replace('/<iframe\b[^>]*>(.*?)<\/iframe>/is', '', $text);
		$text = preg_replace('/<object\b[^>]*>(.*?)<\/object>/is', '', $text);

		// 18. Restore protected code blocks
		foreach ($code_blocks as $idx => $block)
		{
			$text = str_replace("___MIGRATIONCENTER_SMF_CODE_{$idx}___", $block, $text);
		}

		$text = trim($text);
		$result->normalized_bbcode = $text;

		// 19. Compile to phpBB Storage format
		$uid = '';
		$bitfield = '';
		$flags = 0;
		$storage_text = $text;

		if (!function_exists('generate_text_for_storage'))
		{
			global $phpbb_root_path, $phpEx;
			if (!empty($phpbb_root_path) && file_exists($phpbb_root_path . 'includes/functions_posting.' . ($phpEx ?: 'php')))
			{
				require_once $phpbb_root_path . 'includes/functions_posting.' . ($phpEx ?: 'php');
			}
		}

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
				$storage_text = $this->compile_to_storage_text($text);
			}
		}
		else
		{
			$storage_text = $this->compile_to_storage_text($text);
		}

		$result->storage_text = $storage_text;
		$result->bbcode_uid = $uid;
		$result->bbcode_bitfield = $bitfield;

		return $result;
	}

	/**
	 * Compile normalized BBCode to phpBB s9e\TextFormatter XML
	 *
	 * @param string $bbcode_text
	 * @return string
	 */
	protected function compile_to_storage_text(string $bbcode_text): string
	{
		if (class_exists('\\s9e\\TextFormatter\\Bundles\\Forum'))
		{
			try
			{
				return \s9e\TextFormatter\Bundles\Forum::parse($bbcode_text);
			}
			catch (\Throwable $e)
			{
				// Fallback to plain XML wrapper
			}
		}

		return '<r>' . htmlspecialchars($bbcode_text, ENT_QUOTES, 'UTF-8') . '</r>';
	}
}
