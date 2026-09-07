# 🚀 phpBB Migration Center

[![Version](https://img.shields.io/badge/version-1.0.0--beta.4-blue.svg?style=flat-square)](https://github.com/phpbb-seo/phpbb-migration-center)
[![phpBB](https://img.shields.io/badge/phpBB-3.3.x-green.svg?style=flat-square)](https://www.phpbb.com)
[![PHP](https://img.shields.io/badge/PHP-%3E%3D%207.4-purple.svg?style=flat-square)](https://php.net)
[![License](https://img.shields.io/badge/license-GPL--2.0-yellow.svg?style=flat-square)](LICENSE)
[![Tests](https://github.com/phpbb-seo/phpbb-migration-center/actions/workflows/tests.yml/badge.svg)](https://github.com/phpbb-seo/phpbb-migration-center/actions/workflows/tests.yml)

**phpBB Migration Center** is an enterprise-grade, modular migration framework for **phpBB 3.3+**. It enables seamless, high-fidelity migration of forums, users, passwords, permissions, topics, posts, attachments, private messages, polls, and banlists from major community platforms directly into phpBB.

---

## 🔌 Supported Platforms

| Source Platform | Supported Versions | Status | Key Highlights |
|:---|:---:|:---:|:---|
| **XenForo** | 1.x, 2.x | ✅ Ready | Native passwords (auto-rehash), Rich BBCode, Conversations, Post Attachments, Avatars |
| **vBulletin** | 3.8.x, 4.2.x, 5.x, 6.x | ✅ Ready | Dual-salt MD5 & Argon2id passwords (with vB6 MD5 pre-hash), Node/Channel hierarchy, Quotes/BBCode, Attachments, PMs, Polls |
| **MyBB** | 1.8.x | ✅ Ready | MD5+Salt passwords, BBCode & Inlines, Avatars, PMs, Nested Sets tree rebuild |
| **SMF (Simple Machines Forum)** | 2.0.x, 2.1.x | ✅ Ready | SHA256/SHA1 passwords, Boards & Categories, Attachments, PMs, Polls, Bans & Permissions |
| **Invision Community (IPB)** | 4.x | ⏳ Planned | Scheduled on roadmap |

---

## ✨ Key Features

- **Full 15-Stage Modular Pipeline:** Migrates user groups, users, passwords, group memberships, global permissions, forum trees, node permissions, topics, posts & BBCode, post attachments, user avatars, conversations, private messages, PM attachments, polls, and banlists.
- **Transparent Password Authentication:** Users keep their existing passwords. Supported hashes (Argon2id, SHA256, MD5 dual-salt, Bcrypt) are securely verified on first login and automatically upgraded to native phpBB Argon2id/Bcrypt hashes.
- **Cross-Engine Role & Privilege Preservation:** Source administrators and moderators retain their full administrative and moderating capabilities in phpBB without manual role reconfiguration.
- **Dual Execution Modes:**
  - **ACP Web Wizard:** Interactive AJAX interface with real-time progress bars, step-by-step metrics, and diagnostics.
  - **CLI Worker:** Headless CLI runner built for massive boards (millions of posts) bypassing web server execution timeouts.
- **Automatic Forum Hierarchy:** Built-in nested-set tree rebuilding (`left_id`/`right_id`), parent-first dependency ordering, and category preservation.
- **Rich BBCode & Media Normalization:** Standardizes quotes, code blocks, video tags, font sizes, colors, and inline attachments into phpBB native `s9e\TextFormatter` XML.
- **Fault-Tolerant & Resumable:** Cursor-based pagination, pause/resume capability, heartbeat monitoring, stage checkpoints, and rollback safety.

---

## 🚀 Quick Start

### 1. Installation
Extract or clone the extension into your phpBB directory:
```text
phpBB_ROOT/ext/phpbbseo/migrationcenter/
```

### 2. Enable Extension
1. Go to **ACP > Customise > Extension Management > Manage extensions**.
2. Locate **phpBB Migration Center** and click **Enable**.

### 3. Run Migration
1. Navigate to the **Migration** tab in your phpBB ACP.
2. Select your source forum platform (XenForo, vBulletin, MyBB, or SMF) and enter database connection details.
3. Verify preflight diagnostics and proceed with the migration via ACP or CLI:

```bash
# Verify connectivity and schema preflight
php bin/phpbbcli.php migrationcenter:check --run-id=<RUN_ID>

# Run migration via CLI (recommended for large forums)
php bin/phpbbcli.php migrationcenter:run --run-id=<RUN_ID>

# Resume an interrupted migration
php bin/phpbbcli.php migrationcenter:resume --run-id=<RUN_ID>
```

---

## 📋 Requirements

- **phpBB:** 3.3.0 to 3.3.x
- **PHP:** 7.4, 8.0, 8.1, 8.2, or 8.3
- **PHP Extensions:** `json`, `pdo_mysql`, `mbstring`
- Direct access to the source forum database and file attachment storage

---

## 🧪 Automated Testing

The project includes an automated standalone test runner with 30 comprehensive unit and integration test suites:
```bash
php tests/ci_runner.php
```

---

## 📄 License & Community

- **License:** [GNU General Public License v2 (GPL-2.0)](LICENSE)
- **Issues & Bug Reports:** [GitHub Issues](https://github.com/phpbb-seo/phpbb-migration-center/issues)
- **Website:** [phpBB SEO](https://www.phpbbseo.com/)