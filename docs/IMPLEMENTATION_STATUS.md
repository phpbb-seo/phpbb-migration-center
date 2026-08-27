# phpBB Migration Center - Implementation Status

## Phase Tracker

| Phase | Description | Status | Verification / Notes |
|---|---|---|---|
| **Phase 0** | Inspection & Environment Detection | **Completed** | Target phpBB 3.3.17, Source XF 2.3.12, PHP 8.2.12 detected safely. |
| **Phase 1** | Architecture & Extension Skeleton | **Completed** | Extension structure, DB migrations, DIC services, CLI commands, Contracts & DTOs, En/Fa languages installed. |
| **Phase 2** | Preflight & XenForo Provider | **Completed** | XenForo provider, DB adapter, version adapters (2.0-2.3), config detector, ACP wizard steps 1-3, and CLI check command. |
| **Phase 4A** | Groups, Memberships & Permissions | **Completed** | Canonical group resolution, custom group Unicode preservation, membership reconciliation, conservative permission translation matrix, founder protection, wildcard audit. |
| **Phase 4B** | Categories, Forums, Links & Node Permissions | **Completed** | Normalized ForumDto, nested-set tree calculation, hierarchy repairs (orphans/cycles), collision handling, forum-scoped local ACLs (f_, m_), zero target regression. |
| **Phase 4C** | Threads to Topics | **Completed** | Normalized TopicDto, keyset batching, prefix prepending/policies, status/visibility mapping, provisional post pointers, Unicode preservation. |
| **Phase 4D** | Posts & BBCode Conversion | **Completed** | Keyset posts batching, isolated BBCode converter, protected code blocks, deferred attachment markers, real post mapping, topic/forum/user finalization & recount. |
| **Phase 5A** | Post Attachments & Deferred Inline Finalization | **Completed** | Full pipeline verified (Source Provider -> attachments_step -> normalizer -> target writer -> id_mapper -> marker finalization -> display rendering). Storage root containment, persisted filename plan, SHA-256 validation, viewtopic attachment order (ORDER BY attach_id DESC), config thumbnails. |
| **Phase 5B** | User Avatars & Gravatars | **Completed** | Strict data/avatars/ containment, size variant precedence (o -> l -> m -> s), GD aspect-ratio resizing, phpBB native driver conventions, Gravatar support, existing target avatar preservation, protected users (ID 1, 2) safety. |
| **Phase 5C** | Private Messages & Conversations | **Completed** | Normalized ConversationDto & ConversationMessageDto, root_level thread establishment, privmsgs & privmsgs_to relationships (Inbox/Sentbox), per-user read/unread/starred/deleted flags, deferred PM attachments, zero notification side-effects. |
| **Phase 5D** | Conversation Attachments | **Completed** | Shared generalized attachment pipeline, in_message=1, topic_id=0, post_msg_id=msg_id, distinct pm_attachment ID mappings, native UCP query order (ORDER BY filetime DESC, post_msg_id ASC), inline marker conversion ([[MC_PM_ATTACH:id]] -> [attachment=n]...[/attachment]), message_attachment flag, strict download authorization guard via core.modify_pm_attach_download_auth event listener. |
| **Phase 5E** | Thread Polls, Options & Votes | **Completed** | Mapped xf_poll to phpbb_topics (poll_title, poll_start, poll_length, poll_max_options, poll_last_vote, poll_vote_change), inserted poll options into phpbb_poll_options, inserted and deduplicated votes into phpbb_poll_votes, reconciled poll_option_total from real votes, preserved Unicode and Persian/Arabic text, validated event dispatch and equal-timestamp tie-breakers. |
| **Phase 5F** | User Bans, Email Bans & IP Bans | **Completed** | Mapped XenForo user bans, email bans, and IP bans to phpBB banlist (`phpbb_banlist`). Singularity of ban ownership refactored to Bans step, anonymous/founder/admin protection enforced, expired ban skip policy, exact email and safe wildcard domain matching, IPv4/IPv6/wildcard matching with CIDR rejection, verified with native phpBB check_ban(). |
| **Phase 6** | Finalization, Recounts, Synchronization & Search Indexing | **Completed** | Modular resumable finalization (topics, forums, users, stats, ACL cache), native incremental search indexing (fulltext_native/mysql), comprehensive 11-category verification engine, count equation reconciliation, Persian/Arabic Unicode search verified, Subscriptions classified as intentionally_not_imported. |
| **Phase 7** | Full End-to-End Verification & Production Readiness | **Completed** | Clean isolated target (bb_e2e), mandatory real Persian user/avatar/attachment verification, 0 orphans, exact SQL recounts, incremental search verification, release packaging and documentation. |
| **Phase 7C** | Rollback, Fast Reset, Cancel, Weighted Progress & English Standardization | **Completed** | Production-safe reverse topological rollback, zero-import fast reset, dual progress indicators (Current Step + Weighted Overall), strict Anonymous & Founder protection, 100% English language standardization, 33/33 automated tests passing. |

---

### Out of Scope & Excluded Components
- **Subscriptions / Watches**: `xf_thread_watch`, `xf_forum_watch`, topic/forum subscriptions, and email notification states are explicitly excluded from migration and classified as `intentionally_not_imported`. (Aggregated sanitized counts will be displayed in the final report without exposing user identities).
- **Profile Banners**: Deferred / unsupported without third-party extension.
- **Third-Party Addons**: Unofficial XenForo addons excluded from core pipeline.

---

## Artifacts & Deliverables

- Extension ID: `phpbbseo/migrationcenter`
- Manifest: `composer.json`, `ext.php`
- Schema Migrations: `migrations/install_schema.php`, `migrations/install_acp_module.php`
- Core Contracts: `SourceProviderInterface`, `IdMapperInterface`, `StepInterface`, `PasswordHandlerInterface`, `ContentConverterInterface`, `TargetWriterInterface`
- Core DTOs: `MigrationConfigDto`, `RunStateDto`, `StepResultDto`, `PreflightResultDto`, `UserDto`, `GroupDto`, `ForumDto`, `TopicDto`, `PostDto`, `AttachmentDto`
- Core Engine Services: `IdMapper`, `LockManager`, `StateManager`, `StepRegistry`, `ProviderRegistry`, `MigrationEngine`, `PhpbbTargetWriter`, `RollbackManager`
- Console Commands: `check`, `run`, `resume`, `pause`, `status`, `retry`, `verify`, `finalize`, `search_index`
- Presentation: ACP module controller, HTML templates, CSS (LTR/RTL compliant, 100% English standardized)
- Languages: English (`en`), Persian (`fa` synchronized to English)
- Tests: Test runner with 34 unit and integration tests (100% passing, test isolation and automatic teardown)

