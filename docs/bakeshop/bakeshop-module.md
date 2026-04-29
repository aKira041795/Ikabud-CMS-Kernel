# Bakeshop Module

**Module ID:** `bakeshop`
**Version:** 0.1.0
**Author:** Ikabud Kernel Team
**Depends on capabilities:** `kernel.audit.record@1`, `kernel.auth.user@1`
**Standalone:** yes — does not depend on any other application module.

---

## Overview

The Bakeshop module is a stand-alone supervisor workspace for bakery operations: branches, units, ingredients, products + recipes, deliveries, production runs, and per-branch ingredient usage reporting. It runs inside the Ikabud Kernel as a tenant-scoped module — all `bakeshop_*` tables live in the tenant's own database (no `tenant_id` columns).

It is **module-owned auth**: the module has its own `bakeshop_users` table, its own JWT cookie (`bakeshop_token`), and registers itself as a `kernel.auth.authenticate@1` provider. Bakeshop tenants are entry-module-scoped — when [tenantEntryModuleIdForTenant()](src/helpers/module-manager.php) resolves to `bakeshop`, the kernel home redirect lands authenticated users at `/admin/bakeshop`.

> The previous standalone reference app under `bakeshopapp/` has been removed. The current implementation lives entirely under [modules/bakeshop](modules/bakeshop) and [templates/modules/bakeshop](templates/modules/bakeshop).

---

## Manifest Summary

Source: [modules/bakeshop/module.json](modules/bakeshop/module.json)

| Field | Value |
|---|---|
| `auth_cookie` | `bakeshop_token` |
| `owns_tables` | `bakeshop_users`, `bakeshop_branches`, `bakeshop_units`, `bakeshop_ingredients`, `bakeshop_products`, `bakeshop_product_recipe`, `bakeshop_deliveries`, `bakeshop_delivery_items`, `bakeshop_production_runs`, `bakeshop_production_items` |
| `reads_tables` | `bakeshop_ingredient_usage` (view), `audit_logs` |
| `events` | `bakeshop.{branch,delivery,production,product,ingredient}.created`, `bakeshop.recipe.saved` |
| `settings_fields` | `usage_decimal_places`, `print_template`, `role_permissions` |

### Capabilities

Exposed (see [modules/bakeshop/helpers.php](modules/bakeshop/helpers.php) `bakeshop_capability_handlers()`):

| Capability | Mode | Purpose |
|---|---|---|
| `kernel.auth.authenticate@1` | `pipeline` (priority 560) | Authenticates `@bakeshop:<username>` and bare module credentials. |
| `bakeshop.read@1` | `first` | Tenant-scoped read gate for bakeshop data. |
| `bakeshop.manage@1` | `first` | Tenant-scoped write/manage gate. |
| `bakeshop.product.read@1` | `first` | Read access to product catalog. |
| `bakeshop.ingredient.usage.read@1` | `first` | Read access to the `bakeshop_ingredient_usage` view. |

Capability policy restricts `kernel.auth.authenticate@1` callers to `bakeshop` and `kernel`.

---

## Database Architecture

Schema is shipped as five migrations under [modules/bakeshop/database/migrations/](modules/bakeshop/database/migrations) and applied per-tenant by `syncTenantMigrationsForCurrentRequest()`:

| Migration | Purpose |
|---|---|
| `001_bakeshop_core.sql` | Branches, units, ingredients, products, recipes, deliveries, production runs/items + seeds default units (`kg`, `g`, `L`, `mL`, `pc`, `pack`) and the `bakeshop_ingredient_usage` view. |
| `002_bakeshop_delivery_source.sql` | Adds delivery-source metadata to deliveries. |
| `002_bakeshop_users.sql` | Creates `bakeshop_users` and conditionally seeds from `cms_users` if present. Falls back to a placeholder bootstrap row guarded by a non-usable password hash marker (`!bakeshop-bootstrap-password-reset-required!`). |
| `003_bakeshop_bootstrap_admin.sql` | Inserts the `bakeshopadmin` placeholder when no admin exists. |
| `004_bakeshop_user_token_version.sql` | Adds `token_version` to `bakeshop_users` for invalidation on password change (uses `DO 0` / `SELECT 1`-free no-op pattern). |
| `005_bakeshop_bootstrap_password_reset.sql` | Forces the legacy bootstrap password to the reset marker so the public login path can never accept it. |

**Important:** there is **no public bootstrap-password setup page**. The placeholder password hash (`!bakeshop-bootstrap-password-reset-required!`) cannot match any input — the only way to obtain a usable first admin is the trusted provisioning path (CLI or admin recovery).

---

## Routes

Source: [modules/bakeshop/routes.php](modules/bakeshop/routes.php)

### Pages
- `GET /bakeshop/login`, `GET /bakeshop/forgot-password`, `GET /bakeshop/reset-password`
- `GET /bakeshop`, `GET /admin/bakeshop` (supervisor home)
- `GET /admin/bakeshop/{branches,catalog,ingredients,deliveries,production,usage,history,users,account,settings}`
- `GET /admin/bakeshop/print`, `GET /bakeshop/print` (print summary)

### API (JSON)
- Auth: `POST /bakeshop/auth/login`, `POST /api/v1/bakeshop/auth/login`, `POST /api/v1/bakeshop/auth/forgot-password`, `POST /api/v1/bakeshop/auth/reset-password`, `POST /bakeshop/logout`
- Account: `POST /api/v1/bakeshop/account/password`
- Users: `GET|POST /api/v1/bakeshop/users`, `POST /api/v1/bakeshop/users/{id}`, `POST /api/v1/bakeshop/users/{id}/delete`
- Settings: `POST /api/v1/bakeshop/settings/permissions`, `POST /api/v1/bakeshop/settings/display`
- Domain CRUD: branches, products, ingredients, recipes, deliveries, production runs (index + store + status/delete)
- Usage report: `GET /api/v1/bakeshop/usage`
- Health: `GET /api/v1/bakeshop/health`

All admin handlers go through `bakeshopResponseGuard()` for consistent 403/422/500 JSON responses and CSRF enforcement via `bakeshopEnforceCsrf()`.

---

## Authentication & Authorization

### Provider

`bakeshop_cap_kernel_auth_authenticate_1()` (in [modules/bakeshop/helpers.php](modules/bakeshop/helpers.php)) is the canonical provider:

- Accepts `username = "@bakeshop:<username-or-email>"` (preferred) or bare module credentials.
- Looks up `bakeshop_users` by username **or** email, verifies `password_hash` with `password_verify`, and rejects users whose password equals the legacy bootstrap hash or the reset marker.
- Returns an identity payload with `id`, `username`, `email`, `full_name`, `role`, `source = 'bakeshop'`, and `token_version`.

`bakeshopAuthLogin()` issues a JWT into the `bakeshop_token` cookie (HttpOnly, `SameSite=Strict`, `Secure` when HTTPS) and rotates the CSRF token on success. Token version is embedded in the JWT and re-checked against `bakeshop_users.token_version` so a forced password change invalidates outstanding tokens.

A module-local rate limiter (`bakeshopLoginRateLimitState()`) reuses the kernel `rate_limits` table with the `bakeshop:login` action key.

### Self-service password reset

`bakeshopForgotPasswordPage()`, `bakeshopResetPasswordPage()`, `bakeshopApiForgotPassword()`, and `bakeshopApiResetPassword()` live in [modules/bakeshop/handlers/05-auth.php](../../modules/bakeshop/handlers/05-auth.php). They follow the current auth-owned module contract:

- public guest pages at `/bakeshop/forgot-password` and `/bakeshop/reset-password`
- canonical browser APIs at `/api/v1/bakeshop/auth/forgot-password` and `/api/v1/bakeshop/auth/reset-password`
- generic success messaging to avoid account enumeration
- 60-minute reset-link expiry and rate limiting on both issue and reset attempts
- page-render token validation so expired links show an inline recovery path instead of a broken form

Trusted admin recovery remains the kernel-level password-push endpoint documented below; self-service reset is for end users, while tenant admin recovery is still driven from the kernel admin surface.

### Authorization

- `bakeshopCurrentUser($permission, $roles)` requires module auth and gates by role + permission map.
- Roles: `admin`, `supervisor`. Default role permissions are returned by `bakeshopDefaultRolePermissions()` and can be customized via the `role_permissions` setting (JSON map, validated against `bakeshopPermissionActions()`).
- Kernel `superadmin` users (`source === 'kernel'`) bypass the module-user check via `bakeshopIsKernelSuperadmin()` for support flows.

---

## Trusted Provisioning

The bakeshop bootstrap admin **must** be seeded through the trusted provisioning pipeline. The kernel CLI guards this:

```bash
php ikabud tenant:provision <tenant_id> \
  --admin-user=<username> \
  --admin-pass=<plain_password> \
  --admin-name="<Display Name>"
```

When the tenant's entry module resolves to `bakeshop`, [TenantProvisioner](kernel/Services/TenantProvisioner.php) refuses to proceed without `--admin-user` and `--admin-pass`, then inserts the named admin into `bakeshop_users` (replacing the placeholder bootstrap row). The operation is **idempotent** — re-running is safe and does not duplicate the admin.

Behavior is covered by:
- [tests/bakeshop_tenant_provision_behavior_test.php](tests/bakeshop_tenant_provision_behavior_test.php) (33 assertions: service + CLI fail-fast, happy path, idempotency)
- [tests/bakeshop_tenant_provision_contract_test.php](tests/bakeshop_tenant_provision_contract_test.php) (10 assertions: contract surface)

---

## Trusted Recovery (Admin Password Push)

For lost-password recovery, use the kernel-level admin endpoint handled by `kernelHandleApiTenantAdminPasswordPush()` in [src/http/admin-handlers.php](src/http/admin-handlers.php). On a tenant whose entry module is `bakeshop`, the handler updates `bakeshop_users.password_hash` (and bumps `token_version` semantics through the auth provider) and includes `bakeshop_users` in the `pushed` response.

The pushed password is **immediately usable** through `bakeshop_cap_kernel_auth_authenticate_1()` — no additional reset step is required. The previous password is rejected on the next authentication attempt.

Behavior is covered by [tests/bakeshop_tenant_admin_password_push_behavior_test.php](tests/bakeshop_tenant_admin_password_push_behavior_test.php) (18 assertions, including auth-provider acceptance of the new password and rejection of the old one for the affected tenant).

---

## Settings

| Key | Type | Default | Notes |
|---|---|---|---|
| `usage_decimal_places` | number | `2` | Decimal places for the ingredient usage summary and printable reports. |
| `print_template` | select | `standard` | Printable summary layout. |
| `role_permissions` | textarea (JSON) | `""` | Optional role → permissions map. Allowed permissions: `bakeshop.read`, `bakeshop.manage`. `admin` is always granted both. |

Settings are read via `getModuleSettings('bakeshop')` and written via `saveModuleSettings('bakeshop', ...)`. Updates audit through `bakeshopAudit()`.

---

## Handlers Layout

[modules/bakeshop/handlers/](modules/bakeshop/handlers) is split for clarity and load order:

| File | Responsibility |
|---|---|
| `00-bootstrap.php` | Response/CSRF guards, permission catalog, default role permissions, `bakeshopCurrentUser()`, table whitelist for read helpers. |
| `05-auth.php` | Login rate limit, login page, login/logout endpoints, JWT issuance. |
| `10-pages.php` | Disyl page renderers for the supervisor workspace, branches, catalog, ingredients, deliveries, production, usage, history, users, account, settings, print summary. |
| `15-api-settings.php` | Permissions + display settings save endpoints. |
| `20-api-products-recipe.php` | Products and recipes CRUD. |
| `30-api-deliveries.php` | Deliveries + delivery items CRUD. |
| `40-api-production.php` | Production runs + items CRUD. |
| `50-api-usage-report.php` | Per-branch ingredient usage report read API. |
| `60-users.php` | User management (list/create/update/delete) and account password update. |

---

## Templates

Disyl templates under [templates/modules/bakeshop/](templates/modules/bakeshop):

- `layouts/app.disyl`, `layouts/print.disyl` — application + print layouts.
- `pages/supervisor.disyl`, `account.disyl`, `users.disyl`, `settings.disyl`, `history.disyl`, `print-summary.disyl`.

Login uses the kernel-shared `pages/login.disyl` skin with `bakeshopLoginPageContext()` overrides.

---

## Test Coverage

Targeted integration-style tests in [tests/](tests):

| Test | Focus |
|---|---|
| `bakeshop_module_test.php` | End-to-end module surface (124 assertions). |
| `bakeshop_tenant_provision_behavior_test.php` | Provisioning service + CLI behavior, idempotency. |
| `bakeshop_tenant_provision_contract_test.php` | Provisioning contract surface. |
| `bakeshop_tenant_admin_password_push_behavior_test.php` | Admin recovery → bakeshop auth provider. |
| `bakeshop_admin_mutation_test.php`, `bakeshop_recipe_mutation_test.php` | Mutation guards and validation. |
| `bakeshop_history_*_test.php` | History page, permissions, delete. |
| `bakeshop_user_management_test.php`, `bakeshop_permissions_test.php`, `bakeshop_permission_denial_test.php`, `bakeshop_role_permissions_save_test.php` | User + role permission flows. |
| `bakeshop_supervisor_settings_panel_test.php`, `bakeshop_display_settings_save_test.php` | Settings panel rendering and persistence. |
| `bakeshop_print_summary_test.php`, `bakeshop_usage_integration_test.php` | Print summary + usage view. |
| `bakeshop_julies_seed_sql_test.php`, `bakeshop_julies_fixture_import_test.php` | Reference fixture import. |

Run individually with `php tests/<file>.php`. All bakeshop tests should be green before shipping any change touching auth, provisioning, recovery, or schema.

---

## Designed as an ERP Foundation

The narrow current surface (branches, ingredients, products + recipes, deliveries, production, usage report) is a **deliberate seed**, not the finished form. The schema and module conventions are shaped so a fuller ERP can be grown additively without reshaping what already exists:

- **Movement-shaped data.** `bakeshop_deliveries` (stock in) and `bakeshop_production_runs` (stock used) are reconciled by the `bakeshop_ingredient_usage` view. New movement classes (waste, transfer, return, sales) plug into the same view pattern.
- **Units are ERP-grade from day one.** `bakeshop_units` carries `dimension`, `base_unit_id`, and `factor_to_base`, so recipes, deliveries, and reports never need ad-hoc conversion code.
- **Recipes are a real BOM.** `bakeshop_product_recipe` is structurally a Bill of Materials; layering costing on `bakeshop_delivery_items.unit_cost` yields COGS per produced unit.
- **Branches are a multi-location stub.** `bakeshop_branches.external_store_id` and `external_warehouse_id` are explicit bridge points to ecommerce stores and WMS warehouses.
- **Capability + role gates scale.** New ERP surfaces (purchasing, AR/AP, payroll, HR) should be added as new capabilities (e.g. `bakeshop.purchasing.manage@1`) and granted through the existing `role_permissions` JSON map.
- **Tenant-DB-clean.** Every new ERP table lives in the tenant DB with no cross-tenant fields. Schema growth is local; deployment scaling is orthogonal.
- **Audit + events ready.** `bakeshopAudit()` and the manifest `events[]` provide the traceability and trigger spine without retrofitting.

Natural growth path:

| Direction | Fits Existing Schema By |
|---|---|
| Purchasing | `bakeshop_purchase_orders` feeding `bakeshop_deliveries` (delivery becomes "PO receipt"). |
| Costing / Finance | `bakeshop_delivery_items.unit_cost` + recipe rollups → `bakeshop_product_costs` view → GL postings. |
| Sales / POS | New movement source feeding the usage view alongside production for true variance. |
| Stock authority handoff | `bakeshop_branches.external_warehouse_id` lets WMS take over live stock, mirroring the `wms_authoritative_products` pattern already used with ecommerce. |
| HR / Payroll | `bakeshop_users` already provides the auth substrate; add `bakeshop_shifts`, `bakeshop_timeclock`, capability `bakeshop.hr.manage@1`. |

Treat the current `0.1.0` surface as the smallest coherent slice that proves the foundation works (units, BOM, movements, multi-branch, audit, capabilities). Higher ERP layers are additive modules or additive tables — not refactors.

---

## Operational Contracts (Don't Break)

1. **No public bootstrap-password setup.** The placeholder hash `!bakeshop-bootstrap-password-reset-required!` and the legacy hardcoded hash must always be rejected by `bakeshop_cap_kernel_auth_authenticate_1()`.
2. **Provisioning requires a named admin** for bakeshop entry tenants — both via the `TenantProvisioner` service and the `php ikabud tenant:provision` CLI. This must remain idempotent.
3. **Admin password push must immediately authenticate** via the bakeshop auth provider for the affected tenant; the old password must stop working on the next attempt.
4. **Migrations must stay fresh-tenant safe** — the conditional seed in `002_bakeshop_users.sql` references `cms_users` only when it exists, and the no-op branch uses `DO 0` (not `SELECT 1`) to avoid the migration runner's result-set guard.
5. **Module-owned auth cookie** (`bakeshop_token`) and module identity (`source = 'bakeshop'`) are stable contracts — kernel home redirect, capability gates, and tests all rely on them.

> These five contracts are now expressed declaratively in `modules/bakeshop/module.json` under the `auth_owned` block (users_table, password_column, admin_roles, blocked_password_hashes, requires_named_admin_on_provision). The kernel discovers them through `kernelAuthOwnedModules()` in [src/helpers/module-manager.php](../../src/helpers/module-manager.php) and applies the same provisioning + admin-password-push behavior to **every** auth-owning module (currently `bakeshop`, `wms`, `daily-ledger`, `guidance`, `users`). To onboard a new module-owned auth surface, add an `auth_owned` block to its manifest — no kernel code changes required.
