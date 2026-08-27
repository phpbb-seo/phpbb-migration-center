<?php
/**
 * XenForo Message & BBCode Converter Unit Test
 */

namespace phpbbseo\migrationcenter\tests\unit;

use phpbbseo\migrationcenter\core\dto\migration_config_dto;
use phpbbseo\migrationcenter\source\xenforo\content\xf_message_converter;
use phpbbseo\migrationcenter\source\xenforo\content\xf_attachment_marker;

class XfMessageConverterTest
{
	public function run()
	{
		$converter = new xf_message_converter();
		$config = new migration_config_dto();

		// 1. Basic formatting (b, i, u, s, color, size)
		$raw1 = "[b]Bold[/b] [i]Italic[/i] [u]Underline[/u] [s]Strike[/s] [color=red]Red[/color] [size=4]Large[/size]";
		$res1 = $converter->convert($raw1, $config);
		if (strpos($res1->normalized_bbcode, '[b]Bold[/b]') === false || strpos($res1->normalized_bbcode, '[color=red]Red[/color]') === false)
		{
			throw new \Exception("Basic formatting BBCode conversion failed");
		}

		// 2. XenForo Quote with post & member IDs -> Standard phpBB quote
		$raw2 = '[QUOTE="Elena_Rov, post: 3, member: 4"]Welcome to our community![/QUOTE]';
		$res2 = $converter->convert($raw2, $config);
		if (strpos($res2->normalized_bbcode, '[quote="Elena_Rov"]') !== 0)
		{
			throw new \Exception("XenForo Quote attribute conversion failed. Got: {$res2->normalized_bbcode}");
		}

		// 3. Nested Quotes
		$raw3 = '[QUOTE="UserA"]First level [QUOTE="UserB, post: 55"]Second level[/QUOTE] Back to first[/QUOTE]';
		$res3 = $converter->convert($raw3, $config);
		if (strpos($res3->normalized_bbcode, '[quote="UserA"]') === false || strpos($res3->normalized_bbcode, '[quote="UserB"]') === false)
		{
			throw new \Exception("Nested quotes conversion failed. Got: {$res3->normalized_bbcode}");
		}

		// 4. Code block with BBCode inside & language attribute
		$raw4 = "[CODE=php]<?php\n// Persian comment: UnicodeRunner\xE2\x80\x8CXXX\n\$var = '[b]Not bold[/b]';\n?>[/CODE]";
		$res4 = $converter->convert($raw4, $config);
		if (strpos($res4->normalized_bbcode, '[code]') !== 0 || strpos($res4->normalized_bbcode, "[b]Not bold[/b]") === false)
		{
			throw new \Exception("Code block protection or language fallback failed. Got: {$res4->normalized_bbcode}");
		}

		// 5. Inline Code
		$raw5 = "Check variable [ICODE]\$config['db'][/ICODE] now";
		$res5 = $converter->convert($raw5, $config);
		if (strpos($res5->normalized_bbcode, "[code]\$config['db'][/code]") === false)
		{
			throw new \Exception("Inline code conversion failed");
		}

		// 6. Spoilers
		$raw6 = '[SPOILER="Ending"]The hero survives[/SPOILER]';
		$res6 = $converter->convert($raw6, $config);
		if (strpos($res6->normalized_bbcode, '[quote="Spoiler: Ending"]') === false)
		{
			throw new \Exception("Spoiler conversion failed. Got: {$res6->normalized_bbcode}");
		}

		// 7. User mentions
		$raw7 = 'Thanks to [USER=87]AloyNora[/USER] for reporting';
		$res7 = $converter->convert($raw7, $config);
		if (strpos($res7->normalized_bbcode, '@AloyNora') === false)
		{
			throw new \Exception("User mention conversion failed. Got: {$res7->normalized_bbcode}");
		}

		// 8. Media tag fallback (YouTube)
		$raw8 = '[MEDIA=youtube]dQw4w9WgXcQ[/MEDIA]';
		$res8 = $converter->convert($raw8, $config);
		if (strpos($res8->normalized_bbcode, 'https://www.youtube.com/watch?v=dQw4w9WgXcQ') === false)
		{
			throw new \Exception("Media conversion failed. Got: {$res8->normalized_bbcode}");
		}

		// 9. Table & Headings
		$raw9 = "[HEADING=1]Server Rules[/HEADING]\n[TABLE][TR][TH]Rule[/TH][TH]Penalty[/TH][/TR][TR][TD]No Spam[/TD][TD]Ban[/TD][/TR][/TABLE]";
		$res9 = $converter->convert($raw9, $config);
		if (strpos($res9->normalized_bbcode, '[b][size=150]Server Rules[/size][/b]') === false || strpos($res9->normalized_bbcode, 'No Spam | ') === false)
		{
			throw new \Exception("Table or Heading conversion failed. Got: {$res9->normalized_bbcode}");
		}

		// 10. Attachment References & Deferred Markers
		$raw10 = "Look at this screenshot: [ATTACH=full]501[/ATTACH] and logs: [ATTACH]502[/ATTACH]";
		$res10 = $converter->convert($raw10, $config);
		if (!in_array(501, $res10->detected_attachments, true) || !in_array(502, $res10->detected_attachments, true))
		{
			throw new \Exception("Attachment detection failed. Detected: " . json_encode($res10->detected_attachments));
		}
		if (strpos($res10->normalized_bbcode, '[[MC_ATTACH:501]]') === false || strpos($res10->normalized_bbcode, '[[MC_ATTACH:502]]') === false)
		{
			throw new \Exception("Attachment deferred marker creation failed. Got: {$res10->normalized_bbcode}");
		}
		// Confirm NO [attachment=0] misuse
		if (strpos($res10->normalized_bbcode, '[attachment=0]') !== false)
		{
			throw new \Exception("CRITICAL VIOLATION: Attachment was improperly converted to [attachment=0]");
		}

		// 11. Malformed non-numeric attachment reference
		$raw11 = "Broken attach tag: [ATTACH]not_a_number[/ATTACH]";
		$res11 = $converter->convert($raw11, $config);
		if (strpos($res11->normalized_bbcode, '[Attachment: not_a_number]') === false || empty($res11->warnings))
		{
			throw new \Exception("Malformed attachment handling failed");
		}

		// 12. Dangerous URL/IMG schemes (javascript:, data:)
		$raw12 = 'Unsafe image: [IMG]javascript:alert(1)[/IMG] and [URL=javascript:void(0)]Click[/URL]';
		$res12 = $converter->convert($raw12, $config);
		if (strpos($res12->normalized_bbcode, 'javascript:') !== false)
		{
			throw new \Exception("Dangerous URL scheme was not neutralized!");
		}

		// 13. Persian, Arabic, ZWNJ, and Emoji preservation
		$raw13 = "Hello XX XXX XXXXXX! XYX YK XXX XXXY XX Unicode (UnicodeRunner\xE2\x80\x8CXXX) X XXXXY 🚀 XXX.\n[quote=\"System_Admin\"]Important Topic for Staff[/quote]";
		$res13 = $converter->convert($raw13, $config);
		if (strpos($res13->normalized_bbcode, "UnicodeRunner\xE2\x80\x8CXXX") === false || strpos($res13->normalized_bbcode, "🚀") === false || strpos($res13->normalized_bbcode, '[quote="System_Admin"]') === false)
		{
			throw new \Exception("Unicode / RTL text preservation failed in message conversion");
		}

		return true;
	}
}
