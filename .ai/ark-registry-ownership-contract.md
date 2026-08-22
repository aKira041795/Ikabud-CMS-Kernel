# Architecture Contract — ARK/Theme Registry Ownership (freeze-first)

task:
  Decide and freeze the ownership and persistence model for the ARK/theme
  registry (digest-conflict registration, unique identity rows, concurrency
  semantics, tenant selection) so slice-3 can land: registry DDL, ARK panel at
  cms.dashboard.widgets (read-only), idempotence/concurrency tests, and the
  theme resolver TARGET rewiring.

objective:
  Separate GLOBAL REGISTRATION from TENANT-LOCAL SELECTION as two distinct
  contracts with named owners, connections, tables, and writers. Freeze the
  existing implementation facts (filesystem discovery, settings-based
  selection) before writing ANY new DDL. Respect the MySQL 5.7 hard gate and
  the "capabilities and registries freeze before any route work" rule.

scope:
  allowed:
    - FREEZE the VERIFIED current-state facts below as the authoritative
      baseline for ARK + theme + profile registration/selection.
    - Define the APPROVED TARGET: a registry migration (unique index on
      artifact-type-scoped `(name, version)`, canonical digest, advisory lock,
      losers → explicit CONFLICT) that is ADDITIVE and does not disturb the
      existing filesystem-discovery + settings-selection paths.
    - Authorize the CMS module as the owner of theme registry persistence
      (themes are a CMS product surface; `cms.themes.list@1` already exists).
    - Authorize the Kernel (ApplicationProfileRegistry) as the owner of
      application-profile registration (kernel-level, CMS-independent).
    - Authorize a registry DDL migration under the EXISTING tenant migration
      runner/ledger (`_migrations`, `tenantSyncModuleMigrations`) — no
      Akira-specific ledger.
    - Specify tenant selection reuse: tenant-local active theme =
      `tenant_module_settings` (cms `active_theme`), and tenant-local profile
      selection = a NEW `application_profile` settings key in the same
      settings store (NOT a new table) unless the freeze audit proves
      otherwise.
    - Provide the digest-conflict + concurrency spec and the exact MySQL 5.7
      DDL (see constraints).
  prohibited:
    - No new Akira-owned tables. Registry persistence is owned by CMS (themes)
      and Kernel (profiles), never by a `cms-akira-*` module.
    - No tenant `ModuleDB` reach into the global registry table.
    - No blanket tenant/db-key rejection scan of arbitrary business content.
    - No MySQL 8.0+ features (no CTEs, window functions, JSON_TABLE,
      `CREATE INDEX IF NOT EXISTS`, functional/invisible indexes, enforced
      check constraints).
    - No forced JWT on registry/admin surfaces; no ARK selection mutation from
      a read-only panel.

## VERIFIED CURRENT STATE (freeze facts, from code — 2026-08-22)

1. THEMES ARE FILESYSTEM-DISCOVERED, NOT DB-REGISTERED.
   - `cmsAvailableThemes()` (modules/cms/helpers/40-theme-settings.php:643)
     scandirs `storage/cms-themes/` and reads `theme.manifest.json` (or legacy
     `theme.json`) per directory. No `INSERT`/`UPSERT` into a themes table.
   - `cmsConfiguredActiveTheme()` (same file:428) reads the per-tenant active
     theme from `getModuleSettings('cms')['active_theme']` (backed by
     `tenant_module_settings`). Selection is SETTINGS-based, not table-based.
   - ARK identity exists on disk:
     `storage/cms-themes/ark/theme.manifest.json` (name `ark`, version
     `3.0.0`).
   - `cms.themes.list@1` (modules/cms/helpers/55-capabilities.php:1113) reads
     the `cms_themes` table — a DB surface that is currently NOT the
     registration source of truth (filesystem is). FREEZE: this table is
     ancillary/populated elsewhere; do NOT build the registry on it without
     re-verifying its writer.

2. APPLICATION PROFILES ARE KERNEL-DISCOVERED (CMS-INDEPENDENT).
   - `ApplicationProfileRegistry` (kernel/Services/ApplicationProfileRegistry.php)
     scandirs `storage/application-profiles/`, loads
     `profile.manifest.json`, instantiates `ApplicationProfileProvider`
     (kernel/Contracts/ApplicationProfileProvider.php — stateless, no DB/auth/
     tenant access, no CMS deps). Pure in-memory static registry.
   - ARK Workbench identity: `storage/application-profiles/ark-workbench/
     profile.manifest.json` (name `ark-workbench`, version `0.1.0`,
     `supported_surfaces` desktop/mobile/tablet/print/pdf/email).
   - `ApplicationProfileResolver::resolve()` (kernel/Services/
     ApplicationProfileResolver.php) is a PURE read-only function:
     {profile, error}. NO persistence, NO mutation calls.

3. MIGRATION RUNNER (reuse, no Akira ledger).
   - `_migrations` tracking table + `tenantSyncModuleMigrations()`,
     `tenantEnsureMigrationTrackingTable()`, `tenantMigrationOwnershipPreflight()`,
     `syncTenantMigrationsForTenant()` (src/helpers/module-migrations.php).
   - MySQL 5.7 DDL does implicit commits — "transactional per migration" is NOT
     guaranteed; preflight + advisory-lock recovery required.

## APPROVED TARGET

### A. Registry persistence model (ADDITIVE)

- THEME REGISTRY (owner: CMS module, `cms`):
  - New migration in `modules/cms/database/migrations/NNN_theme_registry.sql`
    (reuse `_migrations` ledger, `tenantSyncModuleMigrations`).
  - Table `cms_theme_registry`:
    - `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
    - `name` VARCHAR(190) NOT NULL          (artifact name, e.g. `ark`)
    - `version` VARCHAR(32) NOT NULL        (semver, e.g. `3.0.0`)
    - `artifact_type` ENUM('theme','profile') NOT NULL DEFAULT 'theme'
    - `canonical_digest` CHAR(64) NOT NULL  (sha256 of canonical manifest JSON)
    - `manifest_path` VARCHAR(500) NOT NULL
    - `registered_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    - `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    - UNIQUE KEY `uq_registry_identity` (`artifact_type`,`name`,`version`)
    - ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  - NOTE: index bytes — 3 + 190*4 + 32*4 + 1 ≈ 892 bytes utf8mb4, well under
    InnoDB 3072-byte limit. No prefix uniqueness (complete `(name, version)`).

- PROFILE REGISTRY (owner: Kernel, ApplicationProfileRegistry):
  - Mirror table `kernel_application_profile_registry` with the SAME shape
    (artifact_type default 'profile'). Kernel-owned, base-DB (not tenant DB).

- BOTH use the same contract:
  - Registration IDEMPOTENT by `(artifact_type, name, version, canonical_digest)`
    → no-op on identical identity+digest.
  - Same identity + DIFFERENT digest → explicit `CONFLICT` (400 envelope),
    never silent overwrite.
  - Concurrency: advisory lock (`GET_LOCK('ikabud_registry_<artifact_type>', 10)`)
    around check-then-insert; the UNIQUE INDEX is the deterministic winner.
    Losers receive `CONFLICT`, NOT a silently-chosen winner.

### B. Tenant selection (settings-based, NOT a new table)

- THEME: reuse `getModuleSettings('cms')['active_theme']` (unchanged).
- PROFILE: new settings key `application_profile` in `tenant_module_settings`
  for the `kernel`/entry module — selected via existing authorized registry
  UI/API only. Resolver already accepts `$tenantProfileId` and remains a pure
  function.

### C. ARK panel (read-only, next slice)

- Owned by `cms.dashboard.widgets` (NOT a second `cms.sidebar`).
- Named read capability `cms.registry.read@1` (or reuse `cms.themes.list@1` +
  a kernel profile-list capability). Reads registry + current selection.
- NEVER mutates selection. Disabled provider → documented `resolved_from:
  fallback` + kernel default shell.

## constraints

- MySQL 5.7 hard gate on all DDL (see DDL above; no 8.0+ features).
- Reuse existing `_migrations` runner + advisory lock; recovery after implicit
  commit.
- Capability-only delegation; no cross-module DB access by Akira providers.
- Theme resolver TARGET (`akira.theme.resolve@1` depends =
  `cms.themes.list@1` + `theme.token.apply@1`) requires a NEW CMS
  runtime-diagnostics capability (`cms.theme.runtime@1`) because
  `cms.themes.list@1` returns theme records, not active-theme context. This is
  a separate approved capability addition gated by a drift test.

## acceptance

- Registry DDL applied via existing runner on base DB (kernel) + tenant DB
  (CMS), MySQL 5.7 compatible, unique index byte-checked.
- Idempotent re-registration (same digest) = no-op; different digest =
  CONFLICT; concurrent duplicates → one winner + CONFLICT for losers.
- `cms_akira_ark_registry_idempotence_test.php`,
  `cms_akira_ark_selection_nonmutation_test.php`,
  `cms_akira_background_job_tenant_binding_test.php`,
  `cms_akira_two_tenant_isolation_test.php` green.
- ARK panel read-only at `cms.dashboard.widgets`; selection via existing
  registry UI only; `resolved_from: fallback` documented.
- Existing gates stay green (architecture:check, module:validate, contract
  freeze tests).

## verification

- `php ikabud architecture:check`
- `php ikabud module:validate cms`
- Real MySQL 5.7 integration run on the registry DDL (advisory lock
  acquire/release, implicit-commit recovery, concurrent provisioning).
- New tests listed above.

## risk

- `cms_themes` table writer is currently unverified — do not assume it is the
  registration source of truth; the new registry is ADDITIVE and does not
  require it.
- Adding DDL to the CMS module touches a high-traffic module — additive table
  only, no ALTER of existing tables.
- Advisory lock alone is NOT a deterministic winner — unique index is; losers
  must see explicit CONFLICT.

status: READY_FOR_IMPLEMENTATION (slice 3, gated by MySQL 5.7 integration run)
