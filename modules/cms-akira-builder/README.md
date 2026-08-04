# Cms Akira Builder

Cms Akira Builder module for Ikabud Kernel

## Quick Start

```bash
# 1. Edit your schema
vim modules/cms-akira-builder/database/migrations/001_initial.sql

# 2. Run migrations
php ikabud migrate cms-akira-builder

# 3. Enable the module (if not already)
php ikabud module:enable cms-akira-builder

# 4. Visit the admin page
open /admin/cms-akira-builder
```

## Structure

| File | Purpose |
|------|---------|
| `module.json` | Module manifest — tables, capabilities, events, nav |
| `routes.php` | Route map: `'METHOD' => ['/path' => 'cms-akira-builder:handler']` |
| `handlers.php` | Route handler functions |
| `helpers.php` | Auto-loaded scoped helpers (cabCtx, cabDb, cabRender) |
| `database/migrations/` | Numbered SQL migration files |

## Routes

| Method | Path | Handler |
|--------|------|---------|
| GET | `/admin/cms-akira-builder` | `pageCmsAkiraBuilderHome` |

## Tables Owned

_(none yet — add tables to `database/migrations/001_initial.sql` and list them in `module.json` `owns_tables`)_

## Testing

```bash
php tests/cms_akira_builder_module_test.php
```

## Further Reading

- [Module Development Guide](../../docs/module-development-guide.md)
- [Module Quickstart Tutorial](../../docs/module-quickstart.md)
