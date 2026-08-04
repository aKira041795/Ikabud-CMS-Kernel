# Cms Akira Profile Headless

Cms Akira Profile Headless module for Ikabud Kernel

## Quick Start

```bash
# 1. Edit your schema
vim modules/cms-akira-profile-headless/database/migrations/001_initial.sql

# 2. Run migrations
php ikabud migrate cms-akira-profile-headless

# 3. Enable the module (if not already)
php ikabud module:enable cms-akira-profile-headless

# 4. Visit the admin page
open /admin/cms-akira-profile-headless
```

## Structure

| File | Purpose |
|------|---------|
| `module.json` | Module manifest — tables, capabilities, events, nav |
| `routes.php` | Route map: `'METHOD' => ['/path' => 'cms-akira-profile-headless:handler']` |
| `handlers.php` | Route handler functions |
| `helpers.php` | Auto-loaded scoped helpers (caphCtx, caphDb, caphRender) |
| `database/migrations/` | Numbered SQL migration files |

## Routes

| Method | Path | Handler |
|--------|------|---------|
| GET | `/admin/cms-akira-profile-headless` | `pageCmsAkiraProfileHeadlessHome` |

## Tables Owned

_(none yet — add tables to `database/migrations/001_initial.sql` and list them in `module.json` `owns_tables`)_

## Testing

```bash
php tests/cms_akira_profile_headless_module_test.php
```

## Further Reading

- [Module Development Guide](../../docs/module-development-guide.md)
- [Module Quickstart Tutorial](../../docs/module-quickstart.md)
