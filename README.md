# phpBB Migration Center

[![Version](https://img.shields.io/badge/version-1.0.0--beta.4-blue.svg?style=flat-square)](https://github.com/phpbb-seo/phpbb-migration-center)
[![phpBB](https://img.shields.io/badge/phpBB-3.3.x-green.svg?style=flat-square)](https://www.phpbb.com)
[![PHP](https://img.shields.io/badge/PHP-%3E%3D%207.4-purple.svg?style=flat-square)](https://php.net)
[![License](https://img.shields.io/badge/license-GPL--2.0-yellow.svg?style=flat-square)](LICENSE)
[![Tests](https://github.com/phpbb-seo/phpbb-migration-center/actions/workflows/tests.yml/badge.svg)](https://github.com/phpbb-seo/phpbb-migration-center/actions/workflows/tests.yml)

phpBB Migration Center is an extension for **phpBB 3.3+** that migrates forum content, user accounts, password hashes, permissions, attachments, private messages, polls, and bans from external forum platforms into a phpBB database.

---

## Verified & Supported Platforms

The following platforms and versions have dedicated providers, normalizers, converters, and automated test coverage in this release:

| Source Platform | Verified Versions | Password / Auth Scheme | Configuration Files | Data Extracted & Migrated | CI Test Coverage |
|:---|:---|:---|:---|:---|:---:|
| **XenForo** | 1.5.x<br>2.0.x<br>2.1.x<br>2.2.x<br>2.3.x | Native XF handlers, Bcrypt, Argon2id, Core upgrade hashes | `src/config.php`<br>`library/config.php` | Users, Groups, Memberships, Forum Tree, Node & Global Permissions, Topics, Posts, BBCode, Attachments, Avatars, Conversations | ✅ Tested |
| **vBulletin** | 3.8.x<br>4.2.x<br>5.x<br>6.0.x | **vB3/vB4:** Dual-salt MD5 (`md5(md5(pass) . salt)`)<br>**vB5/vB6:** Argon2id with vB6 native MD5 pre-hash and standard Argon2id | `includes/config.php`<br>`core/includes/config.php` | Users, Groups, Memberships, Node & Forum Permissions, Topics & Posts (standard thread/post tables and vB5/vB6 `node` tree), Attachments (DB & filesystem `filedata`), Avatars, PMs & Attachments, Thread Polls, Bans | ✅ Tested |
| **MyBB** | 1.8.x | Salted MD5 (`md5(md5(salt) . md5(pass))`) | `inc/config.php` | Users, Usergroups, Memberships, Forum Tree (Nested Sets rebuild), Topics, Posts, BBCode, Inline Attachments, Avatars, Private Messages, Permissions | ✅ Tested |
| **SMF (Simple Machines Forum)** | 2.0.x<br>2.1.x | **SMF 2.0:** SHA1 + lowercase username (`sha1(strtolower(user) . pass)`)<br>**SMF 2.1:** SHA256 / SHA512 | `Settings.php`<br>`index.php` | Users, Membergroups, Memberships, Boards & Categories, Topics, Posts, BBCode, Attachments, Avatars, Personal Messages & Attachments, Polls, Bans, Permissions | ✅ Tested |
| **Invision Community (IPB)** | 4.x | - | - | Planned (not implemented in this release) | ⏳ Roadmap |

---

## Migration Pipeline

Migrations execute sequentially through 15 stages:

1. **User Groups:** Source group names, styles, and group type attributes.
2. **Users & Passwords:** User records, emails, join dates, registration details, and source password hash tokens.
3. **Group Memberships:** Primary and secondary group memberships, leader flags, and rank mappings.
4. **Global Permissions:** Administrative and moderation capabilities mapped to phpBB permission options.
5. **Forums & Categories:** Reconstructs board categories and forum hierarchies using nested-set indices (`left_id`/`right_id`).
6. **Node Permissions:** Forum-specific read, write, reply, and moderation access rules.
7. **Topics:** Thread titles, views, reply counts, sticky/pinned states, open/locked statuses, and creator IDs.
8. **Posts & BBCode:** Post contents parsed and normalized into phpBB `s9e\TextFormatter` XML (quotes, code blocks, media, list tags, smilies).
9. **Post Attachments:** File attachments mapped and transferred to the phpBB `files/` directory with file integrity checks.
10. **User Avatars:** Custom user avatars transferred to phpBB avatar storage with dimension metadata.
11. **Conversations:** Private discussion threads and participants.
12. **Private Messages:** Personal messages converted and linked to sender/recipient folders.
13. **PM Attachments:** Attachments linked to private messages transferred with folder association.
14. **Thread Polls:** Poll questions, multiple choice settings, voting options, and user votes.
15. **Bans & Blacklists:** Banned user accounts, emails, and IP restrictions.

---

## Role & Permission Mapping

- **Administrators:** Users marked as administrators in the source platform are placed into phpBB `ADMINISTRATORS` (Group ID 5) and assigned the Full Administrator role (`auth_role_id = 4`, `a_*` capabilities), ensuring immediate access to the Administration Control Panel (ACP).
- **Moderators:** Source moderators are assigned to phpBB `GLOBAL_MODERATORS` (Group ID 4) with moderation privileges (`m_*`).
- **Standard Users:** Retain standard group assignments and forum-level access without permission conflicts.

---

## Password Handling & Transparent Upgrades

Migrated users do not need to reset their passwords:
1. The extension registers dedicated password drivers in the phpBB service container (`vb_password_driver`, `mybb_password_driver`, `smf_password_driver`, `xf_password_handler`).
2. When a migrated user logs in with their original credentials, the corresponding driver validates the source hash.
3. Upon successful validation, phpBB automatically re-hashes the password using phpBB's native hashing algorithm (Argon2id or Bcrypt) and updates the database record. Subsequent logins use native phpBB verification.

---

## Installation & Setup

### Requirements
- **phpBB:** 3.3.0 to 3.3.x
- **PHP:** 7.4, 8.0, 8.1, 8.2, or 8.3
- **PHP Extensions:** `json`, `pdo_mysql`, `mbstring`
- Direct access to the source database and attachment directory

### Installation
1. Place the extension into your phpBB directory:
   ```text
   phpBB_ROOT/ext/phpbbseo/migrationcenter/
   ```
2. Enable the extension in **ACP > Customise > Extension Management > Manage extensions**.

---

## Execution Modes & CLI Commands

Migrations can be executed via the ACP web interface or through the phpBB CLI console.

### Preflight Diagnostics
```bash
php bin/phpbbcli.php migrationcenter:check --run-id=<RUN_ID>
```

### Run Migration
```bash
php bin/phpbbcli.php migrationcenter:run --run-id=<RUN_ID>
```

### Resume Interrupted Migration
```bash
php bin/phpbbcli.php migrationcenter:resume --run-id=<RUN_ID>
```

---

## Automated Test Suite

The extension includes 30 automated unit and integration test suites that run independently of a live web server:

```bash
php tests/ci_runner.php
```

All 30 test suites pass in the test environment:
- `UnicodeTest`
- `XfPasswordHandlerTest`, `XfAttachmentPathResolverTest`, `XfAvatarPathResolverTest`, `XfConfigDetectorTest`, `XfConversationNormalizerTest`, `XfForumTreeBuilderTest`, `XfNodePermissionTest`, `XfPermissionTranslatorTest`, `XfTopicNormalizerTest`, `XfUserNormalizationTest`
- `VbGroupNormalizerTest`, `VbPasswordDriverTest`, `VbUserNormalizerTest`, `VbMessageConverterTest`, `VbCredentialPrecedenceRegressionTest`, `VbProviderSeparationTest`, `Vb6MigrationTest`, `VbConfigDetectorTest`
- `MybbPasswordDriverTest`, `MybbMessageConverterTest`, `MybbGroupNormalizerTest`, `MybbUserNormalizerTest`, `MybbConfigDetectorTest`
- `SmfPasswordDriverTest`, `SmfMessageConverterTest`, `SmfConfigDetectorTest`, `SmfUserNormalizerTest`, `SmfGroupNormalizerTest`
- `CrossEngineAdminModeratorPermissionsTest`

---

## License

GNU General Public License, version 2 (GPL-2.0). See [LICENSE](LICENSE) for details.