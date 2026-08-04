# CMS-Adjacent Module Blueprint

## Goal
Provide a repeatable, manifest-first blueprint for new CMS-adjacent modules that align with Ikabud architecture and tenancy constraints.

## Phase 1: Contract Before Code
1. Create module.json with id, name, version, description, author.
2. Declare owns_tables, reads_tables, migrations, and seeds explicitly.
3. Declare capabilities.exposes and capabilities.depends using versioned contract ids.
4. Declare settings/settings_fields defaults if tenant-scoped settings are needed.
5. If auth-owned, declare auth_owned including id_column and role_column.

## Phase 2: Capability and Boundary Wiring
1. Keep routes.php declarative and concern-grouped.
2. Register capability handlers in helpers.php loader path.
3. Use capability calls/events/hooks for cross-module interactions.
4. Avoid direct imports of other modules' internals.
5. Keep dependency declarations narrow and tied to actual runtime calls.

## Phase 3: Rendering and Entity Model
1. Use entity views for standard list/detail surfaces.
2. Keep composite dashboards/pages in module templates with handler-fed aggregate data.
3. Do not move rendering concerns into kernel-level special cases.
4. Keep route ownership explicit to avoid collisions with CMS core routes.

## Phase 4: Tenant and Migration Safety
1. Ensure migration files are deterministic and MySQL 5.7 compatible.
2. Validate table declarations match real SQL usage.
3. Validate tenant provisioning/upgrade paths for fresh and existing tenants.
4. Validate auth-owned password push compatibility when applicable.
5. If the module is intended to be a tenant entry module or auth-owned admin surface, make that explicit in `module.json` and test the tenant provisioning flow.

## Tenant Dashboard Provisioning Rule

The Admin > Tenants page is not just a record list; it is the place where the platform chooses which module bundle is provisioned for a tenant.

If the module owns users/auth, treat it as a standalone tenant-entry module.

- The dropdown selects the tenant's `entry_module_id` and therefore controls `tenantProvisionModulePlan(entry_module_id)`.
- Auth-owned modules must declare `auth_owned` so the kernel can seed the initial admin user and perform password-push recovery.
- If a new module owns its own users table, the new tenant must be provisioned with that module bundle selected or the tenant will not receive the correct auth tables/users.
- If a module is not an entry module and is not auth-owned, it should not need tenant-dashboard selection unless it is intentionally part of the tenant's initial bundle.

This is a required provisioning step, not an optional UI preference. New module manifests must be designed with it in mind.

## Minimal Contract Skeleton
```json
{
  "id": "cms-ai-automation",
  "name": "CMS AI Automation",
  "version": "1.0.0",
  "type": "module",
  "depends": ["cms", "ai"],
  "routes": true,
  "owns_tables": ["cms_ai_jobs"],
  "reads_tables": ["cms_content"],
  "migrations": ["database/migrations/001_cms_ai_jobs.sql"],
  "capabilities": {
    "exposes": [
      { "id": "cms.ai.plan.run@1", "priority": 50, "modes": ["first"] }
    ],
    "depends": [
      "cms.content.get@1",
      "ai.text.generate@1"
    ]
  },
  "auth_owned": {
    "users_table": "cms_ai_users",
    "username_column": "username",
    "email_column": "email",
    "password_column": "password_hash",
    "name_column": "display_name",
    "active_column": "is_active",
    "admin_roles": ["administrator"],
    "default_admin_role": "administrator",
    "id_column": "user_id",
    "role_column": "role"
  }
}
```

## Validation Checklist
- Manifest schema validation passes (fatal diagnostics = 0).
- Architecture diagnostics show no certification blockers for new module manifests.
- Capability handlers are loaded from helpers.php and callable at module registration time.
- No undeclared table access in module DB paths.
- No dependency overreach through capabilities.depends on kernel.auth.authenticate@1.

## Focused Test Matrix
- Manifest validation tests (schema + architecture checks).
- Module load smoke test for callable capability handlers.
- Route smoke tests for representative admin/API/public endpoints.
- Tenant migration tests (fresh + upgrade).
- Auth flow tests only for auth-owned modules.
- Entity view integration tests for one list and one detail path.
