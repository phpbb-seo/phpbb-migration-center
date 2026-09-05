# vBulletin 3.8 / 4.2 Migration Discovery Errata & Deferred Technical Items

This document records architectural nuances, discovery items, and invariants that remain unverified or deferred for implementation in their respective subsequent migration phases.

---

## 1. Permissions & Usergroup Bitfields (Deferred to Phase D)
* **Finding:** Permissions must be decoded directly from real vBulletin bitfield masks (`forumpermissions`, `genericpermissions`, `adminpermissions`), not from hard-coded group IDs.
* **phpBB Authoritative Target:** Permissions must be written directly to `phpbb_acl_groups` and `phpbb_acl_users` tables. `phpbb_moderator_cache` is a generated read-cache, not the authoritative source of permissions.
* **Security Guardrail:** Administrator mappings must never automatically grant phpBB `founder` status or unrestricted master privileges.

---

## 2. Password Persistence & Driver (Deferred to Phase C)
* **Finding:** Storing legacy vBulletin password hashes (`md5(md5($pass) . $salt)`) in phpBB requires a registered phpBB password driver or a compatible format handler (`vbulletin$hash$salt`) to allow transparent re-hashing upon user login.
* **Security Guardrail:** Salt and hash comparisons must use constant-time operations where applicable to prevent timing attacks.

---

## 3. Content, Delimiters & BBCodes (Deferred to Phases F, G, J)
* **Poll Options & Votes:** vBulletin serializes poll options with newline (`\n`) delimiters. Exact delimiter parsing and vote option mapping require source-code and database verification in Phase J.
* **Custom BBCodes:** Custom BBCode migration requires collision detection policies to avoid overwriting default phpBB BBCodes or injecting un-sanitized HTML replacements.
* **PM Attachments:** Native vBulletin 3.8.x and 4.2.x core schemas (`pmtext`, `pm`) do not include attachment columns. This feature is classified as **NOT APPLICABLE** in native core vBulletin.

---

## 4. Storage & Filesystem Paths (Deferred to Phase H)
* **Attachment & Avatar Paths:** When filesystem storage mode is enabled in vBulletin settings (`attachsave = 1` or `usefileavatar = 1`), physical file resolution must follow vBulletin's directory hashing logic (`attachpath`/`avatarpath`).

---

## Phase A Scope Boundary
Phase A implements exclusively the Provider contract, Configuration Detection, Version Detection, Read-Only Database Adapter, Preflight Checks, and Source Counts. No write operations or stage migrations are performed during Phase A.
