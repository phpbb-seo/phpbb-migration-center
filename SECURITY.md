# 🛡️ Security Policy

## Reporting Security Issues

The **phpBB SEO Team** takes the security of phpBB Migration Center seriously. If you discover a security vulnerability, please report it responsibly.

### How to Report
- **Email:** Send security vulnerability reports directly to `security@phpbbseo.com`.
- Please **do NOT** file public GitHub issues for undisclosed security vulnerabilities.
- Provide detailed steps to reproduce the vulnerability, including sample payloads or configurations where applicable.

### Security Guarantees & Scope
- **Source Database Safety:** Migration Center strictly maintains a **Read-Only Invariant** against the source forum database. It will never perform `INSERT`, `UPDATE`, `DELETE`, or `DROP` queries on the source database.
- **Credential Protection:** Migration Center does not store plain-text passwords. Supported password hashes are converted and preserved securely without exposing secret credentials.
- **Deserialization Safety:** Unserialization of legacy source authentication payloads strictly enforces `allowed_classes => false` to prevent PHP object injection vulnerabilities.
- **Founders & Administrative Safety:** The migration engine strictly protects existing target phpBB founders and anonymous users (`user_id = 1`) from privilege escalation or accidental deletion.

### Sensitive Data Warning
When submitting bug reports, screenshots, or logs in public issue trackers:
- **Never include database passwords, API keys, or secret tokens.**
- **Redact user email addresses, private messages, and personal identifiable information (PII).**
- **Sanitize absolute filesystem paths containing sensitive server usernames.**