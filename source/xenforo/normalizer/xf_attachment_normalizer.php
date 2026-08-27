<?php
/**
 * phpBB Migration Center Extension
 *
 * @copyright (c) 2026 phpBB SEO Team
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace phpbbseo\migrationcenter\source\xenforo\normalizer;

use phpbbseo\migrationcenter\core\dto\attachment_dto;
use phpbbseo\migrationcenter\core\dto\migration_config_dto;
use phpbbseo\migrationcenter\source\xenforo\storage\xf_attachment_path_resolver;

/**
 * XenForo Attachment Normalizer
 */
class xf_attachment_normalizer
{
	/**
	 * Normalize an attachment row into AttachmentDto
	 *
	 * @param array $row
	 * @param migration_config_dto $config
	 * @return attachment_dto
	 */
	public function normalize_attachment(array $row, migration_config_dto $config): attachment_dto
	{
		$att = new attachment_dto();
		$att->source_id = (int)$row['attachment_id'];
		$att->data_id = (int)($row['data_id'] ?? 0);
		$att->content_type = (string)($row['content_type'] ?? 'post');
		$att->post_source_id = (int)($row['content_id'] ?? 0);
		$att->user_source_id = (int)($row['user_id'] ?? 0);
		$att->real_filename = trim((string)($row['filename'] ?? 'attachment_' . $att->source_id));
		$att->filesize = max(0, (int)($row['file_size'] ?? 0));
		$att->filetime = (int)($row['attach_date'] ?? $row['upload_date'] ?? time());
		$att->download_count = max(0, (int)($row['view_count'] ?? 0));
		$att->file_hash = (string)($row['file_hash'] ?? $row['file_key'] ?? '');
		$att->thumbnail = !empty($row['thumbnail_width']) ? 1 : 0;
		$att->is_orphan = !empty($row['unassociated']) ? 1 : 0;
		$att->raw_source_data = $row;

		// Extract extension from filename
		$ext = strtolower(pathinfo($att->real_filename, PATHINFO_EXTENSION));
		$att->extension = $ext;

		// Resolve physical source file path
		$source_path = $config->source_path ?: '';
		$file_key = (string)($row['file_key'] ?? '');
		$file_hash = (string)($row['file_hash'] ?? '');
		$file_path = (string)($row['file_path'] ?? '');

		$resolved_path = xf_attachment_path_resolver::resolve_path(
			$source_path,
			$att->data_id,
			$file_key,
			$file_hash,
			$file_path
		);

		if ($resolved_path)
		{
			$att->source_physical_path = $resolved_path;

			// Check physical file size
			$actual_size = @filesize($resolved_path);
			if ($actual_size !== false && $actual_size > 0)
			{
				$att->filesize = $actual_size;
			}

			// Detect MIME type safely
			if (function_exists('mime_content_type'))
			{
				$detected_mime = @mime_content_type($resolved_path);
				if ($detected_mime && strpos($detected_mime, '/') !== false)
				{
					$att->mimetype = $detected_mime;
				}
			}
		}
		else
		{
			$att->warnings[] = "Source physical attachment file not found for data ID {$att->data_id}";
		}

		if (empty($att->mimetype) || $att->mimetype === 'application/octet-stream')
		{
			$att->mimetype = $this->mime_from_extension($ext);
		}

		return $att;
	}

	/**
	 * Safe MIME type fallback from extension
	 *
	 * @param string $ext
	 * @return string
	 */
	public function mime_from_extension(string $ext): string
	{
		$map = [
			'png'  => 'image/png',
			'jpg'  => 'image/jpeg',
			'jpeg' => 'image/jpeg',
			'gif'  => 'image/gif',
			'webp' => 'image/webp',
			'pdf'  => 'application/pdf',
			'zip'  => 'application/zip',
			'tar'  => 'application/x-tar',
			'gz'   => 'application/gzip',
			'txt'  => 'text/plain',
		];

		return $map[$ext] ?? 'application/octet-stream';
	}
}
