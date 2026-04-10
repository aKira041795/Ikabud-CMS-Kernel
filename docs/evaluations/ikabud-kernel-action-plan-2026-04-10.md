# Ikabud Kernel Action Plan

**Source evaluation:** `docs/evaluations/ikabud-kernel-technical-evaluation-2026-04-10.md`  
**Plan date:** 2026-04-10  
**Status:** Proposed execution plan

## Purpose

Turn the technical evaluation into a concrete improvement program that reduces kernel complexity without destabilizing tenant isolation, module loading, or request dispatch.

This plan assumes the evaluation is directionally correct, with one important clarification from current repo state:

- runtime metadata is inconsistent across `docs/ARCHITECTURE.md` (`PHP 8.2+`) versus `README.md` and `composer.json` (`PHP 8.1+`)
- license metadata is inconsistent across `README.md` (`GPL v3.0`) versus `composer.json` (`proprietary`)

## Guiding Rules

1. Preserve runtime behavior first. Refactors must be seam-first, not rewrite-first.
2. Extract by responsibility, then add tests around the extracted boundary before moving the next slice.
3. Do not weaken tenant fail-closed behavior, kernel-managed security headers, or module manifest validation.
4. Keep route maps declarative and keep module handler contracts stable while kernel internals move.
5. Every phase must end with explicit no-regression validation.

## Outcomes

By the end of this plan, the repository should have:

- a thinner `public/index.php` that delegates request bootstrap, core route registration, and dispatch orchestration
- a smaller `kernel/App.php` with core services extracted behind narrow APIs
- a decomposed `src/helpers/module-manager.php` split by concern
- aligned runtime and license metadata across docs and package metadata
- contributor-facing docs for setup, tests, tenancy, and stable extension contracts

## Workstreams

### A. Front Controller Decomposition

Target file:

- `public/index.php`

Primary extraction targets:

- request bootstrap and request-context initialization
- session and cookie policy setup
- CORS and security header application
- core route registration
- module route merge and dispatch orchestration
- admin and superadmin handler closures that can move into dedicated files

Desired destination structure:

- `src/http/request-bootstrap.php`
- `src/http/core-routes.php`
- `src/http/dispatch.php`
- `src/http/admin-handlers.php`
- `src/http/superadmin-handlers.php`

### B. App Service Extraction

Target file:

- `kernel/App.php`

Primary extraction targets:

- tenant DB pool and reconnection logic
- auth user resolution and auth state handling
- render context construction and render failure handling
- CSRF token lifecycle
- kernel capability registration bootstrap

Desired destination structure:

- `kernel/Services/DatabaseManager.php`
- `kernel/Services/AuthContext.php`
- `kernel/Services/RenderContextFactory.php`
- `kernel/Services/CsrfService.php`
- `kernel/Bootstrap/KernelCapabilityBootstrap.php`

### C. Module Manager Decomposition

Target file:

- `src/helpers/module-manager.php`

Primary extraction targets:

- module discovery and manifest loading
- registry persistence and enable/disable behavior
- tenant settings and tenant entitlement reads/writes
- control-plane catalog and access request workflows
- tenant migration planning and migration execution helpers

Desired destination structure:

- `src/helpers/module-discovery.php`
- `src/helpers/module-registry.php`
- `src/helpers/module-settings.php`
- `src/helpers/module-catalog.php`
- `src/helpers/module-entitlements.php`
- `src/helpers/module-migrations.php`

### D. Metadata and Contributor Docs

Target files:

- `README.md`
- `composer.json`
- `docs/ARCHITECTURE.md`
- new docs under `docs/`

Primary fixes:

- choose one supported PHP floor and align all docs and package metadata
- choose one license source of truth and align all docs and package metadata
- document local setup, tenancy expectations, test runner workflow, and common debug paths
- document stable kernel extension contracts versus internal implementation details

### E. Security Hardening Follow-Through

Target files:

- `kernel/Http/SecurityHeaders.php`
- relevant DiSyL and PHP templates when nonce migration eventually happens

Primary scope:

- document current CSP tradeoffs explicitly
- define preconditions for moving from `'unsafe-inline'` to nonced scripts
- do not begin nonce rollout until template coverage is measurable and testable

## Phased Plan

## Phase 0: Baseline and Guardrails

Goal:

- make refactoring safe before moving core runtime code

Tasks:

- add a maintainer note describing the refactor program and non-goals
- capture current line counts and hotspots for `bootstrap.php`, `public/index.php`, `kernel/App.php`, and `src/helpers/module-manager.php`
- identify the smallest regression suite that must stay green for every kernel refactor PR
- add or document smoke tests for:
  - tenant resolution
  - login/logout and auth cookie resolution
  - module route loading
  - superadmin module admin flows
  - security header emission

Acceptance criteria:

- baseline metrics are documented
- required regression commands are documented in one place
- each later phase can point back to a stable safety checklist

## Phase 1: Metadata and Docs Correction

Goal:

- remove source-of-truth ambiguity before structural work starts

Tasks:

- align PHP version floor across `README.md`, `composer.json`, and `docs/ARCHITECTURE.md`
- align license metadata across `README.md` and `composer.json`
- add `docs/contributor-workflows.md` covering:
  - environment prerequisites
  - tenant/control DB expectations
  - how to run targeted tests
  - where to inspect logs
- add `docs/kernel-stable-contracts.md` covering:
  - hooks
  - events
  - capabilities
  - module manifest keys treated as stable contracts

Acceptance criteria:

- no runtime/license contradictions remain in top-level docs and package metadata
- contributor docs are sufficient for a new engineer to run tests and inspect failures

## Phase 2: Front Controller Split

Goal:

- reduce the blast radius of changes in `public/index.php`

Tasks:

- extract request bootstrap helpers from `public/index.php` into dedicated files under `src/http/`
- extract core route registration arrays into dedicated route builder files
- move large inline admin and superadmin handlers out of the front controller
- keep `public/index.php` as the composition root that wires these pieces together
- preserve existing route paths and auth behavior exactly

Suggested sequence:

1. extract pure helper functions first
2. extract route registration second
3. extract handler implementations third
4. leave final dispatch switch in place until all helpers are proven stable

Acceptance criteria:

- `public/index.php` becomes primarily orchestration code
- request behavior remains unchanged under the existing dispatch and integration tests
- security headers, request IDs, tenant rewriting, and module dispatch still happen in the same order

## Phase 3: App Service Extraction

Goal:

- shrink `kernel/App.php` into a coordination layer instead of a god object

Tasks:

- extract tenant/control DB connection logic into a dedicated service
- extract auth state resolution and user lookup into an auth context service
- extract render context assembly into a render-context factory
- extract CSRF handling into a dedicated service
- isolate kernel capability registration into a bootstrap class or function set

Suggested sequence:

1. database manager
2. auth context
3. render context factory
4. CSRF service
5. kernel capability bootstrap

Acceptance criteria:

- `App` still presents the same external API to callers
- extracted services can be reasoned about independently
- no tenant DB fail-open regressions are introduced

## Phase 4: Module Manager Split

Goal:

- separate unrelated control-plane, tenant, registry, and migration concerns

Tasks:

- split discovery and manifest helpers into one file
- split tenant settings and tenant settings cache behavior into one file
- split catalog and access-request workflows into one file
- split entitlements and licensing into one file
- split migration planning/execution into one file
- leave a thin compatibility shim in `src/helpers/module-manager.php` while call sites are transitioned

Acceptance criteria:

- each extracted file has a single dominant responsibility
- public helper names remain stable during the migration window
- manifest, entitlement, and migration tests stay green

## Phase 5: Security and Contract Hardening

Goal:

- tighten documentation and guardrails around the most sensitive kernel behavior

Tasks:

- document the current CSP compatibility posture and exact prerequisites for nonce migration
- add explicit regression tests around:
  - security headers
  - auth cookie discovery
  - cross-tenant JWT rejection
  - route ambiguity rejection
  - module manifest validation
- define which kernel extension points are stable and which remain internal

Acceptance criteria:

- hardening work is codified in docs and tests instead of institutional memory
- CSP changes have a documented migration path and test expectations

## Validation Matrix

Each phase should rerun at least:

- `composer test`
- focused tenancy and request-dispatch tests
- focused auth and security-header tests
- focused module-manager and manifest-validation tests
- manual smoke checks for `/login`, `/cms/login`, superadmin module management, and one tenant-routed module page when the phase touches routing or auth

Logs to inspect after failures:

- `storage/logs/app.log`
- `storage/logs/error.log`

## Suggested Delivery Order

1. Phase 0 and Phase 1 in one short cycle
2. Phase 2 as the first major refactor
3. Phase 3 after the front-controller seams are stable
4. Phase 4 after `App` extraction reduces cross-cutting coupling
5. Phase 5 continuously, but formalize it after Phases 2 through 4

## 30-Day Backlog

1. Align PHP and license metadata.
2. Add contributor workflow documentation.
3. Document stable kernel extension contracts.
4. Extract request bootstrap helpers from `public/index.php`.
5. Extract core route registration from `public/index.php`.
6. Add dedicated tests around security headers and dispatch ordering if coverage is still indirect.

## 60-Day Backlog

1. Extract DB management from `kernel/App.php`.
2. Extract auth context and CSRF lifecycle from `kernel/App.php`.
3. Extract render context construction from `kernel/App.php`.
4. Split module discovery and registry concerns out of `src/helpers/module-manager.php`.

## 90-Day Backlog

1. Split module catalog, entitlement, and migration helpers out of `src/helpers/module-manager.php`.
2. Add compatibility shims and retire direct monolithic helper edits.
3. Publish a kernel-maintainer guide for safe refactoring and rollout.
4. Decide whether CSP nonce migration is realistic for the next cycle or should remain deferred.

## What Not To Do

- do not rewrite `public/index.php` wholesale in one PR
- do not rename public helper APIs while structural extraction is underway
- do not change tenant DB failure behavior in pursuit of cleaner abstractions
- do not start CSP nonce rollout before templates are instrumented end to end
- do not mix feature delivery with kernel decomposition PRs unless a production bug forces it

## Success Measure

This plan is successful if the kernel remains behaviorally stable while the main runtime files stop being the default place for every new concern.