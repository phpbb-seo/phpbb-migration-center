# phpBB Migration Center

[![Version](https://img.shields.io/badge/version-1.0.0--beta.4-blue.svg?style=flat-square)](https://github.com/phpbb-seo/phpbb-migration-center)
[![phpBB](https://img.shields.io/badge/phpBB-3.3.x-green.svg?style=flat-square)](https://www.phpbb.com)
[![PHP](https://img.shields.io/badge/PHP-%3E%3D%207.4-purple.svg?style=flat-square)](https://php.net)
[![License](https://img.shields.io/badge/license-GPL--2.0-yellow.svg?style=flat-square)](LICENSE)

A clean and simple extension to migrate your community data from **XenForo**, **vBulletin**, **MyBB**, or **SMF** to **phpBB 3.3+**.

---

## 📦 Supported Forums & Versions

Only the following platforms and versions are supported and tested:

| Source Forum | Tested Versions | What is Migrated |
|:---|:---:|:---|
| **XenForo** | `2.x` | Users, Passwords, Groups, Forums, Topics, Posts, Attachments, Avatars, Conversations (PMs) |
| **vBulletin** | `3.8.x`, `4.2.x`, `6.x` | Users, Passwords, Groups, Forums, Topics, Posts, Attachments, Avatars, PMs, Polls, Bans |
| **MyBB** | `1.8.x` | Users, Passwords, Groups, Forums, Topics, Posts, Attachments, Avatars, PMs |
| **SMF** | `2.0.x`, `2.1.x` | Users, Passwords, Groups, Boards, Topics, Posts, Attachments, Avatars, PMs, Polls, Bans |

> **Password Security Note:** Members do **not** need to reset their passwords. Existing passwords continue to work seamlessly and are automatically upgraded to phpBB's native secure password hash upon their first login.

---

## 🛠️ Step-by-Step Installation Guide

If you are new to phpBB, follow these simple steps to install and run your migration:

### Step 1: Upload the Extension Files
1. Download or clone this repository.
2. In your phpBB forum root directory, open the `ext/` folder.
3. Create a folder named `phpbbseo`, and inside it create another folder named `migrationcenter`.
4. Place all files from this repository inside that folder:
   ```text
   phpBB_ROOT/
     └── ext/
           └── phpbbseo/
                 └── migrationcenter/
                       ├── acp/
                       ├── adm/
                       ├── config/
                       ├── composer.json
                       └── ext.php
   ```

### Step 2: Enable the Extension in phpBB
1. Log in to your forum as an Administrator.
2. Go to the **Administration Control Panel (ACP)**.
3. Click on the **Customise** tab in the top navigation bar.
4. In the left menu, click **Manage extensions** (under *Extension Management*).
5. Locate **phpBB Migration Center** in the list and click **Enable**.

### Step 3: Run the Migration
1. After enabling, a new **Migration** tab will appear at the top of your ACP.
2. Click on the **Migration** tab.
3. Select your old forum software (e.g. *vBulletin 6.x* or *XenForo 2.x*).
4. Enter the database connection details of your old forum (Database Host, Database Name, Username, and Password).
5. Click **Start Migration** and follow the live progress bar on screen.

---

## ⚡ Optional: Run via Command Line (CLI)

For large forums with many posts, running via command line is recommended to prevent web server timeouts:

```bash
# Run migration
php bin/phpbbcli.php migrationcenter:run --run-id=<YOUR_RUN_ID>

# Resume an interrupted migration anytime
php bin/phpbbcli.php migrationcenter:resume --run-id=<YOUR_RUN_ID>
```

---

## ⚙️ Requirements

- **phpBB:** 3.3.0 to 3.3.x
- **PHP:** 7.4, 8.0, 8.1, 8.2, or 8.3
- **PHP Extensions:** `pdo_mysql`, `json`, `mbstring`

---

## 🧪 Automated Testing

To run the automated test suite on your server:
```bash
php tests/ci_runner.php
```
*(All 30 unit and integration tests passing)*

---

## 🛡️ SEO Preservation & 301 Redirect Guide

One of the biggest risks during a forum platform migration is losing Google search rankings, backlinks, and organic traffic due to broken URLs (404 errors).

phpBB Migration Center provides an enterprise-grade **Permanent 301 Redirection infrastructure** designed to maintain 100% of your Google PageRank and backlink equity:

### 1. Permanent ID Mapping Table (`phpbb_migration_id_map`)
- During migration, every source ID (topics, posts, forums, users) is indexed and stored in `phpbb_migration_id_map`.
- **Data Safety Guarantee:** When Migration Center is disabled or uninstalled, the `phpbb_migration_id_map` table is **deliberately preserved** in your database so legacy redirects continue to work indefinitely.

### 2. Integration with phpBB SEO Framework
For the ultimate SEO setup, install the [phpBB SEO Framework](https://github.com/phpbb-seo/). It automatically integrates with `phpbb_migration_id_map` to seamlessly redirect old XenForo, vBulletin, SMF, or MyBB URLs directly to modern, canonical, human-readable slug URLs (e.g. `/topic/123-title`) with `HTTP 301 Moved Permanently`.

### 3. Emergency Standalone Fallback (`legacy_redirect.php`)
If you have not installed the phpBB SEO Framework yet, a lightweight, zero-dependency script is included:
- **Location:** `ext/phpbbseo/migrationcenter/legacy_redirect.php` (can also be copied to your phpBB root as `legacy_redirect.php`).
- Directly queries `phpbb_migration_id_map` in under 2ms and issues `301 Moved Permanently` headers.

### 4. Webserver Rewrite Rules

Add the appropriate rules to the top of your webserver configuration to intercept old URLs:

#### Apache / LiteSpeed (`.htaccess`):
```apache
<IfModule mod_rewrite.c>
RewriteEngine On

# XenForo 301 Redirects:
RewriteRule ^threads/[^/]*\.([0-9]+)/?.*$ index.php?threads=$1 [L,QSA]
RewriteRule ^threads/([0-9]+)/?.*$ index.php?threads=$1 [L,QSA]
RewriteRule ^posts/([0-9]+)/?.*$ index.php?posts=$1 [L,QSA]
RewriteRule ^forums/[^/]*\.([0-9]+)/?.*$ index.php?forums=$1 [L,QSA]
RewriteRule ^members/[^/]*\.([0-9]+)/?.*$ index.php?members=$1 [L,QSA]

# vBulletin 301 Redirects:
RewriteCond %{QUERY_STRING} (?:^|&)t=([0-9]+) [NC]
RewriteRule ^showthread\.php$ index.php?t=%1 [L,QSA]
RewriteCond %{QUERY_STRING} (?:^|&)f=([0-9]+) [NC]
RewriteRule ^forumdisplay\.php$ index.php?f=%1 [L,QSA]
</IfModule>
```

#### Nginx (`nginx.conf`):
```nginx
# XenForo 301 Redirects:
location ~ ^/threads/[^/]*\.([0-9]+)/? {
    rewrite ^/threads/[^/]*\.([0-9]+)/?.*$ /index.php?threads=$1 last;
}
location ~ ^/posts/([0-9]+)/? {
    rewrite ^/posts/([0-9]+)/?.*$ /index.php?posts=$1 last;
}
location ~ ^/forums/[^/]*\.([0-9]+)/? {
    rewrite ^/forums/[^/]*\.([0-9]+)/?.*$ /index.php?forums=$1 last;
}
```

> **Tip:** You can view and copy customized rules tailored to your exact source platform directly from the **Health & Finalization Dashboard** in the phpBB ACP.

---

## 📄 License

This project is licensed under the [GNU General Public License v2 (GPL-2.0)](LICENSE).