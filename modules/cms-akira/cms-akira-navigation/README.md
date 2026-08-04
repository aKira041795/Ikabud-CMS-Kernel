# Cms Akira Navigation

Cms Akira Navigation module for Ikabud Kernel

## Quick Start

```bash
# 1. Edit your schema
vim modules/cms-akira/cms-akira-navigation/database/migrations/001_initial.sql

# 2. Run migrations
php ikabud migrate cms-akira-navigation

# 3. Enable the module (if not already)
php ikabud module:enable cms-akira-navigation

# 4. Visit the admin page
open /admin/cms-akira-navigation
```

## Structure

| File | Purpose |
|------|---------|
| `module.json` | Module manifest — tables, capabilities, events, nav |
| `routes.php` | Route map: `'METHOD' => ['/path' => 'cms-akira-navigation:handler']` |
| `handlers.php` | Route handler functions |
| `helpers.php` | Auto-loaded scoped helpers (canCtx, canDb, canRender) |
| `database/migrations/` | Numbered SQL migration files |

## Routes

| Method | Path | Handler |
|--------|------|---------|
| GET | `/admin/cms-akira-navigation` | `pageCmsAkiraNavigationHome` |

## Tables Owned

_(none yet — add tables to `database/migrations/001_initial.sql` and list them in `module.json` `owns_tables`)_

## Testing

```bash
php tests/cms_akira_navigation_module_test.php
```

## Further Reading

- [Module Development Guide](../../docs/module-development-guide.md)
- [Module Quickstart Tutorial](../../docs/module-quickstart.md)
