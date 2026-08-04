# Cms Akira Theme

Cms Akira Theme module for Ikabud Kernel

## Quick Start

```bash
# 1. Edit your schema
vim modules/cms-akira-theme/database/migrations/001_initial.sql

# 2. Run migrations
php ikabud migrate cms-akira-theme

# 3. Enable the module (if not already)
php ikabud module:enable cms-akira-theme

# 4. Visit the admin page
open /admin/cms-akira-theme
```

## Structure

| File | Purpose |
|------|---------|
| `module.json` | Module manifest — tables, capabilities, events, nav |
| `routes.php` | Route map: `'METHOD' => ['/path' => 'cms-akira-theme:handler']` |
| `handlers.php` | Route handler functions |
| `helpers.php` | Auto-loaded scoped helpers (catCtx, catDb, catRender) |
| `database/migrations/` | Numbered SQL migration files |

## Routes

| Method | Path | Handler |
|--------|------|---------|
| GET | `/admin/cms-akira-theme` | `pageCmsAkiraThemeHome` |

## Tables Owned

_(none yet — add tables to `database/migrations/001_initial.sql` and list them in `module.json` `owns_tables`)_

## Testing

```bash
php tests/cms_akira_theme_module_test.php
```

## Further Reading

- [Module Development Guide](../../docs/module-development-guide.md)
- [Module Quickstart Tutorial](../../docs/module-quickstart.md)
