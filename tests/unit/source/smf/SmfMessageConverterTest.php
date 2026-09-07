<?php
/**
 * phpBB Migration Center Extension
 *
 * @copyright (c) 2026 phpBB SEO Team
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace phpbbseo\migrationcenter\tests\unit\source\smf;

use phpbbseo\migrationcenter\source\smf\content\smf_message_converter;

/**
 * Unit Test for SMF 2.x BBCode & Message Converter
 */
class SmfMessageConverterTest
{
	public function run(): array
	{
		$converter = new smf_message_converter();
		$results = [];

		// 1. Quotes with author, link, date parameters
		$smf_quote1 = '[quote author=Admin link=topic=12.msg45#msg45 date=1600000000]Hello from SMF 2.1![/quote]';
		$conv1 = $converter->convert($smf_quote1);
		$results['quote_with_author_link_date'] = (strpos($conv1->normalized_bbcode, '[quote="Admin"]') !== false);

		$smf_quote2 = '[quote author="SarahConnor"]No fate but what we make.[/quote]';
		$conv2 = $converter->convert($smf_quote2);
		$results['quote_quoted_author'] = (strpos($conv2->normalized_bbcode, '[quote="SarahConnor"]') !== false);

		$smf_quote3 = '[quote]Anonymous quote[/quote]';
		$conv3 = $converter->convert($smf_quote3);
		$results['quote_bare'] = (strpos($conv3->normalized_bbcode, '[quote]') !== false);

		// 2. Linebreaks: SMF stores <br /> in message bodies
		$smf_br = "Line 1<br />Line 2<br>Line 3";
		$conv_br = $converter->convert($smf_br);
		$results['br_converted_to_newline'] = ($conv_br->normalized_bbcode === "Line 1\nLine 2\nLine 3");

		// 3. Code & PHP blocks
		$smf_code = '[code]<?php echo "hello world"; ?>[/code]';
		$conv4 = $converter->convert($smf_code);
		$results['code_block'] = (strpos($conv4->normalized_bbcode, '[code]') !== false);

		$smf_php = '[php]echo "hello php";[/php]';
		$conv5 = $converter->convert($smf_php);
		$results['php_block'] = (strpos($conv5->normalized_bbcode, '[code=php]') !== false);

		// 4. Attachments: [attach]42[/attach] or [attach id=42]
		$smf_att1 = 'See attached log: [attach]42[/attach] for details.';
		$conv6 = $converter->convert($smf_att1);
		$results['attach_tag_detected'] = in_array(42, $conv6->detected_attachments, true);
		$results['attach_tag_normalized'] = (strpos($conv6->normalized_bbcode, '[attachment=42]') !== false);

		$smf_att2 = 'Another attachment: [attach id=88]image.png[/attach]';
		$conv7 = $converter->convert($smf_att2);
		$results['attach_id_detected'] = in_array(88, $conv7->detected_attachments, true);

		// 5. Size conversions: [size=14pt] / [size=12px]
		$smf_size = '[size=14pt]Large text[/size]';
		$conv8 = $converter->convert($smf_size);
		$results['size_pt_converted'] = (strpos($conv8->normalized_bbcode, '[size=') !== false && strpos($conv8->normalized_bbcode, '14pt') === false);

		// 6. Persian / Unicode & Emoji Preservation
		$persian_text = 'سلام دنیا! این یک متن فارسی با ایموجی 🚀 و نیم‌فاصله (می‌شود) است.';
		$conv9 = $converter->convert($persian_text);
		$results['persian_unicode_preserved'] = ($conv9->normalized_bbcode === $persian_text);

		// 7. HTML Sanitization
		$unsafe_html = 'Malicious <script>alert("xss")</script> and <iframe src="evil.com"></iframe> tag';
		$conv10 = $converter->convert($unsafe_html);
		$results['html_sanitized'] = (strpos($conv10->normalized_bbcode, '<script>') === false && strpos($conv10->normalized_bbcode, '<iframe>') === false);

		// 8. SMF Pre and Code prefixes: Code:<br>[pre]...[/pre] -> [code]...[/code]
		$smf_pre = "Code:<br>[pre]&lt;?php<br>echo 'hi';<br>?&gt;[/pre]";
		$conv_pre = $converter->convert($smf_pre);
		$results['pre_to_code_converted'] = (strpos($conv_pre->normalized_bbcode, "[code]<?php\necho 'hi';\n?>[/code]") !== false);
		$results['code_prefix_removed'] = (strpos($conv_pre->normalized_bbcode, 'Code:') === false);

		// 9. SMF Lists: [list type=decimal] with [li]...[/li]
		$smf_list = "[list type=decimal]\n[li]First item[/li]\n[li]Second item[/li]\n[/list]";
		$conv_list = $converter->convert($smf_list);
		$results['list_decimal_normalized'] = (strpos($conv_list->normalized_bbcode, '[list=1]') !== false);
		$results['list_li_converted'] = (strpos($conv_list->normalized_bbcode, '[*]First item') !== false && strpos($conv_list->normalized_bbcode, '[/li]') === false);

		// 10. Horizontal rule: <hr> or [hr]
		$smf_hr = "Section 1<hr>Section 2[hr]Section 3";
		$conv_hr = $converter->convert($smf_hr);
		$results['hr_normalized'] = (substr_count($conv_hr->normalized_bbcode, '[hr]') === 2);

		// 11. Strikethrough, Mentions, and Images
		$smf_misc = "[strike]strike text[/strike] and [member=42]JohnDoe[/member] and [img width=100 height=50]https://example.com/pic.jpg[/img]";
		$conv_misc = $converter->convert($smf_misc);
		$results['strike_to_s'] = (strpos($conv_misc->normalized_bbcode, '[s]strike text[/s]') !== false);
		$results['member_to_mention'] = (strpos($conv_misc->normalized_bbcode, '@JohnDoe') !== false);
		$results['img_dimensions_cleaned'] = (strpos($conv_misc->normalized_bbcode, '[img]https://example.com/pic.jpg[/img]') !== false);

		return $results;
	}
}
