# Cms Akira Ai

Cms Akira Ai module for Ikabud Kernel

## Quick Start

```bash
# 1. Edit your schema
vim modules/cms-akira/cms-akira-ai/database/migrations/001_initial.sql

# 2. Run migrations
php ikabud migrate cms-akira-ai

# 3. Enable the module (if not already)
php ikabud module:enable cms-akira-ai

# 4. Visit the admin page
open /admin/cms-akira-ai
```

## Structure

| File | Purpose |
|------|---------|
| `module.json` | Module manifest — tables, capabilities, events, nav |
| `routes.php` | Route map: `'METHOD' => ['/path' => 'cms-akira-ai:handler']` |
| `handlers.php` | Route handler functions |
| `helpers.php` | Auto-loaded scoped helpers (caaCtx, caaDb, caaRender) |
| `database/migrations/` | Numbered SQL migration files |

## Routes

| Method | Path | Handler |
|--------|------|---------|
| GET | `/admin/cms-akira-ai` | `pageCmsAkiraAiHome` |

## Tables Owned

_(none yet — add tables to `database/migrations/001_initial.sql` and list them in `module.json` `owns_tables`)_

## Testing

```bash
php tests/cms_akira_ai_module_test.php
```

## Further Reading

- [Module Development Guide](../../docs/module-development-guide.md)
- [Module Quickstart Tutorial](../../docs/module-quickstart.md)
