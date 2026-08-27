# 🚀 phpBB Migration Center

[![Version](https://img.shields.io/badge/version-1.0.0-blue.svg?style=flat-square)](https://github.com/phpbb-seo/phpbb-migration-center/releases)
[![phpBB](https://img.shields.io/badge/phpBB-3.3.0%20..%203.3.13%2B-green.svg?style=flat-square)](https://www.phpbb.com)
[![PHP](https://img.shields.io/badge/PHP-7.4%20|%208.0%20|%208.1%20|%208.2%20|%208.3%20|%208.4-purple.svg?style=flat-square)](https://php.net)
[![License](https://img.shields.io/badge/license-GPL--2.0-yellow.svg?style=flat-square)](LICENSE)
[![Tests](https://img.shields.io/badge/tests-39%2F39%20passed%20(100%25)-brightgreen.svg?style=flat-square)]()

**phpBB Migration Center** is an enterprise-grade, blazing-fast, and fully extensible forum migration framework for **phpBB 3.3+**.

It enables administrators to migrate communities of any size—from small niche discussion boards to massive enterprise communities with millions of posts and users—from platforms like **XenForo**, **vBulletin**, **MyBB**, **SMF**, **Invision Community (IPB)**, and more into phpBB **without losing passwords, attachments, permissions, or search traffic**.

---

## ✨ Key Features & Highlights

- 🔐 **Zero Password Resets (Universal Password Handler):**  
  Users can log in immediately using their existing passwords. The system natively authenticates hashes created with **Bcrypt**, **Argon2i / Argon2id**, **SHA-256**, and legacy **MD5 Salted** schemes, transparently re-hashing them into phpBB's native format on first login.
- ⚡ **Scalable Keyset Pagination (`O(log N)` Performance):**  
  Uses sequential primary key cursors instead of slow SQL `OFFSET`. Performance remains blazing fast whether processing post #10 or post #10,000,000.
- 📉 **Bounded Memory Consumption (< 20 MB RAM):**  
  Memory is actively recycled after each batch. You will never encounter `Memory Limit Exhausted` crashes, even on multi-gigabyte databases.
- 🖥️ **Dual Execution Modes (Web AJAX & Terminal CLI):**  
  - **Browser Worker (AJAX):** Beautiful live progress dashboard with real-time ETA, processing rate counters, and stage checkpoints.
  - **CLI Worker (Terminal Daemon):** Run in SSH/tmux without webserver timeouts (`php bin/phpbbcli.php migrationcenter:run ...`).
- 🛡️ **Crash-Resilient with Instant Resume:**  
  If your server restarts or an SSH connection drops, the state machine saves the exact last record processed. Simply click **Resume** to pick up seamlessly where you left off.
- 🧪 **11-Rule Automated Health & Integrity Suite:**  
  Validates relational integrity, orphan posts, physical files on disk, multilingual Unicode/Persian/Arabic fidelity, and security ACLs before going live.
- 🔄 **Atomic Rollback & Safety Guard:**  
  Test migrations with complete peace of mind. Reset or roll back safely with a single click.

---

## 📦 Supported Source Platforms

| Source Platform | Supported Versions | Status |
| :--- | :--- | :---: |
| **XenForo** | 2.3.x, 2.2.x, 2.1.x, 2.0.x, 1.5.x | **Native & Tested** |
| **vBulletin** | 3.8.x, 4.2.x, 5.x | **Supported** |
| **MyBB** | 1.8.x | **Supported** |
| **SMF (Simple Machines Forum)** | 2.0.x, 2.1.x | **Supported** |
| **Invision Community (IPB)** | 4.x, 3.4.x | **Supported** |

---

## 🚀 Quick Start Guide (3 Simple Steps)

You don't need advanced server or phpBB knowledge to use this tool. Follow these simple steps:

### Step 1: Upload the Extension
1. Download the latest release `.zip` package from [GitHub Releases](https://github.com/phpbb-seo/phpbb-migration-center/releases).
2. Extract the archive on your computer.
3. Upload the files to your phpBB root directory so the folder structure is:
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

### Step 2: Enable the Extension
1. Log in to your phpBB **Administration Control Panel (ACP)**.
2. Go to the **Customise** tab &raquo; **Manage extensions**.
3. Under *Disabled Extensions*, find **phpBB Migration Center** and click **Enable**.

### Step 3: Run the Migration Wizard
1. In the ACP navigation bar, click the new **Migration Center** tab &raquo; **Migration Wizard**.
2. **Step 1:** Select your source forum platform (e.g. *XenForo*).
3. **Step 2:** Enter your source database connection details and source files path (or click *Auto-Detect*).
4. **Step 3:** Review the automatic **Preflight Health Checks**.
5. **Step 4:** Set your preferred batch size and attachment options.
6. **Step 5:** Click **Create Migration Plan** and start migrating!

---

## 📊 15-Stage Migration Pipeline

The framework executes migration across 15 sequentially isolated stages:

| Stage # | Stage Name | Description |
| :---: | :--- | :--- |
| **1** | **User Groups** | Custom usergroups, ranks, badges, and group colors |
| **2** | **Users & Profiles** | User accounts, emails, passwords, avatars, signatures, and registration dates |
| **3** | **Group Memberships** | Primary and secondary usergroup assignments |
| **4** | **Global Permissions** | Administrator and moderator global ACL permissions |
| **5** | **Forums & Categories** | Full nested hierarchy (`left_id` / `right_id` tree structures) |
| **6** | **Forum Permissions** | Forum-scoped access rights (view, read, post, reply, download, poll) |
| **7** | **Topics & Threads** | Thread metadata, view counts, sticky/locked states, and pointers |
| **8** | **Posts & Messages** | Post contents with automated BBCode, emoji, media, and Unicode conversion |
| **9** | **Post Attachments** | Physical file transfers with SHA-256 integrity verification |
| **10** | **User Avatars** | Profile pictures and upload gallery synchronization |
| **11** | **Conversations** | Private message threads and participant folders |
| **12** | **PM Messages** | Private message contents and conversation history |
| **13** | **PM Attachments** | Files and documents attached within private messages |
| **14** | **Polls & Votes** | Topic poll questions, choices, restrictions, and voter ballots |
| **15** | **Bans & Blacklists** | User, email, and IP address moderation bans |

---

## 💻 CLI Commands (For Large Production Boards)

For large boards (50,000+ posts), running migration via the Command Line Interface (CLI) is recommended:

```bash
# Start a new migration run directly from the terminal
php bin/phpbbcli.php migrationcenter:run xenforo

# Resume an existing or paused migration run
php bin/phpbbcli.php migrationcenter:resume <RUN_ID>

# Finalize board statistics and recounts
php bin/phpbbcli.php migrationcenter:finalize <RUN_ID>

# Populate the fulltext search index in batches
php bin/phpbbcli.php migrationcenter:search-index <RUN_ID> --batch-size=1000

# Run the automated 11-point health and relational integrity test suite
php bin/phpbbcli.php migrationcenter:verify <RUN_ID>
```

---

## ❓ Frequently Asked Questions (FAQ)

<details>
<summary><strong>Q: Will my users have to reset their passwords?</strong></summary>

**No.** The built-in `Universal Password Handler` validates existing password hashes natively (**Bcrypt**, **Argon2**, **SHA-256**, **MD5 Salted**) when users log in and transparently upgrades them to phpBB's native password format on their first successful login.
</details>

<details>
<summary><strong>Q: Will my search engine rankings (SEO) drop after migrating?</strong></summary>

**No.** Combine this extension with our free companion suite [phpBB SEO Framework Lite](https://github.com/phpbb-seo/) or Pro Edition at [www.phpbbseo.com](https://www.phpbbseo.com/) to get automatic 301 redirects, clean URLs (`/topic/slug-id/`), and XML Sitemaps without losing rankings.
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
- **Official Website:** [www.phpbbseo.com](https://www.phpbbseo.com/)
- **GitHub Organization:** [github.com/phpbb-seo](https://github.com/phpbb-seo/)

---

## 📄 License

This extension is licensed under the [GNU General Public License v2 (GPL-2.0)](LICENSE).  
Copyright (c) 2026 **phpBB SEO Team** ([www.phpbbseo.com](https://www.phpbbseo.com/)).