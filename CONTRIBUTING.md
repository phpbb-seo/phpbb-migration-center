# 🤝 Contributing to phpBB Migration Center

We welcome contributions from the community! Whether you are submitting a bug fix, improving documentation, testing with different forum schemas, or building a new source platform connector, your help is appreciated.

---

## 🧭 Development Guidelines

### 1. Architectural Separation
- **Shared Core (`core/`):** Contains the engine, worker lifecycle, cursor persistence, lock management, rollback coordinator, state manager, and verification suite. Do not introduce platform-specific code into `core/`.
- **Source Connectors (`source/<platform>/`):** Platform-specific queries, DTO normalizers, version adapters, BBCode converters, and password handlers must remain isolated within their connector directory.

### 2. Source Read-Only Invariant
- All source database operations must be **strictly read-only** (`SELECT`). Never introduce mutating queries against the source database.

### 3. Keyset Pagination & Bounded Memory
- Migration steps must use primary key cursor pagination (`WHERE id > :cursor ORDER BY id ASC LIMIT :batch_size`) instead of SQL `OFFSET`.
- Ensure memory is freed after each batch so memory consumption remains constant regardless of dataset size.

---

## 🧪 Testing Your Changes

Before submitting a Pull Request:
1. Run the automated test runner:
   ```bash
   php tests/runner.php
   ```
2. Verify PHP syntax across supported PHP versions:
   ```bash
   find . -type f -name "*.php" -not -path "./tests/tmp/*" -not -path "./scratch/*" -exec php -l {} +
   ```
3. Ensure no real user credentials or production database dumps are added to the repository test fixtures.

---

## 📜 Pull Request Process

1. Fork the repository and create your branch from `main`.
2. Follow phpBB coding guidelines and maintain clear, descriptive commit messages.
3. Complete the pull request template checklist when opening your PR.
4. Keep PRs focused on a single feature, connector, or bug fix.

Thank you for helping build a better migration experience for the phpBB community!