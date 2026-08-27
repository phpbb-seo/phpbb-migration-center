<?php
/**
 * phpBB Migration Center Extension
 *
 * @copyright (c) 2026 phpBB SEO Team
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace phpbbseo\migrationcenter\core\dto;

/**
 * Normalized Attachment DTO
 */
class attachment_dto
{
	/** @var string|int Source attachment ID (xf_attachment.attachment_id) */
	public $source_id;

	/** @var string|int Source attachment data ID (xf_attachment_data.data_id) */
	public $data_id;

	/** @var string Content type (e.g. 'post') */
	public $content_type = 'post';

	/** @var string|int Source post ID (xf_attachment.content_id) */
	public $post_source_id;

	/** @var string|int Source topic ID */
	public $topic_source_id;

	/** @var string|int Source user ID */
	public $user_source_id;

	/** @var string Original file name */
	public $real_filename = '';

	/** @var string Resolved source physical absolute file path */
	public $source_physical_path = '';

	/** @var string Generated target physical filename in phpBB files/ directory */
	public $target_physical_filename = '';

	/** @var string Lowercase file extension without dot */
	public $extension = '';

	/** @var string MIME type */
	public $mimetype = 'application/octet-stream';

	/** @var int File size in bytes */
	public $filesize = 0;

	/** @var int Upload timestamp */
	public $filetime = 0;

	/** @var int View / download count */
	public $download_count = 0;

	/** @var string Source file hash / key */
	public $file_hash = '';

	/** @var int Thumbnail status: 0 = none, 1 = available */
	public $thumbnail = 0;

	/** @var int Orphan status: 0 = active, 1 = orphan */
	public $is_orphan = 0;

	/** @var string Optional attachment comment */
	public $attach_comment = '';

	/** @var array Unsupported features */
	public $unsupported_features = [];

	/** @var array Warnings */
	public $warnings = [];

	/** @var array Raw source record */
	public $raw_source_data = [];
}
