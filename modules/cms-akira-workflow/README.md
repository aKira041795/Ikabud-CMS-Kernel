# Cms Akira Workflow

Cms Akira Workflow module for Ikabud Kernel

## Quick Start

```bash
# 1. Edit your schema
vim modules/cms-akira-workflow/database/migrations/001_initial.sql

# 2. Run migrations
php ikabud migrate cms-akira-workflow

# 3. Enable the module (if not already)
php ikabud module:enable cms-akira-workflow

# 4. Visit the admin page
open /admin/cms-akira-workflow
```

## Structure

| File | Purpose |
|------|---------|
| `module.json` | Module manifest — tables, capabilities, events, nav |
| `routes.php` | Route map: `'METHOD' => ['/path' => 'cms-akira-workflow:handler']` |
| `handlers.php` | Route handler functions |
| `helpers.php` | Auto-loaded scoped helpers (cawCtx, cawDb, cawRender) |
| `database/migrations/` | Numbered SQL migration files |

## Routes

| Method | Path | Handler |
|--------|------|---------|
| GET | `/admin/cms-akira-workflow` | `pageCmsAkiraWorkflowHome` |

## Tables Owned

_(none yet — add tables to `database/migrations/001_initial.sql` and list them in `module.json` `owns_tables`)_

## Testing

```bash
php tests/cms_akira_workflow_module_test.php
```

## Further Reading

- [Module Development Guide](../../docs/module-development-guide.md)
- [Module Quickstart Tutorial](../../docs/module-quickstart.md)
