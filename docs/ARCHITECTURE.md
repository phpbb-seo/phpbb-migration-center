# phpBB Migration Center - Architecture Reference

## Overview

**phpBB Migration Center** (`phpbbseo/migrationcenter`) is an extensible migration engine for phpBB 3.3. It is designed to migrate large-scale community forums into phpBB while maintaining data integrity, Unicode fidelity (Persian, Arabic, Hebrew, Emojis), deterministic keyset pagination, and idempotent pause/resume capabilities.

---

## Core Principles

1. **Provider Isolation**:
   - Source Providers (e.g., XenForo, vBulletin, MyBB, SMF, IPS, Discourse) implement generic contracts (`SourceProviderInterface`).
   - The phpBB Target Writer and Core Migration Engine contain no source-specific SQL or assumptions.

2. **Unified Execution Layer**:
   - ACP Web UI (via AJAX batches) and Symfony Console CLI invoke the exact same `MigrationEngine` services.
   - Zero dependence on HTTP sessions or browser state for migration logic.

3. **Deterministic Keyset Pagination**:
   - For datasets of millions of records, `OFFSET` is never used.
   - Batches use keyset cursors: `WHERE primary_id > :cursor ORDER BY primary_id ASC LIMIT :batch_size`.

4. **Transactional Batch Boundaries & Resumability**:
   - Atomic database transactions occur per batch.
   - Cursor positions and mapped IDs are persisted to extension tables (`phpbb_migration_steps`, `phpbb_migration_id_map`) upon every batch completion.
   - Interrupted runs resume cleanly from the last committed cursor without duplicate inserts.

5. **Distributed Lock with Stale-Lock Recovery**:
   - Database table `phpbb_migration_locks` prevents parallel runs on the same source.
   - Heartbeats keep locks alive; timed-out locks (default 300s) are automatically recovered.

6. **Unicode & RTL Compatibility**:
   - Full `utf8mb4` support across all extension tables and connections.
   - Multibyte Unicode character sets, RTL scripts, and emojis are strictly preserved.
   - ACP templates dynamically support LTR and RTL directions.

7. **Clean Room Implementation & Security**:
   - All code is original clean-room phpBB code. Reference add-on files are inspected only for schema understanding.
   - Passwords and connection secrets are strictly omitted from logs, state DTOs, HTML, and reports.

---

## Extension Database Tables

All tables dynamically respect phpBB's configured prefix (`$table_prefix`):

- **`{prefix}migration_runs`**: Tracks run metadata, overall status, timing, sanitized options.
- **`{prefix}migration_steps`**: Tracks individual step progress, cursors, total/imported/skipped/failed counts.
- **`{prefix}migration_id_map`**: Stores source ID to target phpBB ID mapping with fast lookup indexes.
- **`{prefix}migration_errors`**: Stores sanitized error and warning logs with source context.
- **`{prefix}migration_settings`**: Global migration engine configuration.
- **`{prefix}migration_locks`**: Distributed locking mechanism.

---

## CLI Integration

- `php bin/phpbbcli.php migrationcenter:check <source>`
- `php bin/phpbbcli.php migrationcenter:run <source> [--batch-size=500] [--step=...]`
- `php bin/phpbbcli.php migrationcenter:retry <run-id>`
- `php bin/phpbbcli.php migrationcenter:verify <run-id>`

---

## Topic & Post Lifecycle Strategy

During topic migration (Phase 4C), posts have not yet been imported:
1. **Strategy Selection (Strategy A)**:
   - Provisional topic records are written directly to `phpbb_topics` with `topic_first_post_id = 0`, `topic_last_post_id = 0`, and `topic_posts_approved = 0`.
   - Source first post ID, last post ID, and metadata are persisted in `phpbb_migration_id_map` (`type = topic`).
   - The board should be placed in maintenance mode during execution.
2. **Phase 4D Resolution**:
   - As posts are parsed and written in Phase 4D, `phpbb_posts` references the mapped `topic_id`.
   - Upon completion of posts for each topic/batch, `topic_first_post_id`, `topic_last_post_id`, and `topic_posts_approved` are resolved and finalized through target synchronization.

---

## Message Conversion & Deferred Attachment Markers

1. **BBCode Conversion Architecture**:
   - `xf_message_converter` isolates XenForo-specific BBCode transformation from database/step logic.
   - Code blocks (`[CODE]...[/CODE]`) are protected via iterative token extraction to prevent internal parsing corruption.
   - Quotes (`[QUOTE="User, post: 123"]`) are converted to standard phpBB `[quote="User"]` without exposing source IDs in target BBCode.
   - User mentions (`[USER=123]Name[/USER]`) are safely converted to `@Name`.
   - Spoilers, tables, and headings are converted to semantic, readable phpBB structures.
   - Dangerous image/link schemes (`javascript:`, `data:`, `file:`) are neutralized.
   - Storage text is generated via phpBB's native `generate_text_for_storage()`, ensuring valid `bbcode_uid` and `bbcode_bitfield`.

2. **Deferred Attachment Marker Design & Phase 5A Finalization**:
   - XenForo attachments (`[ATTACH=full]123[/ATTACH]`, `[ATTACH]123[/ATTACH]`) are initially converted to deterministic markers: `[[MC_ATTACH:{source_id}]]`.
   - In Phase 5A, physical files are verified, copied with unique hashed filenames to phpBB's `files/` directory, and inserted into `phpbb_attachments`.
   - Native phpBB inline ordering is resolved by loading post attachments sorted by `attach_id ASC` (indices 0, 1, ...).
   - Markers are replaced with `[attachment=n]real_filename[/attachment]` and post storage XML is regenerated.
   - `post_attachment = 1` and `topic_attachment = 1` are updated strictly based on real target attachment rows.

---

## Physical Attachment Storage & Security (Phase 5A)

1. **Path Resolution & Storage Root Containment**:
   - `xf_attachment_path_resolver` supports XenForo 2.1–2.3+ (`internal_data/attachments/{group}/{data_id}-{file_key}.data`), XenForo 2.0 (`{data_id}-{file_hash}.data`), and custom `file_path` templates.
   - Restricts resolution strictly to `<xenforo-root>/internal_data/attachments/` (or verified custom adapter root).
   - Prevents path traversal, symlink escapes, and prefix confusion (`attachments_evil`).
2. **Persisted Filename Planning & SHA-256 Validation**:
   - Generates a cryptographically random physical target filename (`{$user_id}_{bin2hex(random_bytes(16))}`) and persists it in `phpbb_migration_id_map` metadata before copying.
   - Validates file content using SHA-256 (`hash_file('sha256', ...)`) for safe reuse on retry.
   - Never shares physical files across attachment rows; detects same-size collisions safely.
3. **Native phpBB Inline Viewtopic Ordering**:
   - Matches phpBB's native query: `ORDER BY attach_id DESC`.
   - Post markers are finalized with index `[attachment=0]`, `[attachment=1]`, ... corresponding directly to phpBB's `viewtopic.php` array indexing.

---

## User Avatars & Gravatars (Phase 5B)

1. **Avatar Storage & Containment**:
   - `xf_avatar_path_resolver` strictly resolves avatar files within `<xenforo-root>/data/avatars/`.
   - Size variant precedence: `o` (Original) -> `l` (Large) -> `m` (Medium) -> `s` (Small).
   - Supports formats: JPEG, PNG, WebP.
2. **Target phpBB Driver & Physical File Conventions**:
   - Uploaded avatars: Physical file written to `images/avatars/upload/{$avatar_salt}_{$target_user_id}.{$ext}`.
   - Database fields updated on `phpbb_users`: `user_avatar = '{$target_user_id}.{$ext}'`, `user_avatar_type = 'avatar.driver.upload'`, `user_avatar_width`, `user_avatar_height`.
   - Gravatars: Updates `user_avatar = 'email@example.com'`, `user_avatar_type = 'avatar.driver.gravatar'`, `user_avatar_width = 80`, `user_avatar_height = 80` (if phpBB Gravatar is enabled).
3. **Aspect-Ratio Preserving Resizing**:
   - Queries `phpbb_config` for `avatar_max_width` and `avatar_max_height`.
   - Resizes oversized images via GD preserving alpha transparency (PNG/WebP).
4. **Target User Protection & Policies**:
   - Anonymous user ID 1 and Admin user ID 2 are strictly protected from avatar modification.
   - Pre-existing target user avatars are preserved by default (`existing_avatar_policy = replace_only_if_empty`).
   - Profile banners are inventoried and explicitly deferred (`deferred_unsupported_without_extension`).

---

## Private Messages & Conversations (Phase 5C)

1. **Two-Step Architecture**:
   - Step 1 `conversations`: Inventories conversation master records and participant metadata, persisting planned records in `phpbb_migration_id_map`.
   - Step 2 `conversation_messages`: Imports individual messages chronologically, establishes root threading, and inserts `phpbb_privmsgs` & `phpbb_privmsgs_to` rows.
2. **Root-Level Threading**:
   - The first imported message in a conversation sets `root_level = 0`.
   - Subsequent reply messages set `root_level = target_root_msg_id`.
   - Different conversations never share root IDs or cross-contaminate threads.
3. **Recipient & Sender Relationships (`phpbb_privmsgs_to`)**:
   - For each recipient: inserted into `folder_id = 0` (`PRIVMSGS_INBOX`) with `pm_unread` resolved from `last_read_date` boundary and `pm_deleted` from `recipient_state`.
   - For the author: inserted into `folder_id = -1` (`PRIVMSGS_SENTBOX`) with `pm_unread = 0`.
   - `pm_new` is strictly initialized to `0` to prevent misleading notification popups upon migration.
4. **Conversation Attachments & PM Privacy (Phase 5D)**:
   - Shared generalized attachment pipeline (`write_attachments`) handles both posts (`in_message = 0`, `topic_id = topic_id`) and PMs (`in_message = 1`, `topic_id = 0`, `post_msg_id = msg_id`).
   - Distinct ID mapping namespaces: `type = 'attachment'` for post attachments, `type = 'pm_attachment'` for PM attachments.
   - Native phpBB UCP query ordering: `ORDER BY filetime DESC, post_msg_id ASC`.
   - Deferred markers (`[[MC_PM_ATTACH:{id}]]`) are finalized to `[attachment=n]filename[/attachment]` with index matching UCP ordering.
   - `message_attachment = 1` flag is updated in `phpbb_privmsgs` only when valid target attachment rows exist.
   - Extension-level privacy guard (`main_listener::modify_pm_attach_download_auth` on event `core.modify_pm_attach_download_auth`):
     - Enforces that only users with an active (`pm_deleted = 0`) record in `phpbb_privmsgs_to` can download the file.
     - Denies access to deleted recipients, late joiners (pre-join messages), unrelated users, anonymous users, and guessed `attach_id` requests.

---

## Thread Polls, Options & Votes (Phase 5E)

1. **Topic-Attached Poll Model**:
   - XenForo `xf_poll` (`content_type = 'thread'`) attaches directly to the mapped `phpbb_topics` row without creating duplicate topics.
   - Target topic fields updated: `poll_title`, `poll_start`, `poll_length`, `poll_max_options`, `poll_last_vote`, `poll_vote_change`.
2. **Options Management (`phpbb_poll_options`)**:
   - `poll_option_id` values assigned 1-indexed (1, 2, 3...) per topic.
   - Unicode, Persian, Arabic, and Emoji text preserved cleanly.
   - Mapped in `phpbb_migration_id_map` (`type = 'poll_option'`).
3. **Vote Migration & Reconciled Option Totals**:
   - Source votes (`xf_poll_vote`) resolved to mapped target users and option IDs.
   - Deduplication prevents duplicate user-option votes.
   - Option totals (`poll_option_total`) are derived from actual inserted rows in `phpbb_poll_votes`.
   - `poll_last_vote` updated to the latest vote timestamp.
4. **Reduced Fidelity Classifications**:
   - `public_votes` and `view_results_unvoted` stored in metadata but classified as reduced fidelity as phpBB core does not feature a dedicated public voter list UI.

---

## User Bans, Email Bans & IP Bans (Phase 5F)

1. **Singular Ban Authority**:
   - `write_bans` owns 100% of all `phpbb_banlist` writes, preventing duplicate ban insertion from previous user migration passes.
   - Idempotency ensures reruns detect existing mapped ban records without duplicate row creation.
2. **User Bans**:
   - Source `xf_user_ban` maps to `phpbb_banlist` (`ban_userid = target_user_id`).
   - Permanent bans map to `ban_end = 0`; active temporary bans map to exact expiration timestamp.
   - Expired bans are skipped under default `expired_ban_policy = skip`.
   - Protection guarantees: Anonymous (ID 1), Founders (`user_type = 3`), and pre-existing target administrators are strictly protected.
   - User reason maps to `ban_give_reason` (shown to banned user on login).
3. **Email & IP Bans**:
   - Exact email and safe domain wildcards (`*@domain.com`) map cleanly to `ban_email`. Regex patterns are rejected to prevent overbroad matching.
   - Exact IPv4, IPv6, and standard wildcards (`198.51.100.*`) map to `ban_ip`. Localhost (`127.0.0.1`, `::1`) is protected; CIDR ranges are skipped to avoid rule broadening.
4. **Active Session & Login Enforcement**:
   - Banned accounts, emails, and IPs are verified via native phpBB `check_ban()` login checks.
   - Zero notification side effects during migration.

---

## Finalization, Recounts, Incremental Search Indexing & Verification (Phase 6)

1. **Modular Finalization & Recount Services**:
   - `finalize_topics`: Recalculates first/last post IDs, last post times, poster usernames, poster colors, approved/unapproved/softdeleted reply counts, and topic attachment flags.
   - `finalize_forums`: Recalculates approved/unapproved/deleted topic and post totals, latest post metadata, and validates nested-set `left_id`/`right_id` bounds without destroying valid pre-existing trees.
   - `finalize_users`: Synchronizes `user_posts` (counting approved posts in postcount-enabled forums, combining pre-existing and migrated posts for merged users), and recalculates unread PM counts (`user_unread_privmsg`).
   - `finalize_global_stats`: Updates `num_posts`, `num_topics`, `num_users`, `newest_user_id`, `newest_username`, `newest_user_colour` derived from real database content.
2. **Incremental Search Indexing**:
   - Detects configured phpBB search backend (`fulltext_native`, `fulltext_mysql`).
   - Incrementally indexes migration-owned approved posts in keyset batches.
   - Supports dry-run simulation, pause/resume, and retry without clearing existing search indices.
   - Validates multilingual word tokenization and multibyte Unicode search indexing without character corruption.
   - Eleven structured verification checks including completion gates, provisional topic validation, unresolved attachment markers, orphan relationship resolution, physical file existence, PM thread structure, poll vote reconciliation, ban safety, Unicode integrity, permission safety, and excluded features.
   - Count Equation Reconciliation: `source selected = valid imported + intentionally excluded + unsupported + skipped by policy + failed`.
   - Classification of subscriptions (`xf_thread_watch`, `xf_forum_watch`) strictly as `intentionally_not_imported`.
   - Sanitized reporting across ACP, CLI, and downloadable summaries.

---

## Subscriptions & Watches (Explicitly Excluded)

- `xf_thread_watch`, `xf_forum_watch`, topic/forum subscriptions, and email watch notification states are **out of scope** and classified strictly as `intentionally_not_imported`.
- No `phpbb_topics_watch` or `phpbb_forums_watch` records are inserted.
- Final migration reports will include aggregated count metrics for skipped watch records without exposing voter/subscriber identities.

---

## Single Non-Terminal Run Policy & Production Safety

1. **One Active Migration Rule**:
   - Maximum of 1 non-terminal run (`pending`, `ready`, `running`, `pausing`, `paused`, `failed`, `cancelling`, `cancelled`, `rolling_back`, `rollback_failed`) is permitted per target phpBB board.
   - While a non-terminal run exists, starting a new migration is strictly blocked both in the ACP UI and server-side in `migration_engine::start_run()`.
   - Repeated clicks, browser refreshes, or multi-tab concurrency are safely guarded.
2. **Terminal Run Lifecycle**:
   - Terminal runs (`finalized`, `rolled_back`, `abandoned`) cannot be resumed.
   - New migrations may only be initiated when no active run exists or previous runs have reached terminal status.
3. **Automated Test Isolation**:
   - Automated tests are strictly isolated from the live ACP display.
   - Test teardowns automatically clean fixtures to guarantee zero database contamination.

