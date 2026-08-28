## 📋 Summary of Changes
Provide a brief summary of the changes introduced by this pull request.

## 🎯 Type of Change
- [ ] Bug fix (non-breaking change which fixes an issue)
- [ ] New feature (non-breaking change which adds functionality)
- [ ] New source platform connector
- [ ] Documentation update / typo fix
- [ ] Test suite enhancement

## 🧪 Testing Performed
Describe how you verified these changes:
- [ ] Automated unit/integration tests passed (`php tests/runner.php`)
- [ ] PHP syntax lint check passed across supported versions
- [ ] Verified on local staging installation with test source dataset
- [ ] Verified both Browser AJAX and CLI worker modes (if applicable)
- [ ] Checked English LTR and Persian RTL UI rendering (if ACP styles modified)

## 🛡️ Safety & Architecture Checklist
- [ ] Preserves source read-only invariant (zero write operations to source database)
- [ ] Platform-specific logic is confined inside its connector directory (`source/<platform>/`)
- [ ] Shared migration engine (`core/`) logic is not duplicated
- [ ] No real personal user data, private keys, or passwords in test fixtures