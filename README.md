# 🚀 phpBB Migration Center

[![Version](https://img.shields.io/badge/version-0.1.0--beta.1-blue.svg?style=flat-square)](https://github.com/phpbb-seo/phpbb-migration-center)
[![phpBB](https://img.shields.io/badge/phpBB-3.3.x-green.svg?style=flat-square)](https://www.phpbb.com)
[![PHP](https://img.shields.io/badge/PHP-%3E%3D%207.4-purple.svg?style=flat-square)](https://php.net)
[![License](https://img.shields.io/badge/license-GPL--2.0-yellow.svg?style=flat-square)](LICENSE)
[![Tests](https://github.com/phpbb-seo/phpbb-migration-center/actions/workflows/tests.yml/badge.svg)](https://github.com/phpbb-seo/phpbb-migration-center/actions/workflows/tests.yml)

**phpBB Migration Center** is a modular forum converter and migration framework for transferring established online communities to **phpBB 3.3+**.

It provides controlled migration stages, Browser and CLI workers, persistent progress tracking, manual approval checkpoints, password compatibility, permission translation, recovery tools, and post-migration verification.

> [!WARNING]
> **Beta Testing Release:**  
> This project is currently a **Beta development release**. Use it only on backed-up staging environments. Do not run it against a live production forum without independent testing and a verified recovery plan.

---

## 🚀 Quick Start (Beta Testing Setup)

> [!IMPORTANT]
> Install this Beta release exclusively on a **test/staging phpBB installation** with a complete database and filesystem backup.

1. **Download or Clone the Repository:**  
   Clone or place the repository contents into your phpBB directory so the folder structure is:
   ```text
   phpBB_ROOT/
   └── ext/
       └── phpbbseo/
           └── migrationcenter/
               ├── composer.json
               ├── ext.php
               ├── acp/
               ├── adm/
               ├── config/
               ├── core/
               └── ...
   ```
2. **Enable the Extension:**  
   Log in to your phpBB **Administration Control Panel (ACP)**, navigate to **Customise** &raquo; **Manage extensions**, find **phpBB Migration Center**, and click **Enable**.
3. **Run Preflight Checks:**  
   In the ACP navigation bar, click the **Migration Center** tab &raquo; **Migration Wizard**. Enter your source database connection details and verify the preflight diagnostics before creating a plan.
4. **Execute & Review:**  
   Execute migration stages using either the Browser AJAX worker or the recommended CLI worker command displayed in the ACP, reviewing each stage reconciliation report before approving continuation.

---

## ✨ Key Features

- 🧩 **Modular source-connector architecture:** Shared migration engine separated from platform-specific conversion logic.
- 🖥️ **Browser-based AJAX migration worker:** Live progress visualization with percentage counters and error reporting.
- 💻 **CLI worker for large migrations:** Background daemon execution without webserver timeout restrictions.
- 📊 **Persisted live progress:** Continual recording of source cursors, created/updated/skipped/failed counters, processing rate, and ETA.
- ⏸️ **Manual approval checkpoints:** Safe pauses between stages with detailed reconciliation reporting.
- 🔄 **Pause and controlled resume:** Interruption detection with exact-record resume capability.
- 🔐 **Password compatibility handlers:** Native support for supported source password formats with automatic upgrade on first login.
- 👥 **User, group, and membership migration:** Preserves user profiles, avatars, signatures, and group relations.
- 🛡️ **Security-focused permission translation:** Conservative mapping of administrative, moderator, and forum-scoped ACLs.
- 💬 **Forum, topic, post, and BBCode conversion:** Tree hierarchy preservation with automated BBCode and Unicode normalization.
- 📎 **Attachment and avatar migration:** File existence checks, safe destination hashing, and SHA-256 integrity verification.
- ✉️ **Private conversation and message migration:** Multi-user conversation threading with folder and attachment support.
- 📊 **Poll, vote, and ban migration:** Topic poll options, voter records, and moderation banlists.
- ↩️ **Rollback and reset controls:** Migration-owned record tracking for safe reversal and cleanup.
- 🔍 **Finalization and verification:** Board recounts, search indexing, and automated 11-point data integrity test suite.

*Feature availability may depend on the active source connector and the source-platform version.*

---

## 🚧 Project Status

phpBB Migration Center is currently under active development and community testing.

| Component | Status |
| :--- | :---: |
| **Migration framework core** | Beta |
| **ACP migration wizard** | Beta |
| **Browser AJAX worker** | Beta testing |
| **CLI worker** | Beta testing |
| **Stage checkpoints** | Beta testing |
| **Rollback and recovery** | Beta testing |
| **Final verification suite** | Beta testing |
| **XenForo connector** | **Beta — Under active testing** |
| **vBulletin connector** | Planned |
| **MyBB connector** | Planned |
| **SMF connector** | Planned |
| **Invision Community connector** | Planned |

A platform is supported only after its connector has been implemented, tested, and officially released. At present, the repository contains the XenForo source connector. Other platforms listed above are part of the planned connector roadmap and are not yet available.

---

## 🔌 Source Platform Connectors

Migration Center separates its shared migration engine from platform-specific conversion logic.

- **The common core provides:**
  - Migration lifecycle and stage management
  - Browser and CLI execution
  - Cursor and progress persistence
  - Worker locks and heartbeat monitoring
  - Manual stage checkpoints
  - Reconciliation reporting
  - Recovery and rollback coordination
  - Finalization and verification
- **Each connector provides:**
  - Source configuration detection
  - Source database queries
  - Data normalization
  - Password compatibility
  - Permission translation
  - BBCode conversion
  - Attachment and avatar resolution
  - Platform-specific migration policies

This architecture allows future converters to reuse the same migration workflow without duplicating the core engine.

---

## 📦 Current Connector: XenForo to phpBB

The current development connector targets migration from **XenForo to phpBB**. Its intended migration domains include:

- User groups
- Users and supported password hashes
- Primary and secondary group memberships
- Global permissions
- Forums and categories
- Forum-specific permissions
- Topics and posts
- BBCode and supported content
- Post attachments
- User avatars
- Private conversations
- Private messages
- Private-message attachments
- Polls and votes
- User, email, and IP bans

*Compatibility must be verified against the exact source version and test data before any production migration.*

---

## ⚙️ Migration Architecture

Migration Center uses bounded batches and cursor-based pagination to process source records. After each successfully committed batch, it persists:

- Current stage
- Source cursor
- Processed count
- Created count
- Reused count
- Updated count
- Skipped count
- Failed count
- Worker type
- Worker heartbeat
- Stage and run status

The database remains the authoritative source of migration progress. Opening or refreshing the ACP page does not independently advance a migration.

*Actual migration performance depends on the source database, target database, selected stages, server resources, storage, and content complexity.*

---

## 🖥️ Browser and CLI Workers

Both execution methods use the same migration engine, run ID, stage plan, cursors, counters, and locking system.

### Browser AJAX Worker
The Browser Worker is intended for smaller migrations and testing. It provides:
- Live ACP progress visualization
- Stage and overall percentages
- Reconciliation counters
- Processing rate and ETA when available
- Visible error reporting
- Automatic stopping at stage checkpoints

*The browser must remain open and active while its worker is processing.*

### CLI Worker
The CLI Worker is intended for larger migration workloads and environments where web-server timeouts may interrupt browser processing. It provides:
- Terminal startup validation
- Progress reporting after committed batches
- Worker heartbeat and lock ownership
- Progress monitoring from the ACP
- Controlled interruption recovery
- Automatic stopping at stage checkpoints

*Only one worker may process a migration run at a time.*

Use phpBB CLI help to inspect the arguments and options available in the installed version:

```bash
# Inspect arguments and options for available CLI commands:
php bin/phpbbcli.php migrationcenter:check --help
php bin/phpbbcli.php migrationcenter:run --help
php bin/phpbbcli.php migrationcenter:resume --help
php bin/phpbbcli.php migrationcenter:status --help
php bin/phpbbcli.php migrationcenter:pause --help
php bin/phpbbcli.php migrationcenter:retry --help
php bin/phpbbcli.php migrationcenter:finalize --help
php bin/phpbbcli.php migrationcenter:search-index --help
php bin/phpbbcli.php migrationcenter:verify --help
```

> [!TIP]
> **Do not construct a CLI command manually** when the ACP provides a command for an existing migration run. Use the exact command and Run ID displayed in the Migration Center interface.

---

## 🧭 Controlled Migration Stages

The framework organizes migration data into the following canonical sequence:

| Stage # | Stage Key | Canonical Stage Name |
| :---: | :--- | :--- |
| **1** | `groups` | User Groups |
| **2** | `users` | Users and Passwords |
| **3** | `group_memberships` | Group Memberships |
| **4** | `global_permissions` | Global Permissions |
| **5** | `forums` | Forums and Categories |
| **6** | `node_permissions` | Forum Permissions |
| **7** | `topics` | Topics |
| **8** | `posts` | Posts and BBCode |
| **9** | `attachments` | Post Attachments |
| **10** | `avatars` | User Avatars |
| **11** | `conversations` | Private Conversations |
| **12** | `conversation_messages` | Private Messages |
| **13** | `conversation_attachments` | Private-Message Attachments |
| **14** | `polls` | Polls and Votes |
| **15** | `bans` | Bans and Blacklists |
| **16** | `finalization` | Finalization and Recounts |
| **17** | `search_index` | Search Index |
| **18** | `final_verification` | Final Verification |

*Only selected and supported stages containing applicable source data are included in the migration workload.*

---

## ✅ Manual Stage Checkpoints

Migration Center stops after each completed stage and produces a reconciliation report.

```text
Stage Completed: User Groups
Processed: 6 | Created: 2 | Reused: 4 | Updated: 0 | Skipped: 0 | Failed: 0
```

The next stage does not start until the administrator reviews the result and explicitly approves progression. This workflow is designed to reveal mapping or conversion problems before dependent records are processed.

---

## 🔐 Password Compatibility

Where supported by the active source connector, imported users may continue signing in with their existing passwords. After successful authentication, a compatible legacy password hash is transparently upgraded to phpBB’s native password format.

- Migration Center does not decrypt passwords and does not require access to plain-text user credentials.
- Password compatibility depends on the exact source platform, source version, and hashing configuration.
- Unsupported password formats are reported rather than treated as successfully preserved.

---

## 🛡️ Permission Migration

Forum platforms do not use identical permission systems. Some source permissions cannot be represented exactly in phpBB.

Migration Center classifies permission mappings as:
- Exact mappings
- Reduced-fidelity mappings
- Unsupported rules
- Forum-specific rules
- Rules requiring administrator review

Unsupported access rules use conservative defaults instead of silently granting additional permissions. Existing phpBB founders and protected administrative identities are never automatically replaced or elevated by a migration.

---

## 📎 Attachments and Avatars

Depending on connector support and administrator-selected policies, file migration includes:
- Source-path validation
- File existence checks
- Safe destination naming
- Collision handling
- File-size validation
- Hash verification (SHA-256)
- Attachment-reference conversion
- Migration-owned file cleanup
- Missing-file reporting

*Always verify source and target filesystem paths during preflight checks.*

---

## 🔄 Recovery and Rollback

Every successfully committed batch records its cursor and counters. If processing is interrupted, Migration Center detects the inactive worker and provides a controlled recovery workflow.

Available recovery behavior includes:
- **Pause:** Clean stop after the current committed batch
- **Resume:** Continuation from persisted progress
- **Stale-Worker Detection:** Heartbeat-based inactive worker reclamation
- **Lock Protection:** Worker-lock conflict prevention
- **Retry:** Re-execution of supported failed items
- **Rollback:** Removal of migration-owned records
- **Fast Reset:** Instant reset for zero-write runs

*Rollback capability depends on migration state and ownership records. It is not a replacement for a complete target database and filesystem backup.*

---

## 🔍 Finalization and Verification

After data migration, the framework can perform controlled post-migration operations:
- Forum, topic, post, and user recounts
- Board-statistics synchronization
- Search-index rebuilding
- Referential-integrity checks
- Orphaned-record detection
- Attachment and avatar verification
- Counter reconciliation
- Permission-safety checks
- Unicode and multilingual text-encoding checks
- Migration-error review

*A run should not be considered verified while blocking integrity errors remain.*

---

## 📋 Requirements

- **phpBB:** 3.3.0 to 3.3.x
- **PHP:** 7.4 or newer (compatible with PHP 8.x)
- **PHP Extensions:** `json`, `pdo`, `mbstring`
- **Database:** Access to source forum database
- **Filesystem:** Read access to source files when migrating attachments or avatars
- **Backups:** A complete target database and filesystem backup
- **CLI:** Terminal access required for CLI Worker mode

---

## 🧪 Testing

The repository includes automated test suites for migration components and lifecycle behavior.

Automated tests do not replace real migration testing against representative source data. Before deploying migrated data, verify:
- Real ACP wizard workflow
- Browser AJAX execution
- CLI execution
- Stage reconciliation reports
- User authentication
- Forum permissions
- Attachments and avatars
- Private messages
- Search indexing
- Rollback behavior
- Final integrity verification suite

---

## ⚠️ Beta Testing Safety Guidelines

Before testing:
1. **Create a complete backup** of the target phpBB database.
2. **Back up** the target `files/` and avatar upload directories.
3. **Use a staging or disposable** phpBB installation.
4. **Keep the source forum read-only.**
5. **Do not reuse production database credentials** in public reports.
6. **Remove passwords, absolute private paths, and sensitive values** from screenshots and logs.
7. **Verify every stage reconciliation report** before approving continuation.
8. **Do not rely on Beta software** as the sole copy of a production migration plan.

---

## 🐞 Reporting Issues

Please use [GitHub Issues](https://github.com/phpbb-seo/phpbb-migration-center/issues) for reproducible bugs and feature requests.

Include:
- phpBB version
- PHP version
- Source platform and exact version
- Migration Center commit hash or release tag
- Worker mode used (Browser AJAX or CLI)
- Affected migration stage
- Expected behavior vs actual behavior
- Sanitized error message and logs
- Exact reproduction steps

*Never include database passwords, private keys, session identifiers, or unredacted private user data.*

---

## 🤝 Contributing

Community testing, technical reviews, documentation improvements, and new source connectors are welcome.

Before contributing:
1. Open an issue describing the proposed change.
2. Keep platform-specific logic inside its connector.
3. Do not duplicate shared migration-engine functionality.
4. Add or update relevant tests.
5. Preserve source read-only behavior.
6. Avoid including real user data in fixtures.
7. Ensure existing automated tests continue to pass.

---

## 🗺️ Roadmap

Planned development areas include:
- Continued XenForo connector testing and version compatibility
- vBulletin connector
- MyBB connector
- SMF connector
- Invision Community connector
- Expanded password handlers
- Additional BBCode mappings
- Extended migration fixtures
- Broader multilingual and RTL testing
- Improved documentation and packaging

*Roadmap items are development goals and do not represent current compatibility guarantees.*

---

## 💬 Support and Discussion

- **GitHub Issues:** [https://github.com/phpbb-seo/phpbb-migration-center/issues](https://github.com/phpbb-seo/phpbb-migration-center/issues)
- **Official Website:** [https://www.phpbbseo.com/](https://www.phpbbseo.com/)
- **GitHub Organization:** [https://github.com/phpbb-seo](https://github.com/phpbb-seo)

---

## 📄 License

This extension is licensed under the [GNU General Public License v2 (GPL-2.0)](LICENSE).  
Copyright (c) 2026 **phpBB SEO Team** ([https://www.phpbbseo.com/](https://www.phpbbseo.com/)).

### Disclaimer
*Migration tools modify complex relational data and filesystem content. Although Migration Center is designed around controlled batches, checkpoints, and recovery mechanisms, no migration tool can guarantee compatibility with every database customization, third-party add-on, or server environment. Always test on a backed-up staging installation and independently verify results before using migrated data in production.*