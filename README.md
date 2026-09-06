# 🚀 phpBB Migration Center

[![Version](https://img.shields.io/badge/version-1.0.0--beta.3-blue.svg?style=flat-square)](https://github.com/phpbb-seo/phpbb-migration-center)
[![phpBB](https://img.shields.io/badge/phpBB-3.3.x-green.svg?style=flat-square)](https://www.phpbb.com)
[![PHP](https://img.shields.io/badge/PHP-%3E%3D%207.4-purple.svg?style=flat-square)](https://php.net)
[![License](https://img.shields.io/badge/license-GPL--2.0-yellow.svg?style=flat-square)](LICENSE)
[![Tests](https://github.com/phpbb-seo/phpbb-migration-center/actions/workflows/tests.yml/badge.svg)](https://github.com/phpbb-seo/phpbb-migration-center/actions/workflows/tests.yml)

**phpBB Migration Center** is an advanced, modular migration framework for **phpBB 3.3+**. It enables seamless migration of forums, users, passwords, permissions, posts, attachments, private messages, and polls from other forum platforms into phpBB.

---

## 🔌 Supported Platforms

| Source Platform | Supported Versions | Status | Key Highlights |
|:---|:---:|:---:|:---|
| **XenForo** | 1.x, 2.x | ✅ Ready | Passwords (auto-rehash), Rich BBCode, Conversations, Attachments |
| **vBulletin** | 3.8.x, 4.2.x | ✅ Ready | Dual-salt MD5 passwords, Quotes/BBCode, Attachments, PMs, Polls |
| **MyBB** | 1.8.x | ✅ Ready | MD5+Salt passwords, BBCode & Inlines, Avatars, PMs, Nested Sets |
| **SMF / IPB** | 2.x / 4.x | ⏳ Planned | On roadmap |

---

## ✨ Key Features

- **Dual Execution Modes:** Run migrations via browser-based AJAX UI with live progress, or via headless CLI worker for massive boards without server timeout limits.
- **Transparent Password Migration:** Users keep existing passwords. Hashes are securely verified and transparently upgraded to native phpBB Argon2id/Bcrypt on first login.
- **Automatic Forum Hierarchy:** Built-in nested-set tree rebuilding (`left_id`/`right_id`), parent-first ordering, and subforum preservation.
- **Rich BBCode & Media Normalization:** Standardizes quotes, code blocks, video tags, size scales, and inline attachments into phpBB native `s9e\TextFormatter` XML.
- **Full Content Pipeline:** User groups, profile data, avatars, attachments (with integrity check), private messages/conversations, polls, and banlists.
- **Reliable & Resumable:** Cursor-based pagination, pause/resume capability, heartbeat monitoring, stage checkpoints, and rollback controls.

---

## 🚀 Quick Start

### 1. Installation
Extract the extension files into your phpBB directory:
```text
phpBB_ROOT/ext/phpbbseo/migrationcenter/
```

### 2. Enable Extension
1. Go to **ACP > Customise > Extension Management > Manage extensions**.
2. Locate **phpBB Migration Center** and click **Enable**.

### 3. Run Migration
1. Navigate to the **Migration** tab in your phpBB ACP.
2. Select your source forum platform and provide database connection details.
3. Verify preflight diagnostics and proceed with the migration via ACP or CLI:

```bash
# Run migration via CLI (recommended for large forums)
php bin/phpbbcli.php migrationcenter:run --run-id=<RUN_ID>
```

---

## 📋 Requirements

- **phpBB:** 3.3.0 to 3.3.x
- **PHP:** 7.4, 8.0, 8.1, 8.2, or 8.3
- **PHP Extensions:** `json`, `pdo_mysql`, `mbstring`
- Direct access to the source forum database and file attachments

---

## 🧪 Testing

Run the automated standalone test suite:
```bash
php tests/ci_runner.php
```

---

## 📄 License & Community

- **License:** [GNU General Public License v2 (GPL-2.0)](LICENSE)
- **Issues & Bug Reports:** [GitHub Issues](https://github.com/phpbb-seo/phpbb-migration-center/issues)
- **Website:** [phpBB SEO](https://www.phpbbseo.com/)