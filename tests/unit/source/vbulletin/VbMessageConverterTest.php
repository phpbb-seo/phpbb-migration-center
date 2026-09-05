<?php
/**
 * phpBB Migration Center Extension
 *
 * @copyright (c) 2026 phpBB SEO Team
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace phpbbseo\migrationcenter\tests\unit\source\vbulletin;

use phpbbseo\migrationcenter\source\vbulletin\content\vb_message_converter;

/**
 * Unit Test for vBulletin BBCode & Message Converter
 */
class VbMessageConverterTest
{
	public function run(): array
	{
		$converter = new vb_message_converter();
		$results = [];

		// 1. Test Quote Conversion
		$vb_quote = '[QUOTE=JohnDoe;123456]Hello from vBulletin![/QUOTE]';
		$conv1 = $converter->convert($vb_quote);
		$results['quote_with_postid'] = (strpos($conv1->normalized_bbcode, '[quote="JohnDoe"]') !== false);

		$vb_quote2 = '[quote="SpockVulcan"]سلام دوستان، در سرورهای با ترافیک بالا، چه روشی را برای مدیر...[/quote]';
		$conv1b = $converter->convert($vb_quote2);
		$results['quote_quoted_author'] = (strpos($conv1b->normalized_bbcode, '[quote="SpockVulcan"]') !== false);

		$vb_quote3 = '[quote=\'SpockVulcan\']سلام دوستان[/quote]';
		$conv1c = $converter->convert($vb_quote3);
		$results['quote_single_quote_author'] = (strpos($conv1c->normalized_bbcode, '[quote="SpockVulcan"]') !== false);

		$vb_quote4 = '[quote]بدون نام نویسنده[/quote]';
		$conv1d = $converter->convert($vb_quote4);
		$results['quote_bare'] = (strpos($conv1d->normalized_bbcode, '[quote]') !== false);

		// Size & Font
		$vb_size = '[size=4][font=Tahoma]متن بزرگ[/font][/size]';
		$conv_size = $converter->convert($vb_size);
		$results['size_and_font'] = (strpos($conv_size->normalized_bbcode, '[size=150]متن بزرگ[/size]') !== false);

		// 2. Test Code & PHP Block Conversion
		$vb_php = '[PHP]<?php echo "test"; ?>[/PHP]';
		$conv2 = $converter->convert($vb_php);
		$results['php_code_block'] = (strpos($conv2->normalized_bbcode, '[code=php]') !== false);

		// 3. Test Attachments Conversion
		$vb_attach = 'Look at this [ATTACH=CONFIG]42[/ATTACH] image.';
		$conv3 = $converter->convert($vb_attach);
		$results['attach_conversion'] = in_array(42, $conv3->detected_attachments, true);

		// 4. Test Video Conversion
		$vb_video = '[VIDEO=youtube;dQw4w9WgXcQ]https://www.youtube.com/watch?v=dQw4w9WgXcQ[/VIDEO]';
		$conv4 = $converter->convert($vb_video);
		$results['youtube_video'] = (strpos($conv4->normalized_bbcode, 'https://www.youtube.com/watch?v=dQw4w9WgXcQ') !== false);

		// 5. Test Unicode / Persian / Arabic / Emoji preservation
		$persian_text = 'سلام دنیا! این یک متن تستی با ایموجی 🚀 و نیم‌فاصله (می‌شود) است.';
		$conv5 = $converter->convert($persian_text);
		$results['persian_unicode'] = ($conv5->normalized_bbcode === $persian_text);

		// 6. Test HTML Sanitization
		$unsafe_html = 'Dangerous <script>alert("xss")</script> and <iframe src="evil.com"></iframe> content';
		$conv6 = $converter->convert($unsafe_html);
		$results['html_sanitization'] = (strpos($conv6->normalized_bbcode, '<script>') === false && strpos($conv6->normalized_bbcode, '<iframe>') === false);

		return $results;
	}
}
