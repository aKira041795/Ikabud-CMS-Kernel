# CMS + Application Kernel Reconciliation Review (Code + Architecture)

Updated: March 2026
Scope included: bootstrap.php, public/index.php, kernel/*, src/helpers/module-manager.php, modules/cms docs and architecture
Scope excluded: ikabud-kernel directory (reference only)

## 1. Executive Summary

The CMS is functionally strong and already production-usable for content teams.
The Application Kernel OS provides a real governance backbone: module boundaries, capability bus, hooks/events, tenant routing, and scoped database access.

Implementation progress since this review:
Implementation progress since this review:

**Phase A (Immediate) — COMPLETED:**
- wildcard purge table drops removed; uninstall now deletes only declared owned tables
- module/theme package install path now uses fail-closed ZIP validation with path, type, signature, size, and containment checks
- redirect helper now guards empty input safely

**Phase B (2-6 weeks) — COMPLETED:**
- capability caller context now uses the resolved effective user
- route loading now emits semantic ambiguity warnings for dynamic/static pattern collisions
- capability breaker/metrics file updates now mutate under lock to reduce lost updates under concurrent traffic
- CLI/operator bootstrap path now has an explicit helper with actionable failure guidance
- installer API now emits request-scoped audit logs and machine-readable rejection codes

**Phase C (6-12 weeks) — IN PROGRESS:**
- CMS formal capability contracts expanded to include media, builder, settings, themes (feature set 6 new capabilities)
	- cms.media.list@1: List media with search/type filtering
	- cms.media.upload@1: Upload media files (base64 via capability bus)
	- cms.builder.get@1: Fetch builder document with parsed JSON
	- cms.builder.render@1: Render builder document to HTML
	- cms.settings.get@1: Get CMS settings (single key or bulk)
	- cms.themes.list@1: List available themes with metadata
- All capability handlers include comprehensive error handling and audit logging
- Capability input/output contracts documented in cms-capability-map.md@@

Reconciliation verdict:
- Architectural fit is strong: CMS capabilities map cleanly to kernel extension primitives.
- Operational fit is mixed: core runtime is solid, but there are a few high-risk kernel/module-manager behaviors that can cause data loss or security drift.
- Product maturity fit is improving: CMS value is currently ahead of kernel hardening in a few operational paths.

## 2. What Works Well (Kernel ↔ CMS Alignment)

1. Boundary model is real, not only convention.
- Kernel + module-manager enforce route ownership, scoped ModuleContext usage, and module-scoped DB access.
- This directly supports CMS as a large module without collapsing into global coupling.

2. Capability architecture supports CMS growth.
- Kernel capability registration + policy + schema validation gives a long-term path for CMS contracts (media/builder/settings/theming) to become stable service contracts.

3. Tenant routing integrates with module entry strategy.
- Tenant domain mapping, entry module rewrite, and suspended-tenant handling provide a good platform shape for multi-tenant CMS operation.

4. Render context and hook chain are cleanly centralized.
- Kernel render context and hook filter sequence reduce duplication and keep CMS render integrations composable.

## 3. Code Review Findings (Ordered by Severity)

## Critical

1. Purge uninstall can drop unrelated module tables.
- Evidence: src/helpers/module-manager.php:1376
- Code path uses SHOW TABLES LIKE 'gm_%' and drops all matches during purge.
- Impact: uninstalling one module can delete data from other modules that happen to share gm_* naming.
- Why this matters to CMS: if CMS-adjacent modules share naming patterns, purge can become cross-module destructive.
- Action: remove broad wildcard drop behavior; drop only manifest-declared owned tables plus explicit allowlisted legacy tables for that exact module.

## High

2. Module zip installer extraction hardening is incomplete.
- Evidence: src/helpers/module-manager.php:1197, src/helpers/module-manager.php:1262, src/helpers/module-manager.php:1276
- Current check only blocks '..' and does not enforce a strict canonical extraction policy with normalized paths + entry type checks.
- Impact: elevated risk around malformed archives and edge-case path tricks.
- Why this matters to CMS: CMS installs modules/themes and depends on secure package handling.
- Action: implement strict extraction validator (reject absolute paths, backslashes, null bytes, symlink entries, non-file/dir entry types), and verify destination realpath containment before write.

3. Redirect helper can access empty string offset.
- Evidence: kernel/App.php:1083, kernel/App.php:1086
- redirect() checks $url[0] without guarding empty string input.
- Impact: warning-level runtime issue that can turn into noisy logs or undefined behavior in error paths.
- Action: guard with if ($url === '') fallback before index access.

## Medium

4. Capability metrics/breaker state can suffer race-related lost updates.
- Evidence: kernel/Capabilities/CapabilityBus.php:624, kernel/Capabilities/CapabilityBus.php:637
- Pattern is read-modify-write JSON file state; writes are locked but read+merge remains non-atomic under concurrent requests.
- Impact: health telemetry and breaker counters can become inconsistent under traffic.
- Action: move to DB-backed or atomic lock-file transaction semantics for capability telemetry and breaker state.

5. Route conflict detection is exact-pattern only (not semantic-pattern collision aware).
- Evidence: src/helpers/module-manager.php:601, src/helpers/module-manager.php:613
- Detects exact string duplicates but not collisions like /foo/{id} vs /foo/bar across modules.
- Impact: ambiguous routing order can still happen with dynamic route patterns.
- Action: add route ambiguity linting at module load/install time.

6. Capability call context user can be stale relative to module-specific auth override.
- Evidence: src/helpers/module-manager.php:893, src/helpers/module-manager.php:944
- Access gate can resolve module cookie user, but capability context stores app()->user() again.
- Impact: trace/audit caller identity may mismatch in mixed kernel/module auth scenarios.
- Action: set capability context user from resolved effective user used by access gate.

## Low

7. Security helper sameSite config key appears inconsistent with broader app config style.
- Evidence: src/helpers/security.php:33
- Uses config('app.cookie.samesite', 'Strict') which may not align with main cookie config naming.
- Impact: fallback behavior may hide misconfiguration.
- Action: normalize config path usage and document canonical cookie config keys.

## 4. CMS Review Reconciled with Kernel Findings

How kernel findings affect CMS priorities:

1. CMS installer/theme hardening priority increases.
- Existing CMS roadmap already calls for installer hardening.
- Kernel-level zip extraction finding confirms this is platform-level, not CMS-only.

2. CMS contract expansion should wait for telemetry reliability fix.
- Before exposing many new CMS capability contracts, capability health/metrics race behavior should be stabilized.

3. Multi-tenant CMS confidence depends on purge safety.
- Cross-module table-drop risk is incompatible with safe tenant operations and extension lifecycle.

4. CMS operational debugging improves once caller identity consistency is fixed.
- Correct capability context user propagation will improve auditability of CMS-driven capability chains.

## 5. Prioritized Cross-Layer Action Plan

## Phase A (Immediate: 0-2 weeks)

1. Remove wildcard purge drops.
- Replace gm_* drop behavior with strict manifest-owned table deletion.
- Add integration test: uninstall module A must never drop module B tables.
- Status: implemented in code; regression test added and passing.

2. Harden archive extraction.
- Add strict archive entry validator and containment checks.
- Add malicious archive fixture tests.
- Status: implemented in code; malicious archive fixture tests added and passing.

3. Guard redirect empty URL.
- Add safe default handling and tests for empty/invalid redirect inputs.
- Status: implemented in code; tests added and passing.

## Phase B (2-6 weeks)

1. Stabilize capability telemetry storage.
- Migrate breaker/metrics to DB table or atomic lock strategy.
- Add concurrency test harness for capability call bursts.

2. Improve route conflict validation.
- Add semantic conflict detection for dynamic patterns at install/enable time.

3. Fix effective-user propagation for capability context.
- Use resolved module-auth user when establishing _capability_call_context.

## Phase C (6-12 weeks)

1. Expand CMS formal capability contracts after Phase A+B.
- media list/upload, builder get/render, settings get, themes list.

2. Add operator diagnostics panel.
- capability health, breaker state, route conflict warnings, installer audit trail.

3. Strengthen tenancy hardening checklist for CMS deployments.
- include suspended-tenant behavior, auth cookie boundaries, and install policy enforcement.

## 6. Final Assessment

The CMS and kernel are strategically aligned.
The main blocker to top-tier platform maturity is no longer missing features; it is the quality and safety of lifecycle operations and cross-module runtime guarantees.

If Phase A is completed quickly, the platform can move from "feature-rich and promising" to "production-trustworthy and scalable" for serious multi-tenant CMS operations.
