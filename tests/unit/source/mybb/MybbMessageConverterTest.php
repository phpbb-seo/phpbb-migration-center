<?php
/**
 * phpBB Migration Center Extension
 *
 * @copyright (c) 2026 phpBB SEO Team
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace phpbbseo\migrationcenter\tests\unit\source\mybb;

use phpbbseo\migrationcenter\source\mybb\content\mybb_message_converter;

/**
 * Unit Test for MyBB BBCode & Message Converter
 */
class MybbMessageConverterTest
{
	public function run(): array
	{
		$converter = new mybb_message_converter();
		$results = [];

		// 1. Quotes
		$mybb_quote1 = '[quote=\'Admin\' pid=\'101\' dateline=\'1600000000\']Hello from MyBB![/quote]';
		$conv1 = $converter->convert($mybb_quote1);
		$results['quote_with_pid_dateline'] = (strpos($conv1->normalized_bbcode, '[quote="Admin"]') !== false);

		$mybb_quote2 = '[quote="SarahConnor"]I will be back.[/quote]';
		$conv2 = $converter->convert($mybb_quote2);
		$results['quote_quoted_author'] = (strpos($conv2->normalized_bbcode, '[quote="SarahConnor"]') !== false);

		$mybb_quote3 = '[quote]Anonymous quote[/quote]';
		$conv3 = $converter->convert($mybb_quote3);
		$results['quote_bare'] = (strpos($conv3->normalized_bbcode, '[quote]') !== false);

		// 2. Code & PHP Blocks
		$mybb_code = '[code]<?php echo "hello"; ?>[/code]';
		$conv4 = $converter->convert($mybb_code);
		$results['code_block'] = (strpos($conv4->normalized_bbcode, '[code]') !== false);

		$mybb_php = '[php]echo "php code";[/php]';
		$conv5 = $converter->convert($mybb_php);
		$results['php_block'] = (strpos($conv5->normalized_bbcode, '[code=php]') !== false);

		// 3. Attachments: [attachment=42]
		$mybb_att = 'Please see the attached file: [attachment=42] for details.';
		$conv6 = $converter->convert($mybb_att);
		$results['attachment_detected'] = in_array(42, $conv6->detected_attachments, true);
		$results['attachment_formatted'] = (strpos($conv6->normalized_bbcode, '[attachment=42]') !== false);

		// 4. Video BBCode
		$mybb_video = '[video=youtube]https://www.youtube.com/watch?v=dQw4w9WgXcQ[/video]';
		$conv7 = $converter->convert($mybb_video);
		$results['video_converted_to_url'] = (strpos($conv7->normalized_bbcode, '[url=https://www.youtube.com/watch?v=dQw4w9WgXcQ]') !== false);

		// 5. Size mapping
		$mybb_size = '[size=small]Small text[/size] and [size=large]Large text[/size]';
		$conv8 = $converter->convert($mybb_size);
		$results['size_small'] = (strpos($conv8->normalized_bbcode, '[size=85]') !== false);
		$results['size_large'] = (strpos($conv8->normalized_bbcode, '[size=130]') !== false);

		// 6. Persian / Unicode Preservation
		$persian_text = 'سلام دنیا! این یک متن فارسی با ایموجی 🚀 و نیم‌فاصله (می‌شود) است.';
		$conv9 = $converter->convert($persian_text);
		$results['persian_unicode_preserved'] = ($conv9->normalized_bbcode === $persian_text);

		// 7. HTML Sanitization
		$unsafe_html = 'Dangerous <script>alert("xss")</script> and <iframe src="evil.com"></iframe> content';
		$conv10 = $converter->convert($unsafe_html);
		$results['html_sanitized'] = (strpos($conv10->normalized_bbcode, '<script>') === false && strpos($conv10->normalized_bbcode, '<iframe>') === false);

		return $results;
	}
}
