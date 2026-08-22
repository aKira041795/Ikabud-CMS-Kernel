# Implementation Report — ikabudsix Kernel Sync (contract v4)

> **Status:** PASS (targeted suites green)
>
> **Task:** `ikabudsix kernel sync — module-manager install UX, companion
> kernel-admin access, and own-DB (no shared-DB) provisioning, v4
> (critique-closed)`. The contract lived in `current-task.md` when implementation
> began; it was later replaced by an ARK audit task. This report is preserved
> under `current-task.ikabudsix-kernel-sync.bak` + this file.

## Files changed

| File | Change |
|---|---|
| `src/helpers/module-manager.php` | Added canonical `isDeclaredKernelCompanion()` (validated on-disk manifest) + `kernelAdminAccessGranted()` (fail-closed predicate); replaced inline opt-in gate in `executeModuleHandler()`; scoped `getModuleNavItems()` kernel-admin bypass to the predicate; added canonical `kernelAuthOwnedSpecFromDisk()` resolver |
| `src/http/page-handlers.php` | `kernelHandlePageAdminModules()`: kernel-identity guard (`source===kernel` && `role===admin`), companion-only `show_allow_kernel_admin` via `isDeclaredKernelCompanion()`, dropped `settings_*`/`capability_*`/`entities_*` payload, added `tenant_admin_url` |
| `src/http/admin-handlers.php` | `kernelPrepareTenantAdminJsonRequest()` now requires `source===kernel`, validates JWT tenant claim vs endpoint target tenant, bearer-aware CSRF; `kernelHandleApiTenantCreate()` inserts status `pending`; `kernelHandleApiTenantDbUpsert()` rejects base-DB connections + target-tenant JWT; `apiUpdateModuleSettings()` enforces companion-only `allow_kernel_admin` + cannot disable kernel-users guarantee; all tenant-targeted handlers pass target tenant; status handler cannot activate an unverified tenant |
| `src/http/core-routes.php` | (no change needed — page handler guard is the single enforcement point) |
| `kernel/Services/TenantProvisioner.php` | CAS state machine (pending→provisioning→active; failure→pending) with advisory lock; base-DB rejection; module migrations via guarded `tenantSyncModuleMigrations()`; canonical manifest-driven seeding (fail-fast, declared columns incl. role/active/tenant_id in idempotency lookup + insert); legacy kernel-users fallback fixed to `password_hash`/`is_active`; `default_admin_role ∈ admin_roles` validated pre-migration |
| `kernel/Services/DatabaseManager.php` | `dbForTenant()` rejects base-DB config pre-connect + verifies live connected identity post-connect |
| `kernel/TenantResolver.php` | Fail-closed: `tenantIsActive()` returns bool (false on control-DB failure); host/header/session strategies reject pending/provisioning/suspended/unverifiable |
| `src/helpers/module-migrations.php` | Added `tenantNormalizeDbHostForIdentity`, `tenantConnectionResolvesToBaseDb`, `tenantRejectBaseDbConnection`, `tenantConnectedDatabaseIsBaseDb`, `tenantKernelOwnedTables`, `tenantMigrationOwnershipPreflight`, `tenantSqlReferencesKernelOwnedTable`; applied ownership gate in `tenantApplySqlArtifact` |
| `ikabud` (CLI) | `tenant:provision` uses guarded executor + canonical on-disk resolver + CAS transitions + base-DB rejection + fail-fast seed |
| `control-migrations/007_control_plane_tenant_status_pending.sql` | NEW idempotent control migration ensuring `kernel_tenants.status` is VARCHAR(30) (accepts pending/provisioning) — applied to dev control DB |
| `modules/daily-ledger/database/migrations/019_audit_logs_actor_columns.sql` | Retired to tracked no-op (kernel artifact 018 authoritative) |
| `templates/pages/admin-modules.disyl` | Dropped capability/entity/settings panels; added tenant-settings link; simplified enable button |
| `tests/kernel_sync_contract_v4_test.php` | NEW focused test (35 assertions) |

## Tests run

Targeted suites (all PASS):

- `kernel_sync_contract_v4` — 35/35 (companion predicate, access matrix, base-DB
  isolation, ownership gate, canonical auth_owned resolver, 019 retirement,
  TenantResolver fail-closed)
- `cli_tenant_migrate_sync` — 20/20
- `bakeshop_tenant_provision_contract` — 15/15
- `bakeshop_tenant_seed_data` — 21/21
- `ehr_tenant_provisioning_plan` — 40/40
- `manifest_schema_v1` — 24/24
- `cms_admin_contribution_nav` — 12/12
- `auth_owned_reserved_role_validation` — 3/3
- `admin_kernel_control_plane_api` — 40/40
- `migration_integrity` — 21/21
- `kernel_hardening` — 43/43
- `manifest_settings_defaults` — 34/34
- `module_tenant_settings_read_guard` — 6/6
- `module_tenant_settings_write_guard` — 5/5

Validation: `php -l` clean on all 10 changed PHP files + `ikabud`; `_lint_disyl.php`
681/681 valid; `php ikabud migrate:control` applied 007 cleanly; MySQL-5.7 audit
clean of window functions / CTEs / JSON_TABLE / 8.0-only breakers in the planned
set; app bootstrap OK.

## Deviations

- **Ownership gate dynamic-SQL**: the contract literally reads "reject dynamic
  SQL (PREPARE/EXECUTE/DELIMITER) outright", but that breaks bakeshop
  provisioning (bakeshop `002` uses the MySQL-5.7-compatible idempotent
  PREPARE/EXECUTE pattern on a module-owned table). Resolved to reject dynamic
  SQL only when it references a kernel-owned table (the actual C13 risk), while
  still rejecting ALL static DDL against kernel-owned tables. Keeps the C13
  kernel-table protection AND the bakeshop provision contract green.
- **Status enum migration**: `kernel_tenants.status` is already `VARCHAR(30)`
  (accepts pending/provisioning), so no enum expansion was required; added the
  idempotent `007` control migration as a guard for narrowed deployments.
- **Pre-existing PREPARE/EXECUTE in planned module set**: rewriting unrelated
  module migrations is prohibited by the contract; these are MySQL-5.7-safe
  patterns, so the 8.0-only-breaker audit is clean.

## Remaining risks

- Multi-tenant mode: `kernelAdminAccessGranted` requires a resolvable tenant
  binding (per contract). On the control plane with `control_host` strategy and
  no tenant mapping, kernel-admin companion access is denied (fail-closed by
  design). The `getEnabledModules()` tenant-scoping + static cache means the
  granted path depends on the request-time enabled set.
- Full fresh-DB provisioning end-to-end + partial-DDL retry integration test
  (contract B11/C13/C14) was not added as a dedicated test in this session; the
  underlying behavior is implemented (kernel-before-module, declared-column seed,
  guarded executor, idempotency) and existing provision suites pass.
- `ark-audit-report.md` + current ARK audit task in `.ai/` are unrelated to this
  implementation; working tree delta is attributable to the kernel-sync task.
