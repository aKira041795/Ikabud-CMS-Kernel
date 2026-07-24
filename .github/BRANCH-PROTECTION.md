# Branch Protection Rules — Ikabud

This document defines the required branch protection configuration for the `main` (and `master`) branches. These settings must be applied via GitHub repository settings by an administrator.

## Required status checks (must pass before merge)

| Check | CI job name | Rationale |
|-------|------------|-----------|
| **MySQL 8.0 test suite** | `test (mysql-8)` | Primary development target |
| **MySQL 5.7 test suite** | `test (mysql-5.7)` | Bluehost production target |
| **MariaDB 10.6 test suite** | `test (mariadb-10.6)` | Alternative production target |
| **PHPStan static analysis** | `static-analysis` | Type safety and dead code detection |
| **PHP-CS-Fixer coding standards** | `coding-standards` | Style consistency |
| **Architecture boundary check** | `architecture:check` (runs inside test job) | Module boundary enforcement |
| **DiSyL template lint** | `_lint_disyl.php --ci` (runs inside test job) | Template correctness |

## GitHub Settings configuration

Navigate to: Repository → Settings → Branches → Add classic branch protection rule

### Branch name pattern
```
main
```

### Protection settings

| Setting | Value | Reason |
|---------|-------|--------|
| **Require a pull request before merging** | ✅ Enabled | All changes go through PR review, even for the main architect |
| **Require approvals** | 1 | Solo maintainer — self-approval is acceptable with CI gate |
| **Dismiss stale pull request approvals when new commits are pushed** | ✅ Enabled | New commits invalidate prior approval |
| **Require status checks to pass before merging** | ✅ Enabled | CI must be green |
| **Require branches to be up to date before merging** | ✅ Enabled | Prevents merge skew |
| **Status checks that are required** | `test (mysql-8)`, `test (mysql-5.7)`, `test (mariadb-10.6)`, `static-analysis`, `coding-standards` | Listed above |
| **Require conversation resolution before merging** | ✅ Enabled | All review threads resolved |
| **Do not allow bypassing the above settings** | ✅ Enabled | Including administrators |
| **Allow force pushes** | ❌ Disabled | Protect history |
| **Allow deletions** | ❌ Disabled | Protect history |

> **Note**: `static-analysis` and `coding-standards` jobs use `continue-on-error: true` during initial rollout. Once the baseline is clean, remove `continue-on-error` and make them hard gates.

## Applying these settings

```bash
# Via GitHub CLI (if admin token available):
gh api repos/:owner/:repo/branches/main/protection \
  --method PUT \
  --input branch-protection.json
```

Otherwise, apply manually via the GitHub UI at:
`https://github.com/<owner>/<repo>/settings/branches`

## Future improvements

1. Once `static-analysis` baseline is clean → remove `continue-on-error: true` in CI
2. Once `coding-standards` baseline is clean → remove `continue-on-error: true` in CI
3. Add `release-manifest` as a required check once Phase 4 is complete
4. Consider requiring 2 approvals once team size > 1
