# Application Kernel OS Review 2026

Updated: March 18, 2026
Scope: kernel runtime, module system, capability bus, tenancy routing, developer operational workflows

## 1. Executive Summary

The Application Kernel OS has real platform-level strengths that are uncommon in PHP CMS stacks:
- module-scoped DB enforcement
- capability contracts with versioning and provider policy
- explicit extension seams (hooks/events/capabilities)
- request-scoped observability and safety hardening in install/runtime paths

Current maturity is strong for extensibility and governance, with targeted reliability gaps in:
- silent fallback behavior in tenant entry routing
- warning-only behavior for ambiguous routes
- operational protection around module skip conditions and critical entry module dependencies

Overall assessment:
- Unique Advantages: High
- Reliability/Stability: Medium-High
- Site Developer Usefulness: High

## 2. What Makes This Kernel OS Distinct

1. Contract-first extension architecture
- Capability IDs are versioned and resolvable (`contract.id@major`) with inspectable provider metadata.
- Modules can expose stable contracts while retaining implementation autonomy.

2. Runtime policy and caller-aware capability execution
- Capability bus supports first/pipeline/fanout modes plus provider/caller policy gates.
- Caller context propagation enables audit-safe cross-module behavior.

3. Database ownership boundaries enforced by runtime
- ModuleDB enforces owns/reads table declarations and blocks undeclared access.
- This significantly reduces accidental cross-module data coupling.

4. Tenant-aware request rewriting with entry module model
- Hostnames can map to tenant + entry module for multi-tenant application shells.
- Kernel maintains central auth/API/admin paths while enabling tenant-specific entry UX.

5. Operationally useful observability primitives
- Request ID propagation and centralized log writing are built into bootstrap/runtime.
- Capability telemetry and breaker state provide actionable health insight.

## 3. Reliability and Stability Assessment

## Strengths

1. Safe defaults in core request pipeline
- security headers, strict session cookie defaults, CORS allowlisting, request-id headering.

2. Capability resilience mechanics
- provider breaker + metrics state, policy filtering, and non-fatal tracing paths.

3. Module lifecycle hardening improvements already present
- stricter manifest validation, safer installer preflight checks, and explicit CLI bootstrap helper pattern.

## Risks

1. Entry module outages can degrade into generic 404 behavior
- If a tenant entry module is skipped at load due to manifest/capability errors, tenant root rewrite can map to an unavailable route and return 404.

2. Tenant rewrite failures are silently swallowed
- Router exceptions currently fall back to original URI without explicit telemetry.

3. Route ambiguity is linted but not prevented
- Ambiguous dynamic/static combinations are warning-only and may produce non-obvious runtime behavior.

## 4. Usefulness to Site Developers

## Strongly Useful Today

1. Fast modular feature delivery
- Developers can add modules with clear route/capability boundaries and predictable runtime integration.

2. Better long-term maintainability
- Explicit contracts and data boundaries reduce hidden coupling and regressions in large projects.

3. Better operational debugging than typical CMS stacks
- request-id correlation and centralized logging reduce mean-time-to-diagnose.

## Friction Points

1. Some critical failure modes are soft-fail instead of loudly actionable.
2. Route conflict/ambiguity outcomes are not yet deterministic enough for large module portfolios.
3. Tenant entry routing lacks enough “operator-signal” when fallback behavior activates.

## 5. Actionable Implementation Plan

## Workstream A: Critical Entry-Path Reliability (P0, 1-2 weeks)

### A1. Add explicit tenant rewrite failure logging
- Why: Silent rewrite fallback hides root causes.
- Implementation targets:
  - `kernel/Http/TenantEntryRouter.php`
- Changes:
  - In catch blocks, emit warning log with request_id, host, uri, exception message.
  - Include structured context for tenant lookup path.
- Acceptance criteria:
  - On forced control DB failure, logs include `tenant_rewrite_fallback` with request_id and host.
  - No behavior regressions for normal routing.

### A2. Add entry-module availability pre-check for `/`
- Why: Avoid generic 404 when tenant entry module is skipped.
- Implementation targets:
  - `kernel/Http/TenantEntryRouter.php`
  - `public/index.php`
- Changes:
  - Validate resolved `entry_module_id` is enabled and routeable before rewriting root.
  - If unavailable, set a server flag (e.g., `IK_ENTRY_MODULE_UNAVAILABLE`) and preserve original URI.
  - Render explicit 503 or dedicated maintenance-style page for tenant root when entry module unavailable.
- Acceptance criteria:
  - Misconfigured entry module never returns plain generic 404 for `/`.
  - Operator sees deterministic response + actionable log context.

### A3. Add startup/health warning for skipped entry modules
- Why: Catch drift before traffic impact.
- Implementation targets:
  - `src/helpers/module-manager.php`
  - `public/index.php` health endpoint handling
- Changes:
  - Track skipped modules and reasons in-memory cache for current request window.
  - Expose summary in admin health API and optionally `/api/v1/health` details for admins.
- Acceptance criteria:
  - Health endpoints include skipped-module reasons for privileged users.
  - Entry module skip is visible within one request cycle.

## Workstream B: Deterministic Routing & Conflict Control (P1, 2-4 weeks)

### B1. Introduce route ambiguity strict mode
- Why: Warning-only mode can hide real routing nondeterminism.
- Implementation targets:
  - `src/helpers/module-manager.php`
  - `config/app.php`
- Changes:
  - Add config flag: `app.modules.route_ambiguity_mode = warn|block`.
  - In `block` mode, ambiguous routes are rejected at load/install with clear log reason.
- Acceptance criteria:
  - In block mode, ambiguous routes do not register.
  - Existing deployments can remain in warn mode for backward compatibility.

### B2. Add route precedence rules
- Why: Static and dynamic routes should resolve predictably.
- Implementation targets:
  - `public/index.php`
- Changes:
  - Pre-sort route candidates by specificity before regex matching:
    - static > mixed > fully dynamic
    - longer segment count > shorter
- Acceptance criteria:
  - `/foo/new` is never shadowed by `/foo/{id}` regardless of insertion order.
  - Existing route behavior stays stable except where ambiguity currently exists.

## Workstream C: Developer Safety Rails (P1, 2-3 weeks)

### C1. Expand manifest guard coverage
- Why: Catch invalid module manifest/capability shapes pre-runtime.
- Current state:
  - `scripts/guard-module-manifests.php` validates manifests and capability blocks.
- Implementation targets:
  - `scripts/guard-module-manifests.php`
  - `composer.json`
- Changes:
  - Add optional `--strict` mode:
    - fails on folder-id mismatch
    - fails on duplicate capability expose IDs across modules (informational report at minimum)
  - Add CI-focused output mode (machine-readable JSON summary).
- Acceptance criteria:
  - Guard exits non-zero on strict violations.
  - CI can parse summary for pipeline reporting.

### C2. Add preflight gate to module install/enable operations
- Why: Prevent loading modules with known-invalid capability configuration.
- Implementation targets:
  - `public/index.php` admin module endpoints
  - `src/helpers/module-manager.php`
- Changes:
  - Before enable/install finalization, run full manifest + capability validation and return structured error_code.
- Acceptance criteria:
  - Invalid capability dependencies/expose shape cannot be enabled.
  - API response includes deterministic error code + request_id.

## Workstream D: Telemetry and Operational Diagnostics (P2, 4-6 weeks)

### D1. Operator diagnostics aggregation endpoint
- Why: Developers and operators need one place to inspect kernel health.
- Implementation targets:
  - `public/index.php`
  - capability metrics/breaker readers in `kernel/Capabilities/CapabilityBus.php`
- Changes:
  - Add admin endpoint returning:
    - capability breaker states
    - metrics summary
    - skipped module list + reasons
    - route ambiguity report snapshot
- Acceptance criteria:
  - Single endpoint provides actionable status without log spelunking.

### D2. Request-id trace quality improvements
- Why: Support/debug workflows depend on reliable request correlation.
- Implementation targets:
  - `bootstrap.php`
  - key failure paths in `public/index.php`, `TenantEntryRouter`, module-manager
- Changes:
  - Ensure all major warning/error logs include request_id and route/host context.
- Acceptance criteria:
  - Random sample of 20 warnings/errors each include request_id and caller context.

## 6. Suggested Delivery Sequence

1. Week 1-2:
- A1, A2, A3

2. Week 3-4:
- B1, B2, C2

3. Week 5:
- C1 strict/json output enhancements

4. Week 6+:
- D1, D2

## 7. Validation Checklist (Per Release)

1. Functional checks
- tenant root routing returns expected status for:
  - active tenant + valid entry module
  - active tenant + invalid entry module
  - suspended tenant
- route matching deterministic for static/dynamic overlap test cases

2. Guard/validation checks
- `composer run-script guard:manifests` passes in normal mode
- strict mode catches intentionally malformed fixture manifests

3. Observability checks
- all P0/P1 failure paths log request_id + host + uri
- diagnostics endpoint reflects latest breaker/skip/ambiguity state

## 8. Definition of Done for This Review Plan

This review plan is considered implemented when:
- P0 and P1 workstreams are merged and deployed
- tenant entry module unavailability no longer manifests as generic 404 at root
- route ambiguity handling is configurable and deterministic
- manifest guard supports strict/CI workflows
- operators have one consolidated diagnostics endpoint for kernel health
