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

## auth_owned modules — tenant entry module selection

**If your module has an `auth_owned` block in module.json (or `auth_cookie`), it automatically becomes selectable as a tenant entry module during tenant creation.** No extra registration or config needed — the kernel dynamically discovers all modules with `auth_owned`/`auth_cookie` at `src/http/tenant-entry-modules.php:43` and populates the dropdown.

However, for it to actually be accepted at tenant creation time, it must also be:
1. **Globally enabled** — check via superadmin module settings
2. **Loadable** — all capability dependency checks pass (`getEnabledModules()` in module-manager.php)
3. **Not a service-module** — `type !== 'service-module'`

To verify a new auth_owned module appears in the dropdown:
1. Add the module directory under `modules/` with a valid `module.json` containing `auth_owned`
2. Run module discovery: `php ikabud module:discover` (or restart PHP-FPM)
3. Open the Create Tenant modal in superadmin — the module should appear in the Entry Module `<select>`
4. If it doesn't appear, check `listTenantEntryModuleOptions()` in `src/http/tenant-entry-modules.php`:
   - The module must pass all three guards (not service-module, has auth_owned or auth_cookie)
   - If `loadable === false`, it renders as disabled with "[not loadable]" label — check dependency resolution

## Key patterns
- Use `module()->db()` for module-owned DB access
- Guard handlers with capability checks
- Register routes in `routes.php`, not in handlers
- Keep rendering behind DiSyL/kernel render contracts
- Add or update tests when adding capabilities or changing behavior
- **Capability handlers**: Name the export function `{module_prefix}_capability_handlers()` where `module_prefix` is the module ID with hyphens replaced by underscores. The function must return an array mapping capability IDs to callable function names. See `src/helpers/module-routes.php` for the naming convention.
- **Auth-owned modules using shared templates**: All auth POST endpoints MUST use `/api/v1/{module-id}/auth/*` routes, accept JSON input (`php://input` + `json_decode`), and return JSON responses (`echo json_encode(...)`). See `.github/skills/auth-module-setup.md` for the full pattern and debug checklist.
- **CSRF**: The module manager globally enforces CSRF on all non-API POST routes. The shared login template (`pages/login.disyl`) is exempted via `$isModuleLogin` check, but forgot-password and reset-password templates are NOT — they require API routes.
- **Password reset flow**: Generate `bin2hex(random_bytes(32))` token, store `hash('sha256', $token)` in the password resets table, send email with raw token URL using `sendEmail()` and `buildEmailTemplate()` from `src/helpers/email.php`.
- **Test every migration**: Run `php -l` on all PHP files, then `php ikabud tenant:migrate {tenant} {module}` to validate SQL syntax.
