# Contributing to Ikabud

## Setup

### Prerequisites
- **PHP 8.2+** — Runtime
- **MySQL 5.7+** — Database (production target: Bluehost MySQL 5.7)
- **Apache with mod_rewrite** — Browser routing
- **Composer** — Dependency management
- **Node.js and npm** — For builder UI work (`modules/cms/builder-ui`)

### Local Development
```bash
git clone <repo-url>
cd ikabud
composer install
cp .env.example .env   # Configure database credentials
```

For builder UI work:
```bash
cd modules/cms/builder-ui
npm install
npm run dev           # Local builder UI development
```

## Running Tests

### Full test suite
```bash
composer test
```
Runs every `tests/*_test.php` file in a subprocess via `scripts/run-tests.php`.

### Single test file
```bash
php tests/<suite-name>_test.php
```

### Useful focused test suites
| Test | When to run |
|------|-------------|
| `php tests/request_dispatch_integration_test.php` | After routing or dispatch changes |
| `php tests/manifest_settings_defaults_test.php` | After module.json changes |
| `php tests/module_catalog_entitlement_test.php` | After module enable/disable changes |
| `php tests/kernel_pdo_context_injection_test.php` | After KernelPDO changes |
| `php tests/kernel_pdo_escalation_test.php` | After escalation API changes |
| `php tests/kernel_pdo_module_isolation_test.php` | After ModuleDB access enforcement changes |

### Browser tests (Playwright)
```bash
npx playwright test tests/browser/workbench/
```

## Log Checking

**Always check both logs** after reproducing bugs or running tests:

- `storage/logs/app.log` — Application-level logs (capability calls, warnings, info)
- `storage/logs/error.log` — PHP errors, crashes, stack traces

Use request IDs (`X-Request-Id`) to correlate failures across logs.

## PR Checklist

Before submitting a pull request, verify:

- [ ] `php -l` on all touched PHP files — no syntax errors
- [ ] Relevant tests pass (run `php tests/<suite>.php`)
- [ ] Both `storage/logs/app.log` and `storage/logs/error.log` checked for unexpected entries
- [ ] No MySQL 8.0+ features used (no window functions, CTEs, `JSON_TABLE()`, `CHECK` constraints). See [MySQL 5.7 compatibility](#mysql-57-compatibility) below.
- [ ] Migration `CREATE TABLE` statements include `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`
- [ ] Foreign key column types match referenced column types exactly
- [ ] Documentation updated for any new patterns, settings, or behaviors

## MySQL 5.7 Compatibility

Production runs on **MySQL 5.7** (Bluehost). The following are **forbidden**:
- Window functions (`OVER()`)
- Common Table Expressions (`WITH ... AS (...)`)
- `JSON_TABLE()`
- `CHECK` constraints
- `EXCEPT` / `INTERSECT` set operators

Use `SELECT COUNT(*)`, derived tables, app-level validation, or `NOT EXISTS` equivalents instead.

Tagged rules are prefixed with `@mysql57-compat:` in `.github/copilot-instructions.md` for future grep-ability.

## Codebase Navigation

See `docs/kernel/contributor-workflows.md` for the recommended reading order and key concepts.

## Architecture References

- `docs/kernel/ARCHITECTURE.md` — System architecture, request lifecycle, timing breakdown
- `docs/kernel/module-development-guide.md` — Module manifest schema, capability contracts
- `docs/kernel/kernel-stable-contracts.md` — Stable extension points vs internal implementation details
- `docs/kernel/kernel-os-disyl-roadmap-status.md` — Kernel OS + DiSyL roadmap status
