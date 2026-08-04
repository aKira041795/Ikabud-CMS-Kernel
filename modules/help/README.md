# Help

Help module for Ikabud Kernel

## Quick Start

```bash
# 1. Edit your schema
vim modules/help/database/migrations/001_initial.sql

# 2. Run migrations
php ikabud migrate help

# 3. Enable the module (if not already)
php ikabud module:enable help

# 4. Visit the admin page
open /admin/help
```

## Structure

| File | Purpose |
|------|---------|
| `module.json` | Module manifest — tables, capabilities, events, nav |
| `routes.php` | Route map: `'METHOD' => ['/path' => 'help:handler']` |
| `handlers.php` | Route handler functions |
| `helpers.php` | Auto-loaded scoped helpers (hCtx, hDb, hRender) |
| `database/migrations/` | Numbered SQL migration files |

## Routes

| Method | Path | Handler |
|--------|------|---------|
| GET | `/admin/help` | `pageHelpHome` |

## Tables Owned

_(none yet — add tables to `database/migrations/001_initial.sql` and list them in `module.json` `owns_tables`)_

## Testing

```bash
php tests/help_module_test.php
```

## Further Reading

- [Module Development Guide](../../docs/module-development-guide.md)
- [Module Quickstart Tutorial](../../docs/module-quickstart.md)
