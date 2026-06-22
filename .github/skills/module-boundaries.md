---
name: module-boundaries
description: Rules for cross-module access and kernel boundary discipline
applyTo: "**/*.php"
---

# Module Boundaries

## Kernel boundary
- Never bypass kernel contracts. Use hooks, events, capabilities instead of calling kernel classes directly from modules.
- Module DB → use `module()->db()` (or `aw_db()` for attendance-wage), never `app()->db()` for module-owned tables.
- Superadmin routes live in `public/index.php`, not in module route files.

## Tenant scoping
- All queries on tenant-owned tables must filter by `tenant_id`.
- Use `app()->tenant()->current()` or `app()->dbForTenant($tenantId)` for cross-tenant operations.
- Module settings per-tenant: `getModuleSettings('module-id')`, `readTenantModuleSettingsForTenant()`.

## Capability-based access
- Entity views resolve through capability bus (`entity.list.{entity}@{version}`).
- Handlers gate with `attendanceWageGuard('capability.id@1')`.
- Never directly access another module's tables — go through their capability handlers or API.

## Route ownership
- Module registers its own routes in `routes.php`.
- Route paths prefixed with module context (`/admin/wage/...`, `/admin/attendance/...`).
- Parameterized routes (`{id}`, `{name}`) must be ordered after literal routes (`/create` before `/{id}`).
