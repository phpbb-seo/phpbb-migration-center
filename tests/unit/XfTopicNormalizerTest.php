<?php
/**
 * XenForo Topic Normalizer, Types, Prefixes & Unicode Unit Test
 */

namespace phpbbseo\migrationcenter\tests\unit;

use phpbbseo\migrationcenter\core\dto\migration_config_dto;
use phpbbseo\migrationcenter\source\xenforo\normalizer\xf_topic_normalizer;

class XfTopicNormalizerTest
{
	public function run()
	{
		$normalizer = new xf_topic_normalizer();
		$normalizer->set_prefix_cache([
			1 => 'News',
			2 => "XXXXXYX_XXX_UnicodeRunner\xE2\x80\x8CXXX", // Persian with ZWNJ
			3 => 'Urgent_Notice_Prefix', // Arabic
		]);

		$config = new migration_config_dto();
		$config->options = [
			'prefix_policy'       => 'prepend_title',
			'unknown_type_policy' => 'normal_with_warning',
		];

		// 1. Normal topic
		$row1 = [
			'thread_id'        => 101,
			'node_id'          => 14,
			'user_id'          => 5,
			'username'         => 'TestUser',
			'title'            => 'Standard Discussion Topic',
			'discussion_type'  => 'discussion',
			'discussion_state' => 'visible',
			'discussion_open'  => 1,
			'sticky'           => 0,
			'reply_count'      => 5,
			'view_count'       => 100,
			'first_post_id'    => 1001,
			'last_post_id'     => 1006,
		];
		$t1 = $normalizer->normalize_thread($row1, $config);
		if ($t1->topic_type !== 0 || $t1->topic_status !== 0 || $t1->topic_visibility !== 1 || $t1->first_post_source_id !== 1001)
		{
			throw new \Exception("Normal topic normalization failed");
		}

		// 2. Sticky topic
		$row2 = $row1;
		$row2['thread_id'] = 102;
		$row2['sticky'] = 1;
		$t2 = $normalizer->normalize_thread($row2, $config);
		if ($t2->topic_type !== 1)
		{
			throw new \Exception("Sticky topic must have topic_type = 1 (POST_STICKY)");
		}

		// 3. Locked topic
		$row3 = $row1;
		$row3['thread_id'] = 103;
		$row3['discussion_open'] = 0;
		$t3 = $normalizer->normalize_thread($row3, $config);
		if ($t3->topic_status !== 1)
		{
			throw new \Exception("Locked topic must have topic_status = 1 (ITEM_LOCKED)");
		}

		// 4. Sticky and locked topic
		$row4 = $row1;
		$row4['thread_id'] = 104;
		$row4['sticky'] = 1;
		$row4['discussion_open'] = 0;
		$t4 = $normalizer->normalize_thread($row4, $config);
		if ($t4->topic_type !== 1 || $t4->topic_status !== 1)
		{
			throw new \Exception("Sticky & locked topic flags mismatch");
		}

		// 5. Moderated topic
		$row5 = $row1;
		$row5['thread_id'] = 105;
		$row5['discussion_state'] = 'moderated';
		$t5 = $normalizer->normalize_thread($row5, $config);
		if ($t5->topic_visibility !== 0)
		{
			throw new \Exception("Moderated topic must have topic_visibility = 0 (ITEM_UNAPPROVED)");
		}

		// 6. Soft-deleted topic
		$row6 = $row1;
		$row6['thread_id'] = 106;
		$row6['discussion_state'] = 'deleted';
		$del_log = [
			'delete_date'     => 1785000000,
			'delete_user_id'  => 2,
			'delete_username' => 'ModUser',
			'delete_reason'   => 'Duplicate topic',
		];
		$t6 = $normalizer->normalize_thread($row6, $config, $del_log);
		if ($t6->topic_visibility !== 2 || $t6->delete_username !== 'ModUser' || $t6->delete_reason !== 'Duplicate topic')
		{
			throw new \Exception("Soft-deleted topic metadata mapping failed");
		}

		// 7. Unknown discussion state -> default unapproved
		$row7 = $row1;
		$row7['thread_id'] = 107;
		$row7['discussion_state'] = 'custom_obscure_state';
		$t7 = $normalizer->normalize_thread($row7, $config);
		if ($t7->topic_visibility !== 0)
		{
			throw new \Exception("Unknown state must default to topic_visibility = 0 (ITEM_UNAPPROVED)");
		}

		// 8. Discussion Types (poll, question, article, redirect)
		$row_poll = $row1;
		$row_poll['discussion_type'] = 'poll';
		$t_poll = $normalizer->normalize_thread($row_poll, $config);
		if (!in_array('poll_data_deferred', $t_poll->unsupported_features, true))
		{
			throw new \Exception("Poll topic must report poll_data_deferred");
		}

		$row_q = $row1;
		$row_q['discussion_type'] = 'question';
		$t_q = $normalizer->normalize_thread($row_q, $config);
		if (!in_array('question_solution_deferred', $t_q->unsupported_features, true))
		{
			throw new \Exception("Question topic must report question_solution_deferred");
		}

		$row_art = $row1;
		$row_art['discussion_type'] = 'article';
		$t_art = $normalizer->normalize_thread($row_art, $config);
		if (!in_array('article_type_reduced', $t_art->unsupported_features, true))
		{
			throw new \Exception("Article topic must report article_type_reduced");
		}

		$row_red = $row1;
		$row_red['discussion_type'] = 'redirect';
		$t_red = $normalizer->normalize_thread($row_red, $config);
		if ($t_red !== null)
		{
			throw new \Exception("Redirect thread must return null / be skipped");
		}

		// 9. Persian with ZWNJ, Arabic, and Emoji in Titles & Prefixes
		$row_unicode = $row1;
		$row_unicode['thread_id'] = 109;
		$row_unicode['prefix_id'] = 2; // Persian prefix
		$row_unicode['title'] = "XXXXXXY_XXXX_XXXYX_UnicodeRunner\xE2\x80\x8CXXX 🚀";
		$t_unicode = $normalizer->normalize_thread($row_unicode, $config);

		$expected_title = "[XXXXXYX_XXX_UnicodeRunner\xE2\x80\x8CXXX] XXXXXXY_XXXX_XXXYX_UnicodeRunner\xE2\x80\x8CXXX 🚀";
		if ($t_unicode->topic_title !== $expected_title)
		{
			throw new \Exception("Unicode prefix prepending mismatch! Expected: '{$expected_title}', got: '{$t_unicode->topic_title}'");
		}

		// 10. Prefix Idempotency (Rerunning normalizer on already prepended title)
		$row_idempotent = $row_unicode;
		$row_idempotent['title'] = $expected_title;
		$t_idem = $normalizer->normalize_thread($row_idempotent, $config);
		if ($t_idem->topic_title !== $expected_title)
		{
			throw new \Exception("Prefix prepending is not idempotent! Got duplicate prefix: '{$t_idem->topic_title}'");
		}

		// 11. Empty Title Fallback
		$row_empty = $row1;
		$row_empty['thread_id'] = 111;
		$row_empty['title'] = "   ";
		$t_empty = $normalizer->normalize_thread($row_empty, $config);
		if ($t_empty->topic_title !== 'Untitled Topic #111')
		{
			throw new \Exception("Empty title fallback failed, got: '{$t_empty->topic_title}'");
		}

		// 12. Unicode-aware title truncation (exceeding 255 chars)
		$row_long = $row1;
		$row_long['thread_id'] = 112;
		$row_long['title'] = str_repeat("Long_Unicode_Topic_Title_", 40); // > 400 chars
		$t_long = $normalizer->normalize_thread($row_long, $config);
		if (mb_strlen($t_long->topic_title, 'UTF-8') > 255)
		{
			throw new \Exception("Title truncation failed to enforce 255 Unicode character limit");
		}

		// 13. Invalid Count Protection
		$row_counts = $row1;
		$row_counts['reply_count'] = -5;
		$row_counts['view_count'] = -20;
		$t_counts = $normalizer->normalize_thread($row_counts, $config);
		if ($t_counts->reply_count !== 0 || $t_counts->topic_views !== 0)
		{
			throw new \Exception("Negative reply/view count protection failed");
		}

		return true;
	}
}
