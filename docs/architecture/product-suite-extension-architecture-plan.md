# Product Suite And Extension Architecture Plan

Updated: 2026-08-05

## Review Verdict: Approved (2026-08-05)

Commit `39901426` is approved as the architecture baseline. The design correctly
adds a **suite graph over the flat loader** with manifests as the authority,
rather than a deeply nested runtime tree.

Confirmed outcomes:
- **Logical hierarchy enforced, not decorative.** Fleet validator + install gate
  reject extensions whose host is absent, contributions aimed at unknown hosts,
  contributions to undeclared extension points, and self-installing profiles.
- **Flat-loader compatibility preserved.** `discoverModules()` stays flat;
  suite-aware queries are a sidecar.
- **Dynamic CMS administration proven for SEO.** Enable → nav appears;
  disable → nav disappears; core survives.
- **Lifecycle and certification implemented.** Disable/uninstall/purge policy
  enforced before mutation; C12 (suite contract) and C13 (contribution shape)
  added, with C12 blocking when declared.

Target nav model (confirmed): only the product core owns static core nav;
**every optional extension contributes its own surfaces dynamically**.

## Purpose
Define the next-step architecture for Ikabud so product platforms like CMS Akira, PAL, AISS, ARK, and similar systems are governed as product suites, not only as a flat list of peer modules.

## Review Summary

### 1. Manifest schema gap (highest priority)
- Current manifest validation enforces core technical contracts, but does not enforce product hierarchy contracts like module kind, extension host, extension points, or admin contributions.
- Evidence:
  - src/helpers/manifest-validation.php

### 2. Runtime discovery remains logically flat
- Module discovery recursively scans all module manifests and stores them by module id in a flat map.
- Physical nesting helps repository clarity but does not define runtime ownership relationships.
- Evidence:
  - src/helpers/module-manager.php

### 3. Suite support exists but is limited
- The kernel already supports suite normalization and suite-aware install path targeting.
- Installer validates suite prefix consistency for module ids.
- This is a useful foundation but does not yet enforce extension ownership and lifecycle contracts.
- Evidence:
  - src/helpers/module-manager.php

### 4. CMS admin composition is mixed
- CMS admin sidebar is still mostly hardcoded in the admin layout.
- Dynamic extension nav support already exists through hook-based extension nav items.
- This means migration can be incremental instead of a hard rewrite.
- Evidence:
  - templates/modules/cms/layouts/admin.disyl
  - modules/cms/helpers/40-theme-settings.php
  - modules/cms/helpers/76-extensions-editor.php

### 5. Installer boundary is robust on zip safety, but not yet product-host aware
- Package extraction safety checks are strong.
- Product-level compatibility and host-surface checks are not first-class yet.
- Evidence:
  - src/helpers/module-manager.php

## Target Architecture Model

### Core concepts
- Product Suite
- Product Core Module
- Extension Module
- Adapter Module
- Profile Module
- Dynamic Contribution Registry
- Product Extension Manager UI backed by kernel enforcement

### Design rule
- Physical folder hierarchy is for organization.
- Manifest-declared logical hierarchy is authoritative.

## Manifest Schema V2 (additive)

Proposed new optional fields:
- suite
- kind: product-core | extension | adapter | profile | service | integration | standalone-application
- extends (or parent)
- extension_points
- contributes
- admin_contributions
- compatibility
- uninstall

Example intent:
- product-core declares extension points.
- extension declares extends and contributes.
- profile declares installs list.

## Implementation Roadmap

> Status: All phases implemented (2026-08-04). See `product-suite-extension-adr.md`
> for the architecture decision record. Companion tests live under `tests/`
> (`manifest_suite_contract_test`, `module_suite_graph_test`,
> `contribution_registry_test`, `cms_admin_contribution_nav_test`,
> `module_suite_install_gate_test`, `module_uninstall_policy_test`,
> `module_suite_certification_test`).

## Phase 0: ADR and compatibility policy
Deliverables:
1. ADR for suite graph, module kinds, contribution contract, lifecycle semantics.
2. Backward-compat policy from current manifests to schema-v2.

Acceptance:
1. No runtime behavior change.
2. Migration rules agreed and documented.

Status: ✅ Implemented (`product-suite-extension-adr.md`). Base schema stays v1;
v2 fields are additive and optional.

## Phase 1: Manifest schema-v2 support
Deliverables:
1. Extend validator in src/helpers/manifest-validation.php.
2. Add policy checks for extension/profile/core semantics.
3. Keep schema additive for existing modules.

Acceptance:
1. Existing manifests remain valid.
2. New fields are enforced when present.

Status: ✅ Implemented. `validateModuleSuiteContractV1()` validates kind, suite,
extends, extension_points, contributes, admin_contributions, compatibility,
uninstall, and profile installs. `validateModuleSuiteFleetV1()` enforces
cross-module relationships (extends target exists, contribution host exists,
extension points declared by host) in the manifest guard.

## Phase 2: Kernel suite graph registry
Deliverables:
1. Build suite graph sidecar from discovered modules.
2. Expose read APIs for suite core, extensions, and profiles.
3. Preserve existing discoverModules() compatibility.

Acceptance:
1. Existing callers continue to work unchanged.
2. Suite graph is available for installer/admin use.

Status: ✅ Implemented in src/helpers/module-manager.php: `moduleSuiteGraph()`,
`moduleSuites()`, `moduleSuiteMembers()`, `moduleSuiteCore()`,
`moduleSuiteExtensionPoints()`, `moduleSuiteForModule()`,
`moduleSuiteAdminHost()`, `moduleKindForModule()`, `moduleExtendsForModule()`.

## Phase 3: Dynamic contribution registry
Deliverables:
1. Create kernel contribution model for nav/settings/actions/widgets.
2. Support manifest-backed contributions.
3. Provide hook compatibility bridge for existing contributors.

Acceptance:
1. Existing hook-based CMS nav extensions still render.
2. New manifest contributions render without template patching.

Status: ✅ Implemented in src/helpers/module-manager.php:
`kernelContributionRegistry()`, `kernelContributionsForHost()`,
`kernelContributionsForHostLocation()`, `kernelContributionBridgeCmsNavItems()`.
The bridge registers on `cms.admin.nav_items` (priority 5) so manifest
contributions flow through the existing CMS rendering seam.

## Phase 4: CMS admin migration
Deliverables:
1. Refactor templates/modules/cms/layouts/admin.disyl to use contribution data as the primary source.
2. Keep a minimal static fallback for core safety.
3. Unify desktop/mobile nav generation from the same source.

Acceptance:
1. Extension enable/disable automatically adds/removes nav entries.
2. No dead links from absent modules.

Status: ✅ Implemented. `cmsAdminContext()` feeds `ext_nav_items` from
`cmsGetExtensionNavItems()`; both desktop and mobile sidebars of admin.disyl
render `ext_nav_items`, and the kernel bridge supplies manifest-declared
contributions through that seam. Disabling a module removes its contributions
automatically (verified by test).

Full dynamic sidebar migration (2026-08-05): the hardcoded optional sidebar
links (Page Builder, Theme, Navigation, SEO, Workflow, AI Assistant) were
removed from `admin.disyl` and replaced with manifest `admin_contributions`
declared by each cms-akira submodule:
- cms-akira-builder → Experience group (Page Builder)
- cms-akira-theme → Experience group (Theme)
- cms-akira-navigation → Experience group (Navigation)
- cms-akira-seo → Optimization group (SEO)
- cms-akira-workflow → Optimization group (Workflow)
- cms-akira-ai → Assistants group (AI Assistant)

Only constant operational links stay built-in in the shell (Dashboard,
Content, Content Types, Collections, Media Library, Modules, Routing, Users,
Permissions, Settings, Import/Export). Both desktop and mobile sidebars render
the dynamic sections (Experience / Optimization / Assistants) from the same
`ext_nav_items` source. Verified by render: optional hardcoded links absent,
dynamic sections present with children, no dead routes.

## Phase 5: Product extension manager and install gate
Deliverables:
1. Extend install flow in src/helpers/module-manager.php to validate host, suite compatibility, extension-point usage, and lifecycle declarations.
2. Keep product UIs (CMS/PAL/etc.) as shells over kernel installer services.

Acceptance:
1. Invalid extension packages are rejected before activation.
2. Product admin can only install compatible suite extensions.

Status: ✅ Implemented. `validateModuleSuiteContractForInstall()` runs inside
`installModuleFromZip()` after extraction/certification; it rejects missing
hosts, unknown contribution hosts, undeclared extension points, and
self-installing profiles. `84-extensions.php` module cards now expose
kind/suite/extends/contributes/compatibility/uninstall metadata.

## Phase 6: Disable/uninstall/purge semantics
Deliverables:
1. Manifest uninstall policy support.
2. Runtime disable mode.
3. Uninstall (code removal, data retained by default).
4. Explicit purge flow for owned data.

Acceptance:
1. Parent product survives extension disable/uninstall.
2. Purge requires explicit intent and confirmation.

Status: ✅ Implemented. `moduleUninstallPolicyForManifest()` / ForModule()
resolve the manifest `uninstall` block with safe defaults; `uninstallModule()`
enforces disable_safe (force required), supports_data_export, and
requires_confirmation_to_drop_data (confirm_purge required) before any
mutation. Disable preserves data; purge drops owned tables only with explicit
confirmation.

## Phase 7: Workbench certification
Deliverables:
1. Add extension certification checks for compatibility, tenant isolation, permission behavior, and lifecycle safety.
2. Integrate into manifest guard and release readiness workflow.

Acceptance:
1. Extensions cannot be certified without passing lifecycle and compatibility checks.

Status: ✅ Implemented. `validateModuleCertification()` gains C12 (product suite
contract — strict when declared) and C13 (admin contribution shape — advisory).
`ok` now depends only on CertificationBlocker checks; advisory checks inform
but never block. The manifest guard runs fleet-level suite checks via
`validateModuleSuiteFleetV1()`. `score`/`max` preserve full check counts for
CLI/Workbench/superadmin consumers.

## Proof Of Concept

POC target: CMS Akira SEO

Scope:
1. CMS Akira Core declares extension points.
2. CMS Akira SEO declares extension kind, extends relation, and admin contribution.
3. CMS admin renders SEO navigation dynamically.
4. Disable and uninstall demonstrate expected lifecycle behavior.

Expected behavior:
1. SEO not installed: no SEO nav/settings surface.
2. SEO installed: nav/settings appear automatically.
3. SEO disabled: nav/settings disappear, core CMS remains intact.
4. SEO uninstalled: code removed, data retained unless explicit purge.

Status: ✅ Implemented. Manifests updated:
- cms-akira-core → product-core, suite, product, extension_points
- cms-akira-seo → extension, suite, extends, contributes, admin_contributions, compatibility, uninstall
- profiles (standard/minimal/visual/headless) → profile kind + installs
- media/editor/theme/navigation/workflow/ai/builder → extension
- search-adapter → adapter

Verified by `cms_admin_contribution_nav_test.php`: with SEO enabled, the live
manifest contribution appears in the registry and folds into
`cms.admin.nav_items` as an "Optimization" section; when disabled it
disappears. `ikabud module:certify cms-akira-seo` renders C12/C13 correctly.

## Risks And Guardrails
- Avoid breaking existing hook-based extension navigation during migration.
- Keep schema-v2 additive to avoid bulk module rewrites.
- Enforce suite boundaries in kernel installer, not in product UIs.
- Preserve tenant isolation checks at every lifecycle stage.

## Immediate Next Steps (post-approval roadmap)

### Evidence to gather (three deployments)
1. **CMS Akira SEO** — done: install → nav appears; disable → nav disappears;
   uninstall → CMS survives.
2. **PAL extension** — ✅ done (2026-08-05): `modules/pal/pal-core`
   (product-core) + `modules/pal/pal-advanced-reporting` (extension) prove the
   model is not CMS-specific. Live discovery resolves the `pal` suite (core,
   extension, `pal.report.providers`/`pal.sidebar` points), the sidebar
   contribution resolves for a non-CMS host, and the install gate enforces the
   same host/point/route/compatibility rules. See `product_suite_evidence_test.php`.
3. **An adapter** — ✅ done (2026-08-05): `cms-akira-search-adapter` is a
   provider-only adapter (no `admin_contributions`, no sidebar) that can be
   disabled without affecting the suite core. See `product_suite_evidence_test.php`.

### Recommended next safeguards (not blockers for approval)
1. **Duplicate contribution identities** — ✅ done (2026-08-05): each
   contribution gets a stable `id` (declared or derived `<module>.<location>`);
   the registry rejects duplicates per host+location (first-wins) and records
   them in `_conflicts`; the fleet validator emits a fatal on duplicate ids.
2. **Route-ownership validation** — ✅ done (2026-08-05): install gate G5 and
   certification C13 verify the contributing module registers the declared
   route (GET); `moduleContributionRouteRegistered()` / `RouteMethod()` reuse
   the route pattern matcher to prevent dead links.
3. **Explicit compatibility enforcement** — ✅ done (2026-08-05): install gate
   G6 rejects incompatible Kernel version and incompatible host-suite version
   using a semver range matcher (`kernelSemverRangeSatisfies()` supporting
   `>=`, `>`, `<=`, `<`, `^`, `~`, exact, and compound ranges). Covered by
   `module_suite_compatibility_test.php`.
4. **Tenant-specific contribution filtering** — ✅ done (2026-08-05): the
   registry and host queries accept a context (`tenant_id` / `user`); explicit
   tenant enablement (`isModuleEnabledForTenant`) and role allowlists
   (`admin_contributions.roles`) are enforced, and the CMS bridge resolves the
   request context automatically. Covered by `contribution_registry_test.php`.
5. **Product extension manager UX** — ✅ done (2026-08-05): CMS hosts a generic
   product-suite extension manager (`/cms/admin/extensions` →
   `cmsAdminProductExtensions` + `cmsApiProductSuites` +
   `cmsApiModuleUninstall`) listing suites with core/extensions/adapters/
   profiles, per-module Health, and Disable / Uninstall / Export / Purge
   actions — all backed by the Kernel services (`moduleSuiteGraph`,
   `uninstallModule`, enable/disable). PAL and other products can reuse the
   same endpoints for their own "→ Extensions" screens.
