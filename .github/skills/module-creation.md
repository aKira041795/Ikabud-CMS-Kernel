---
name: module-creation
description: Always check the module development guide and quickstart before creating a new module
applyTo: "**/module.json"
---

# Module Creation

## First read these guides
Before creating any new module, read both:
1. `docs/kernel/module-development-guide.md` — comprehensive reference
2. `docs/kernel/module-quickstart.md` — 30-minute tutorial

These are the authoritative sources. The skills below summarize key points but the guides may have been updated.

## Module scaffold checklist
- `modules/{module-id}/module.json` — manifest (owns_tables, capabilities, migrations, nav)
- `modules/{module-id}/routes.php` — GET/POST route maps
- `modules/{module-id}/handlers.php` — loads handler files, capability registrations
- `modules/{module-id}/helpers.php` — shared functions, capability handlers
- `modules/{module-id}/database/migrations/` — SQL migration files
- `templates/modules/{module-id}/` — DiSyL templates (mirror module path)

## module.json essentials
- `id`: kebab-case module identifier
- `owns_tables`: all tables this module creates/manages
- `reads_tables`: tables from other modules this module needs to read
- `capabilities`: array of `{id, handler, ...}` — what this module can do
- `migrations`: ordered list of migration files (each must be registered here)
- `nav`: sidebar navigation entries
- `depends`: other modules this depends on
- `settings_fields`: if module has configurable settings

## Key patterns
- Use `module()->db()` for module-owned DB access
- Guard handlers with capability checks: `attendanceWageGuard('capability.id@1')`
- Register routes in `routes.php`, not in handlers
- Keep rendering behind DiSyL/kernel render contracts
- Add or update tests when adding capabilities or changing behavior
