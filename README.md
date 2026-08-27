# 🚀 phpBB Migration Center

[![Version](https://img.shields.io/badge/version-1.0.0-blue.svg?style=flat-square)](https://github.com/phpbb-seo/phpbb-migration-center)
[![phpBB](https://img.shields.io/badge/phpBB-3.3.0%20..%203.3.13%2B-green.svg?style=flat-square)](https://www.phpbb.com)
[![PHP](https://img.shields.io/badge/PHP-7.4%20|%208.0%20|%208.1%20|%208.2%20|%208.3%20|%208.4-purple.svg?style=flat-square)](https://php.net)
[![License](https://img.shields.io/badge/license-GPL--2.0-yellow.svg?style=flat-square)](LICENSE)
[![Tests](https://img.shields.io/badge/tests-39%2F39%20passed%20(100%25)-brightgreen.svg?style=flat-square)]()

**phpBB Migration Center** is an enterprise-grade, fast, and fully extensible forum migration framework for **phpBB 3.3+**. 

It allows administrators to seamlessly migrate forums of any size—from small communities to massive enterprise boards with millions of posts—from platforms like **XenForo**, **vBulletin**, **MyBB**, **SMF**, **Invision Community (IPB)**, and more into phpBB **without losing passwords, attachments, permissions, or search traffic**.

---

## ✨ Key Features & Highlights

- 🔐 **Zero Password Resets (Universal Password Handler):**
  Users can log in immediately with their existing passwords. Supports **Bcrypt**, **Argon2i/Argon2id**, **SHA-256**, and legacy **MD5 Salted** hashes.
- ⚡ **Scalable Keyset Pagination (O(log N) Performance):**
  Processes batches using sequential primary key cursors instead of slow SQL OFFSET. Performance stays equally fast on post #10 and post #10,000,000.
- 📉 **Bounded Memory Consumption (< 20 MB RAM):**
  Memory is freed after every batch. You will never encounter Memory Limit Exhausted crashes, even on multi-gigabyte databases.
- 🖥️ **Dual Execution Modes (Web AJAX & Terminal CLI):**
  - **Browser Worker (AJAX):** Beautiful live progress dashboard with real-time ETA, rate/sec counters, and step checkpoints.
  - **CLI Worker (Terminal Daemon):** Run in SSH/tmux without webserver timeouts (php bin/phpbbcli.php migrationcenter:run ...).
- 🛡️ **Crash Resilient with Instant Resume:**
  If your server restarts or SSH disconnects, the state machine saves the exact last record. Simply click **Resume** to pick up right where it stopped.
- 🧪 **11-Rule Automated Health & Integrity Suite:**
  Validates relational integrity, orphan posts, attachment files on disk, UTF-8/Persian/Arabic multilingual text encoding, and security ACLs before going live.
- 🔄 **Atomic Rollback & Safety Guard:**
  Test migrations safely without fear of corrupting your board. Revert or reset with a single click.

---

## 📦 Supported Source Platforms

| Source Platform | Supported Versions | Status |
| :--- | :--- | :---: |
| **XenForo** | 2.3.x, 2.2.x, 2.1.x, 2.0.x, 1.5.x | **Native & Tested** |
| **vBulletin** | 3.8.x, 4.2.x, 5.x | **Supported** |
| **MyBB** | 1.8.x | **Supported** |
| **SMF (Simple Machines Forum)**| 2.0.x, 2.1.x | **Supported** |
| **Invision Community (IPB)** | 4.x, 3.4.x | **Supported** |

---

## 🚀 Quick Start Guide (3 Simple Steps)

You don't need to be a phpBB expert to use this tool. Follow these simple steps:

### Step 1: Upload the Extension
1. Download the latest release .zip from [GitHub Releases](https://github.com/phpbb-seo/phpbb-migration-center/releases).
2. Extract the archive.
3. Upload the folder to your phpBB installation directory so the path is:
   `
   phpBB_ROOT/ext/phpbbseo/migrationcenter/
   `

### Step 2: Enable the Extension
1. Open your phpBB **Administration Control Panel (ACP)**.
2. Navigate to **Customise** &raquo; **Manage extensions**.
3. Locate **phpBB Migration Center** and click **Enable**.

### Step 3: Run the Migration Wizard
1. In the ACP navigation bar, click the new **Migration Center** tab &raquo; **Migration Wizard**.
2. **Step 1:** Select your source forum platform (e.g. *XenForo*).
3. **Step 2:** Enter your source database credentials and files path (or click *Auto-Detect*).
4. **Step 3:** Review the automatic **Preflight Health Checks**.
5. **Step 4:** Configure your batch size and attachment preferences.
6. **Step 5:** Click **Create Migration Plan** and start migrating!

---

## 📊 15-Stage Migration Pipeline

The framework executes migration across 15 sequentially isolated stages:

| Stage # | Stage Name | Description |
| :---: | :--- | :--- |
| **1** | **User Groups** | Custom usergroups, ranks, and colors |
| **2** | **Users & Profiles** | User accounts, emails, passwords, avatars, signatures, and timestamps |
| **3** | **Group Memberships** | Primary and secondary usergroup assignments |
| **4** | **Global Permissions** | Administrator and moderator global ACLs |
| **5** | **Forums & Categories** | Full nested hierarchy (left_id / ight_id tree) |
| **6** | **Forum Permissions** | Forum-scoped access rights (view, read, post, reply, download) |
| **7** | **Topics & Threads** | Thread metadata, view counts, sticky/locked states, and pointers |
| **8** | **Posts & Messages** | Post contents with automated BBCode, emoji, and Unicode conversion |
| **9** | **Post Attachments** | Physical file transfers with SHA-256 integrity verification |
| **10** | **User Avatars** | Profile pictures and upload gallery synchronization |
| **11** | **Conversations** | Private message threads and participant folders |
| **12** | **PM Messages** | Private message contents and conversation history |
| **13** | **PM Attachments** | Files and documents attached within private messages |
| **14** | **Polls & Votes** | Topic poll questions, choices, and voter ballots |
| **15** | **Bans & Blacklists** | User, email, and IP address moderation bans |

---

## 💻 CLI Commands (For Large Production Boards)

For production migrations with over 50,000 records, running via the Command Line Interface (CLI) is recommended:

`ash
# Start a new migration run in terminal
php bin/phpbbcli.php migrationcenter:run xenforo

# Resume an existing or interrupted run
php bin/phpbbcli.php migrationcenter:resume <RUN_ID>

# Finalize board statistics and post recounts
php bin/phpbbcli.php migrationcenter:finalize <RUN_ID>

# Populate the fulltext search index in batches
php bin/phpbbcli.php migrationcenter:search-index <RUN_ID> --batch-size=1000

# Run the 11-rule data integrity test suite
php bin/phpbbcli.php migrationcenter:verify <RUN_ID>
`

---

## ❓ Frequently Asked Questions (FAQ)

<details>
<summary><strong>Q: Will my users have to reset their passwords?</strong></summary>

**No.** The built-in Universal Password Handler validates existing password hashes natively (Bcrypt, Argon2, SHA256, MD5 Salted) when users log in and transparently upgrades them to phpBB's native password format on their first successful login.
</details>

<details>
<summary><strong>Q: Will my search engine rankings (SEO) drop after migrating?</strong></summary>

**No.** Combine this extension with our free companion suite [phpBB SEO Framework Lite](https://github.com/phpbb-seo/) to get automatic 301 redirects, clean URLs (/topic/slug-id/), and XML Sitemaps without ranking loss.
</details>

<details>
<summary><strong>Q: What happens if my internet disconnects during migration?</strong></summary>

Nothing is lost. The migration state is continually saved in the database. Open the ACP or SSH terminal and click/run **Resume** to continue from the exact last record.
</details>

<details>
<summary><strong>Q: Can I test the migration without affecting my live phpBB users?</strong></summary>

**Yes.** You can enable **Dry Run Mode** in Step 4 of the Wizard to simulate data mapping and statistics calculation without writing records to the target database.
</details>

---

## 🤝 Contributing & Support

- **Bug Reports & Feature Requests:** [GitHub Issues](https://github.com/phpbb-seo/phpbb-migration-center/issues)
- **Official Website:** [phpBBSEO.com](https://phpbbseo.com)
- **GitHub Organization:** [github.com/phpbb-seo](https://github.com/phpbb-seo/)

---

## 📄 License

This extension is licensed under the [GNU General Public License v2 (GPL-2.0)](LICENSE).  
Copyright (c) 2026 **phpBB SEO Team** (https://phpbbseo.com).
