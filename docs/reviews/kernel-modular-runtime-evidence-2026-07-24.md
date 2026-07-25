# Kernel OS: Evidence of a Genuine Modular Runtime

> Prepared 2026-07-24 — a comprehensive reference demonstrating that the Ikabud Kernel OS is a genuine modular application runtime, not merely "a CMS with fancy branding."

---

## Table of Contents

1. [Executive Summary](#executive-summary)
2. [Proof 1: 22+ Non-CMS Modules with Full Autonomy](#proof-1-22-non-cms-modules-with-full-autonomy)
3. [Proof 2: The Kernel Is Not CMS-Coupled](#proof-2-the-kernel-is-not-cms-coupled)
4. [Proof 3: Cross-Module Communication via Event Bus + Capability Bridge](#proof-3-cross-module-communication-via-event-bus--capability-bridge)
5. [Proof 4: Module Development Is Fully Documented and CMS-Agnostic](#proof-4-module-development-is-fully-documented-and-cms-agnostic)
6. [Proof 5: Diverse Module Archetypes](#proof-5-diverse-module-archetypes)
7. [Proof 6: CLI Scaffolding for Any Module Type](#proof-6-cli-scaffolding-for-any-module-type)
8. [Proof 7: Entity View System — Declarative Rendering for Any Module](#proof-7-entity-view-system--declarative-rendering-for-any-module)
9. [Proof 8: Operational Validation — What's Been Tested and What Hasn't](#proof-8-operational-validation--whats-been-tested-and-what-hasnt)
10. [Quick Reference: What a Module Looks Like](#quick-reference-what-a-module-looks-like)
11. [Conclusion](#conclusion)

---

## Executive Summary

The Ikabud Kernel OS provides a **modular application runtime** that supports:

- **22+ modules** running in the same tenant process, 100% of which use the same kernel contracts (`module.json`, capability bus, event bus, hooks, entity views)
- **6+ fully standalone non-CMS modules** — each with their own auth (`auth_owned`), database tables, user system, DiSyL admin shell, and business domain (ledger, payroll, warehouse, bakery ops, guidance counseling, construction project audit)
- **Cross-module communication** via a decoupled event bus + IntegrationBridge that maps events to capabilities across module boundaries (proven: Ecommerce ↔ WMS with 11 bidirectional bridges)
- **Module development guides** that are entirely CMS-agnostic, covering capability contracts, event wiring, entity views, hooks, auth-owned modules, polyglot services, and multi-tenant patterns
- **CLI scaffolding** (`php ikabud make:module`, `make:entity`, `make:capability`, `make:handler`) that works for any module type
- **Polyglot support** — modules can expose capabilities via Python/Node/Go through a ServiceProxy wire protocol

**The CMS module (`modules/cms/`) is just one module among 32+.** It is the largest and most mature, but it uses the same kernel contracts as every other module. The kernel itself has zero CMS-specific code paths.

---

## Proof 1: 22+ Non-CMS Modules with Full Autonomy

### Fully Standalone, Auth-Owned Modules (own users table, login, admin shell)

These modules are **complete applications** — they own their authentication, user tables, password reset flow, admin layout, and business logic. They do not depend on CMS for anything.

| Module | Domain | Tables | DiSyL Templates | Capabilities Exposed |
|--------|--------|--------|-----------------|---------------------|
| **daily-ledger** | Retail branch ledger + inventory | 26 | 19+ | `kernel.auth.authenticate@1`, `entity.list/get.daily_ledger_entry@1` |
| **attendance-wage** | HR: attendance, payroll, benefits | 17 | 35+ | `kernel.auth.authenticate@1`, 26 total (role-scoped + entity) |
| **guidance** | Student counseling + case mgmt | 27 | 25+ | `kernel.auth.authenticate@1`, `module.license.activate@1`, 7 entity capabilities |
| **wms** | Warehouse management | 27 | 18+ | `kernel.auth.authenticate@1`, `wms.stock.*`, `wms.order.*` |
| **bakeshop** | Bakery ops: production, deliveries | 14 | 12+ | `kernel.auth.authenticate@1`, `bakeshop.read/manage@1`, entity capabilities |
| **project-audit-ledger** | Construction project audit | 40 | 10+ | `kernel.auth.authenticate@1`, `pal.*`, workbench scenarios |
| **inventory-scanner** | Barcode scanning (Android app) | 5 | 5+ | `kernel.auth.authenticate@1`, scan lookup/save/sync |

Each of these modules declares its `auth_owned` in `module.json` with a complete schema: user table, role column, cookie name, login path, and password reset support. The kernel discovers these at boot and routes `/login` to the correct module based on the host/tenant.

### Service / Infrastructure Modules (no own auth, cross-cutting)

| Module | Purpose | Capabilities Exposed |
|--------|---------|---------------------|
| **anti-spam** | Honeypot, rate limiting, IP blocking | `antispam.check@1` |
| **security** | File integrity, audit, IP allowlist | `security.audit@1` |
| **sms** | SMS notifications (multi-provider) | `sms.send@1` |
| **search** | Full-text search index | `search.query@1`, `search.index.upsert@1` |
| **tinymce** | Shared rich-text editor | `tinymce.assets.get@1`, `tinymce.html.sanitize@1` |
| **ai** | AI capability providers | Multiple AI capabilities |
| **email** | Email delivery | Email capabilities |
| **workflow** | Multi-step workflow engine | Workflow capabilities |

### Multi-Tenant / License Modules

| Module | Purpose |
|--------|---------|
| **ecommerce** | Storefront + cart + orders (CMS-adjacent but with own domain services) |
| **moodle-sso** | Moodle single sign-on integration |
| **contact-form** | Contact form builder |
| **marketing** | Marketing campaign tools |
| **ehr** | Electronic Health Records (clinical) |
| **daily-ledger** | (listed above — also multi-tenant) |

---

## Proof 2: The Kernel Is Not CMS-Coupled

### Kernel bootstrap (`bootstrap.php`) and entrypoint (`public/index.php`)

The kernel's request lifecycle has **zero CMS-specific code paths**:

1. Load environment, constants, autoloader
2. Instantiate `App` singleton (DB, cache, events, capabilities, hooks)
3. Discover enabled modules via `module-manager.php`
4. Load all module routes into a unified route table
5. Match the incoming request to a route (from any module)
6. Dispatch to the handler (in the declaring module's context)
7. The handler may call `render()` which invokes DiSyL template engine

**CMS is never mentioned.** The route `POST /cms/login` is just a route in `modules/cms/routes.php` — the same as `POST /dl/login` in `modules/daily-ledger/routes.php`. The kernel treats them identically.

### Kernel-provided infrastructure (module-agnostic)

| System | Purpose | CMS Dependency |
|--------|---------|---------------|
| **Capability Bus** | `app()->cap()->call('module.cap@1', $input)` | None |
| **Event Bus** | `app()->events()->fire('entity.action', $payload)` | None |
| **Hooks** | `app()->hooks()->apply('kernel.render_context', $ctx)` | None |
| **Workflow Engine** | State machine + multi-step YAML workflows | None |
| **Entity Views** | `{ikb_entity_list}` declarative rendering | None (used by CMS, attendance-wage, daily-ledger, guidance, bakeshop, wms) |
| **DiSyL Templates** | Template language with extends/includes/components | None |
| **Module DB** | `module()->db()` — scoped PDO with tenant isolation | None |
| **Integration Bridge** | Event → Capability mapping across modules | None |
| **Job Queue** | Async job dispatch + processing | None |
| **CLI Tools** | `php ikabud make:*`, `tenant:*`, `architecture:check` | None |

---

## Proof 3: Cross-Module Communication via Event Bus + Capability Bridge

### Architecture

```
Module A                    Kernel                    Module B
─────────                   ──────                    ────────
fires event ──→ EventBus ──→ IntegrationBridge ──→ CapabilityBus ──→ handler
                (priority)     (DB-backed)            (contract)
```

### Ecommerce ↔ WMS: 11 Bridge Definitions (Production Code)

**File**: `modules/ecommerce/helpers/20-orders.php` lines 406–640

#### Ecommerce → WMS (5 bridges)

| Bridge | Trigger Event | Target Capability |
|--------|--------------|-------------------|
| `ecommerce_wms_reserve` | `ecommerce.order.created` | `wms.stock.reserve@1` |
| `ecommerce_wms_order_create` | `ecommerce.order.created` | `wms.order.create@1` |
| `ecommerce_wms_release` | `ecommerce.order.cancelled` | `wms.stock.release@1` |
| `ecommerce_wms_refund_release` | `ecommerce.order.refunded` | `wms.stock.release@1` |
| `ecommerce_wms_cancel_order` | `ecommerce.order.cancelled` | `wms.order.cancel@1` |

#### WMS → Ecommerce (6 bridges)

| Bridge | Trigger Event | Target Capability |
|--------|--------------|-------------------|
| `wms_ecommerce_processing` | `wms.order.picked` | `ecommerce.orders.status.sync@1` |
| `wms_ecommerce_shipped` | `wms.order.dispatched` | `ecommerce.orders.status.sync@1` |
| `wms_ecommerce_tracking_sync` | `wms.order.dispatched` | `ecommerce.orders.tracking.sync@1` |
| `wms_ecommerce_delivered` | `wms.order.delivered` | `ecommerce.orders.status.sync@1` |
| `wms_ecommerce_manual_payment_complete` | `wms.order.payment_collected` | `ecommerce.orders.payment.sync@1` |
| `wms_ecommerce_refund_tracking` | *(refund event)* | *(refund tracking)* |

#### How It Works (Runtime)

1. **Ecommerce fires** `ecommerce.order.created` after checkout (`modules/ecommerce/helpers/20-orders.php:966`)
2. **EventBus** notifies all listeners — including `IntegrationBridge::handle` (registered at kernel boot, `kernel/App.php:138`)
3. **IntegrationBridge** looks up the `kernel_integrations` table, finds the active bridge, resolves `wms.stock.reserve@1`, applies value mapping
4. **CapabilityBus** calls `wms_cap_stock_reserve_1()` in WMS's module context
5. **WMS handler** reserves stock and returns result

Each listener runs in its **declaring module's context** via `moduleWithContext()`, ensuring proper DB scoping, tenant isolation, and capability access.

### Wildcard Event Listeners

The EventBus supports wildcard patterns. Example:

```php
// modules/ecommerce/helpers/58-outbound-webhooks.php:420
app()->events()->listen('ecommerce.order.*', $webhookDispatcher, 10, 'ecommerce');
app()->events()->listen('ecommerce.product.*', $webhookDispatcher, 10, 'ecommerce');
```

A single listener handles `ecommerce.order.created`, `.paid`, `.shipped`, `.cancelled`, `.refunded`, etc.

### Event Declarations in module.json

Each module declares events in its manifest:

```json
// modules/wms/module.json
"events": {
    "emits": [
        "wms.stock.low",
        "wms.stock.movement.created",
        "wms.order.picked",
        "wms.order.dispatched",
        "wms.order.delivered",
        "wms.order.payment_collected",
        "wms.production.completed",
        "wms.delivery.received",
        "wms.cycle_count.completed",
        "wms.return.restocked"
    ]
}
```

```json
// modules/ecommerce/module.json
"events": {
    "emits": [
        "ecommerce.order.created",
        "ecommerce.order.paid",
        "ecommerce.order.shipped",
        "ecommerce.order.refunded",
        "ecommerce.order.cancelled",
        "ecommerce.return.requested",
        "ecommerce.return.approved",
        "ecommerce.return.rejected"
    ]
}
```

### Cross-Module Capability Policies (module.json)

Modules declare which other modules may call their capabilities:

```json
// modules/wms/module.json
"capabilities": {
    "exposes": {
        "wms.stock.reserve@1": { "policy": { "allow_callers": ["wms", "ecommerce", "kernel"] } },
        "wms.order.create@1":  { "policy": { "allow_callers": ["wms", "ecommerce", "kernel"] } }
    }
}
```

This means `ecommerce` is explicitly authorized to call WMS capabilities — proven cross-module capability sharing.

---

## Proof 4: Module Development Is Fully Documented and CMS-Agnostic

### Primary Module Development Guide

**File**: `docs/kernel/module-development-guide.md` (~1,285 lines)

This is the **authoritative reference** for building any module. It covers:

| Section | Content |
|---------|---------|
| Module file structure | Required files, directory layout, template pairing |
| `module.json` schema | 25+ fields documented: `id`, `name`, `version`, `depends`, `owns_tables`, `reads_tables`, `co_owns_tables`, `auth_owned` (12 sub-fields), `capabilities` (exposes/depends/policy), `nav`, `type`, `events`, `settings_fields`, `entity_views`, `service`, `entry_module` |
| Capability contracts | How to declare `exposes` and `depends`, handler function naming (`{prefix}_capability_handlers()`), version immutability, pipeline capabilities, policy-based access control |
| Route format | Nested `'GET' => ['/path' => 'handler']` format (mandatory — inline `'GET /path'` is silently ignored) |
| Entity views | Declaring `entity_views` in module.json, DiSyL view contracts, `builtinDefaults`, `{ikb_entity_list}` and `{ikb_entity_detail}` tags |
| Auth-owned modules | Full 12-item checklist: `auth_owned` schema, user table, password reset table, `auth_cookie`, `kernel.auth.authenticate@1` pipeline handler, login page/form/forgot/reset, migrations, `registerAuthTable()` |
| Service modules | Polyglot modules with `service` field: `endpoint`, `protocol`, `timeout_ms`, `retry`, `circuit_breaker` |
| Multi-tenant standards | Module types (Independent/Sub-module/Shared), settings rule, GLOBALS caches, sub-module install registry, cross-tenant adoption, internal metadata keys, file I/O rules |
| Performance | Per-request static caching (REQUIRED — 3 patterns), connection pooling, query patterns, fast-path endpoints |
| Packaging | Kernel/application modules vs CMS sub-modules, ZIP upload flow |

**CMS is mentioned only in the "Packaging" section** (for CMS sub-modules uploaded via ZIP). The entire module architecture section is CMS-agnostic.

### 30-Minute Quickstart

**File**: `docs/kernel/module-quickstart.md` (~400 lines)

Walks through building a "Notes" module from scratch — a standalone, non-CMS module. Covers:

1. Creating `module.json` with `auth_owned`
2. Writing migrations for `notes_users` and `notes_password_resets`
3. Implementing `kernel.auth.authenticate@1` pipeline handler
4. Creating login/forgot/reset templates in DiSyL
5. Adding routes in `routes.php`
6. Building a notes CRUD entity with `entity.list/get` capabilities
7. Rendering with `{ikb_entity_list}`

**Zero CMS dependency.** The quickstart module is fully standalone.

### Cross-Module Communication Playbook

**File**: `docs/kernel/cross-module-playbook.md` (~250 lines)

A decision tree for module-to-module communication:

| Question | Answer | Mechanism |
|----------|--------|-----------|
| "Do I need a result right now?" | Yes → | **Capability** (`app()->cap()->call()`) |
| "Did something just happen?" | Yes → | **Event** (`app()->events()->fire()`) |
| "Should the reaction be configurable?" | Yes → | **Trigger** (DB-managed event→capability mapping) |
| "Always react to this event?" | Yes → | **Listener** (`app()->events()->listen()`) |
| "Extend the UI of another module?" | Yes → | **Hook** (`app()->hooks()->apply()`) |

### Architecture Decisions

| ADR | Topic |
|-----|-------|
| `ADR-001-module-communication.md` | All cross-module communication via capability bus; no direct table/function access |
| `ADR-003-reads-tables-alongside-capabilities.md` | `reads_tables` for SELECT-only cross-module access |
| `ADR-004-python-first-polyglot-provider.md` | Python as first non-PHP capability provider |

### Skills (AI-assisted development rules)

| Skill | What it enforces |
|-------|-----------------|
| `module-creation.md` | Scaffold checklist, auth-owned modules, CSRF, password reset |
| `module-boundaries.md` | Never bypass kernel contracts; use `module()->db()`; tenant scoping |
| `domain-events.md` | Event emission after state changes; listener registration |
| `entity-view-system.md` | Entity view pipeline, cell renderers, action wiring |
| `service-layer-patterns.md` | `ServiceResult` contract, transaction discipline |
| `approval-workflow.md` | Multi-state approval state machine |
| `financial-immutability.md` | Reversal/void/adjustment patterns |

---

## Proof 5: Diverse Module Archetypes

The codebase demonstrates **five distinct module archetypes**, proving the kernel is not a one-size-fits-all CMS:

### Archetype 1: Auth-Owned Business Application
**Examples**: `daily-ledger`, `attendance-wage`, `guidance`, `wms`, `bakeshop`, `project-audit-ledger`

Characteristics:
- Own `users` and `password_resets` tables
- `auth_owned` in module.json with cookie, role, login path
- Exposes `kernel.auth.authenticate@1` as a pipeline capability
- Has its own admin shell layout (DiSyL `layouts/app.disyl`)
- 14–40 database tables
- 12–40 SQL migrations
- 15–35 DiSyL templates
- Registers `kernel.home_url` hook for role-based redirect

### Archetype 2: Polyglot Service Module
**Example**: Python/Node/Go services

Characteristics:
- `service` field in module.json: `endpoint`, `protocol`, `timeout_ms`
- Exposes capabilities that proxy to the external service
- No own auth — relies on kernel's capability-based access control
- Circuit breaker, retry, health check built-in

### Archetype 3: Infrastructure Service Module
**Examples**: `anti-spam`, `security`, `sms`, `search`, `tinymce`, `ai`

Characteristics:
- Provides cross-cutting capabilities consumed by other modules
- No own auth or user-facing pages (or minimal admin pages)
- `capabilities.policy.allow_callers` restricts which modules can call
- Often has their own DB tables for logs/state

### Archetype 4: CMS Sub-Module
**Examples**: CMS plugins installed via ZIP

Characteristics:
- Lives inside `modules/cms/` or references CMS capabilities
- May have `.cms-owned` marker
- Uses CMS admin shell for settings

### Archetype 5: Tenant Entry / Host Module
**Examples**: `bakeshop` (can serve as tenant entry point)

Characteristics:
- `entry_module` flag in module.json
- Hosts the tenant's public-facing routes
- Owns the shell layout for the tenant

---

## Proof 6: CLI Scaffolding for Any Module Type

The `php ikabud` CLI provides architecture-aware scaffolding that works for all module types:

```bash
# Full module scaffold (auth-owned, with migrations, handlers, templates)
php ikabud make:module <name>

# Entity scaffold (migration + capability handlers + entity views)
php ikabud make:entity <name>

# Capability scaffold (handler + manifest registration)
php ikabud make:capability <id>

# Service module scaffold (polyglot: Python/Node/Go)
php ikabud make:service-module <name>

# SQL migration
php ikabud make:migration <module> <name>

# Route handler
php ikabud make:handler <module> <fn> <METHOD>

# Architecture validation
php ikabud architecture:check           # Full audit
php ikabud module:check-boundaries      # Module boundary integrity
php ikabud entity:describe <entity>     # Entity schema introspection
php ikabud capability:trace <cap>       # Capability dependency trace
php ikabud trigger:trace <event>        # Trigger chain trace
php ikabud disyl:inspect <file>         # DiSyL AST inspection
```

None of these commands are CMS-specific. `make:module` scaffolds a standalone auth-owned module by default.

---

## Proof 7: Entity View System — Declarative Rendering for Any Module

The Entity View system (`{ikb_entity_list}` / `{ikb_entity_detail}`) is a kernel-level rendering engine used by **multiple non-CMS modules**:

| Module | Entities with Entity Views |
|--------|---------------------------|
| **CMS** | `cms_content`, `cms_media`, `cms_user`, `cms_category`, `cms_menu` |
| **attendance-wage** | `attendance_record`, `employee_profile`, `payroll_period`, `salary_computation`, `cash_advance`, `employee_deduction`, `holiday` |
| **daily-ledger** | `daily_ledger_entry`, `production_movement`, `delivery`, `product`, `branch` |
| **guidance** | `guidance_case`, `guidance_appointment`, `guidance_user` |
| **bakeshop** | `bakeshop_product`, `bakeshop_ingredient`, `bakeshop_delivery` |
| **wms** | `wms_product`, `wms_stock`, `wms_order`, `wms_delivery`, `wms_supplier` |

### How It Works

1. Module declares entity views in `module.json` or in `helpers/views/*.disyl`
2. Module exposes `entity.list.{entity}@1` and `entity.get.{entity}@1` capabilities
3. Template uses `{ikb_entity_list source="entity.qualifier" view="table"}`
4. At render time: `EntityViewResolver` → `CapabilityBus` → module's handler → returns `['rows' => [...], 'total' => N]` → `DefaultEntityRenderer` → HTML

The kernel ships `builtinDefaults` (card_grid, table, compact, detailed, summary) that work without any view contract. Modules can also define custom view contracts in DiSyL.

---

## Proof 8: Operational Validation — What's Been Tested and What Hasn't

*Honest assessment of where the kernel has been battle-tested and where the gaps remain.*

### 8.1 Failure Containment — ✅ Tested, Proven

**Cross-module failure isolation is explicitly tested and passes.**

**Stress test Scenario 2** (`tests/stress_architecture_test.php` — 800+ lines, 8 scenarios, 56+ assertions):

> **"Cross-Module Event Chain Failure Isolation — 5/5 PASS"** — A poisoned event listener throws an exception → order still succeeds, healthy listeners still fire, exception is logged. The event bus catches and isolates per-listener failures.

**Stress test conclusion** (`docs/evaluations/stress-and-load-test-findings-2026-04-16.md`):

> **Zero data corruption** across all stress scenarios. Oversell prevention, tenant isolation, and cross-module failure isolation all hold under pressure.

**Post-commit regression findings** (`docs/reviews/2026-04-14-multistore-regression-findings.md`):

After a commit that broke 11 of 121 tests, the analysis confirmed:
- "No DDL from modules, no tenant isolation breaches"
- "Cross-module calls guarded with `function_exists()` (CMS→ecommerce)"
- "Database access through `ecDb()` (ModuleDB), not raw `app()->db()`"
- "Cross-module writes via `moduleWithContext()` escalation"

**Circuit breaker** (`tests/capability_circuit_breaker_test.php` — 162 lines):

Full 3-state circuit breaker (closed → open → half-open) with test coverage:
- Failed calls pass through before threshold (5 failures)
- 5th failure trips breaker open → subsequent calls blocked with `CapabilityCallException` ("circuit open")
- After cooldown (30s), half-open allows one probe request
- Probe succeeds → breaker closes, subsequent calls work normally
- Superadmin observability API exposes per-service circuit breaker state at `src/http/superadmin-observability-handlers.php:431`

**Kernel hardening release** (`docs/releases/release-notes-2026-04-01-kernel-hardening.md`):

- **Tenant Module Settings Firewall**: Blocked unintentional multi-tenant data bleed by rejecting global fallback reads/writes when tenant resolution fails
- **Request Context Helpers**: Migrated raw kernel globals to `kernel_request_context_*` API for clean per-request isolation

**System audit confirmation** (`docs/reviews/system-review-2026-07-05.md`):
> "Multi-tenancy — TenantResolver + per-tenant DB isolation enforced at ModuleDB level. Fail-closed behavior verified in chaos tests."

### 8.2 Benchmarks & Load Testing — ✅ Comprehensive

**HTTP load test** (`tests/load_test.php` — ~1,150 lines):

A `curl_multi`-based concurrent user simulator with:
- **6 profiles**: `storefront`, `api`, `mixed`, `multitenant`, `multitenant-assert` (tenant isolation verification), `checkout` (sequential shopping journey)
- **Concurrency ramp**: Tests at 1, 5, 10, 25, 50 concurrent users with 50 requests each
- **Tenant isolation assertions**: Verifies no tenant's error rate or P95 latency deviates more than N× from the median peer
- **Tenant-aware routing**: Pre-flights candidate paths per tenant host, builds weighted endpoint catalogs (70% native, 30% fallback)

**Throughput measurements** (`docs/evaluations/stress-and-load-test-findings-2026-04-16.md` — 830 lines):

| Metric | Value |
|--------|-------|
| Throughput ceiling (single server) | 3.8–4.1 req/s |
| Throughput after optimizations | 7.8 req/s (+100%) |
| Cumulative storefront throughput gain | +541% (from baseline to fully optimized) |
| Bluehost shared hosting projection | ~1.5–3 req/s effective |
| Stress test scenarios | 8 scenarios, 56+ assertions, all pass |
| Stress test result | Zero data corruption |

**Performance optimization layers** (`docs/evaluations/performance-optimization-summary-2026-04-16.md` — ~220 lines):

7 measured optimization layers: catalog query cache (70–187× speedup), page-level output cache, DiSyL extends cache, stock gate, OPcache, APCu, fast-path pre-bootstrap cache

**DiSyL micro-benchmark** (`scripts/benchmark-disyl.php`):

12 scenarios (renderString, processControlStructures, resolveValue, buildOutputCacheKey, etc.) tested at 3,000 iterations × 5 samples with warmup. Outputs µs/operation with median/mean/min/max. Supports `--json` for CI.

**Kernel critical path benchmark** (`tests/kernel_load_test.php`):

6 benchmarks: parseSource, viewContract, renderString, capabilityHas. **22ms for 100 iterations** (reported in Kernel 6.0 release notes).

**Workbench competitive benchmarks** (`kernel/Workbench/Benchmark/`):

- `CompetitiveBenchmark.php` — evaluates pattern classifier against golden corpus
- `AiCalibrationBenchmark.php` — measures deterministic recall, AI citation validity, false-positive rate, top-3 root cause accuracy
- `tests/workbench_competitive_phase6_test.php` — spawns 12 concurrent `proc_open` workers, verifies index lock prevents lost concurrent run summaries

**Concurrency tests beyond HTTP:**

| Test | Mechanism | What it validates |
|------|-----------|-------------------|
| `tests/stress_architecture_test.php` Scenario 1 | 20 concurrent stock decrements on 8 stock | Exactly 8 succeed, 12 rejected, final stock = 0 |
| `tests/migration_advisory_lock_test.php` | MySQL `GET_LOCK`/`RELEASE_LOCK` | Concurrent migration execution is prevented |
| `tests/workbench_competitive_phase6_test.php` | 12 `proc_open` workers writing to RunRepository | Index lock prevents lost concurrent writes |
| `tests/disyl_v45_async_test.php` | DiSyL async runtime with 64-fiber cap | Scheduler rejects >64 fibers, promise chaining works, determinism verified |

### 8.3 Migration History — ✅ Documented Across 7 Major Versions

**Full kernel version timeline:**

```
3.1.0 (pre-March 2026)  →  kernel hardening, tenant firewall          (April 1)
3.2.0 "lattice"         →  docs hygiene, bakeshop graduation          (May 8)
4.0.0 "atlas"           →  BREAKING: final classes, co_owns_tables    (May 8)
4.1.0 – 4.6.0           →  DiSyL feature arc ({match}, types,         (May 8 batch)
                            {cache}, sandbox, async, federation)
5.0.0 "nexus"           →  entity-view architecture, governed         (June 7)
                            components, polyglot services
6.0.0 "ecosystem"       →  module scaffold, developer SDK,            (June 7)
                            certification, 22 superadmin APIs
6.1.0 "intercoherence"  →  13 CLI tools, Fibers async, compiled       (June 26)
                            mode default, DiSyL LSP extension
```

**14 release notes files** in `docs/releases/` covering every version.

**Kernel 4.0.0 BREAKING changes with documented migration paths:**

| Change | Migration Instruction |
|--------|---------------------|
| Core classes made `final` (`App`, `Hooks`, `EventBus`, etc.) | "Compose, do not extend. Use the documented capability + hook APIs." |
| `_kernel_db_unguarded` removed | Use `KernelPDO::kernelEscalationEnter()` / `kernelEscalationLeave()` |
| `modules/wordpress-importer/` removed | Run cms-wordpress-importer package installer |
| `Grammar.php` → `Grammar/Planned.php` | Migrate namespace references |

**Entity-view adoption plan** (`docs/kernel/entity-view-adoption-plan.md`) — tracks 9 modules migrated to entity-view architecture:

| Module | Status | When Migrated |
|--------|--------|---------------|
| CMS | ✅ Full (13 contracts, 5 types) | Phase 1-2 |
| Ecommerce | ✅ Full | Phase 2 |
| Bakeshop | ✅ Full | Phase 2 |
| Daily Ledger | ✅ Full | Phase 2 |
| WMS | ✅ Full | Phase 2 |
| Guidance | ✅ Full (POC) | June 21, 2026 |
| Attendance & Wage | ✅ Full (11 explicit DiSyL files) | June 24, 2026 |
| Project Audit Ledger | ✅ Full (10 DiSyL contracts) | Post-June 24, 2026 |
| Weather (polyglot) | ✅ Full (ServiceProxy→Python) | Phase 8 |

**Production upgrade path** (`docs/kernel/installation.md:83-125`):

The `create-bluehost-upgrade-package.php` script generates upgrade SQL bundles (`db/app-upgrade.sql`, `db/control-upgrade.sql`, `db/tenant-upgrade.sql`) plus a `README-UPGRADE.txt`. The documented live-upgrade order is: backup → generate upgrade kit from exact commit → import SQL into each DB → upload bundled app ZIP → preserve `.env`/`storage`/`uploads` → test.

**Guidance module conversion** (`docs/kernel/guidance-module-conversion.md`):

Standalone guidance app → freemium module with Free/Pro tier split and capability gating. Full rewrite pattern (not incremental patching). First module to implement explicit tier gating.

### 8.4 External Developer Gap — ❌ Not Yet Proven

**Honest assessment: This is currently a solo-developer project.**

Evidence:
- **`CODEOWNERS` does not exist.** `.github/BRANCH-PROTECTION.md` explicitly states: "Require approvals: 1 — Solo maintainer — self-approval is acceptable with CI gate" and "Consider requiring 2 approvals once team size > 1"
- **`CONTRIBUTING.md`** exists (practical setup, PR checklist, test workflow) but addresses a solo workflow
- **All module.json `author` fields** point to one of three labels: "Noah C. Omamalin" (13 modules), "Ikabud Kernel Team" (12 modules), "Ikabud Platform Team" (18 modules), "Ikabud" (3 modules), or placeholders ("Test Author", "GitHub Copilot")
- **No GitHub Issues templates, no SECURITY.md, no FUNDING.yml, no SUPPORT.md**
- **Contributor onboarding is rated "Medium to High" difficulty** by the independent technical evaluation (`docs/evaluations/ikabud-kernel-technical-evaluation-2026-04-10.md:298`)
- **Zero evidence of a second human developer** in any commit, module, or doc

**What IS in place for future contributors:**

| Asset | Status | Purpose |
|-------|--------|---------|
| `LICENSE-COMMERCIAL` §4 (CLA) | ✅ Exists | "By submitting code... you grant Ikabud a perpetual, worldwide, royalty-free license..." |
| `LICENSE-MIT` | ✅ Exists | Community Edition components — kernel contracts, DiSyL engine, community modules |
| `LICENSING.md` | ✅ Exists | Complete component-to-license mapping. "Build modules against MIT-licensed `kernel/Contracts/`. Your modules are yours." |
| `CONTRIBUTING.md` | ✅ Exists | Setup, PR checklist, MySQL 5.7 compat, test workflow |
| `docs/kernel/contributor-workflows.md` | ✅ Exists | "Reading Order for New Contributors" — 7 files, key concepts, CI tenant coverage (3 tenants), common change workflows |
| `docs/kernel/module-quickstart.md` | ✅ Exists | 30-minute tutorial — builds a standalone Notes module from scratch |
| `docs/kernel/module-development-guide.md` | ✅ Exists | 1,285-line authoritative reference |
| `docs/kernel/cross-module-playbook.md` | ✅ Exists | Decision tree for module-to-module communication |
| `docs/kernel/polyglot-service-guide.md` | ✅ Exists | Python/Node/Go service wire protocol with circuit breaker, retry, timeout |
| `docs/evaluations/third-party-verification-guide.md` | ✅ Exists | "Target: Independent evaluators, auditors, new developers" |
| CLI scaffolding (`make:module`, etc.) | ✅ Exists | Architecture-aware scaffolding for all module types |
| `extensions/disyl-lsp/` | ✅ Exists | VS Code extension v1.1.0 — syntax highlighting, hover, go-to-def, diagnostics, autocomplete for `.disyl` files |
| `kernel/Workbench/Extensions/` | ✅ Exists | Extension SDK for modules to self-register with ARK Workbench testing framework |
| `kernel/Workbench/` | ✅ Exists | Full automated testing/analysis/verification engine (comprehension, benchmarks, scenarios, evidence normalization) |

### 8.5 What Would Close the Gap

The architecture is sound, but the following would transform "brilliant but under-validated" into "safe to bet a business on":

| Gap | What Would Prove It |
|-----|---------------------|
| No post-mortem of a real production failure | Publish a case study where a module crashed without affecting other tenants/modules |
| Throughput tested only to 50 concurrent users | Publish load-test results at 200+ concurrent, multi-tenant, with P99 latency breakdowns |
| No evidence of external developers | Get one module built by someone who didn't create the kernel, using only the docs |
| Migration history exists but is solo-maintainer | Document a real production upgrade with 10+ modules running, including rollback plan |
| Bluehost is the only documented production target | Demonstrate the kernel on AWS, DigitalOcean, or a second hosting provider |

---

## Quick Reference: What a Module Looks Like

### Minimal `module.json`

```json
{
    "id": "my-module",
    "name": "My Module",
    "version": "1.0.0",
    "description": "A standalone business module",
    "depends": {
        "php": ">=8.1"
    },
    "owns_tables": [
        "my_module_users",
        "my_module_password_resets",
        "my_module_records"
    ],
    "migrations": [
        "001_create_users_table.sql",
        "002_create_records_table.sql"
    ],
    "auth_owned": {
        "table": "my_module_users",
        "username_column": "email",
        "password_column": "password_hash",
        "role_column": "role",
        "default_role": "user",
        "admin_role": "admin",
        "cookie": "my_module_token",
        "login_path": "/my-module/login",
        "home_path": "/my-module/dashboard",
        "supports_password_reset": true
    },
    "capabilities": {
        "exposes": {
            "kernel.auth.authenticate@1": { "pipeline": true, "priority": 550 },
            "entity.list.my_record@1": {},
            "entity.get.my_record@1": {}
        }
    },
    "events": {
        "emits": [
            "my_module.record.created",
            "my_module.record.updated"
        ]
    },
    "settings_fields": {
        "app_name": { "type": "text", "label": "Application Name", "default": "My Module" }
    }
}
```

### Minimal route file (`routes.php`)

```php
<?php
return [
    'GET' => [
        '/my-module/login'            => 'my-module:showLogin',
        '/my-module/dashboard'        => 'my-module:showDashboard',
        '/my-module/records'          => 'my-module:listRecords',
    ],
    'POST' => [
        '/my-module/login'            => 'my-module:handleLogin',
        '/my-module/logout'           => 'my-module:handleLogout',
    ],
];
```

### Minimal capability handler registration (`helpers.php`)

```php
<?php
function my_module_capability_handlers(): array
{
    return [
        'kernel.auth.authenticate@1'     => 'my_module_auth_authenticate',
        'entity.list.my_record@1'        => 'my_module_entity_list_my_record',
        'entity.get.my_record@1'         => 'my_module_entity_get_my_record',
    ];
}
```

### Minimal DiSyL template

```django
{extends "modules/my-module/layouts/app.disyl"}

{block content}
    <h1>{app_name}</h1>
    {ikb_entity_list source="my_module.my_record" view="table"}
{/block}
```

---

## Conclusion

The Ikabud Kernel OS is a **genuine modular application runtime**. The evidence:

1. **22+ modules** run on the same kernel, of which **7 are fully standalone business applications** with their own auth, database, and admin shell — none of which depend on CMS
2. **Cross-module communication is proven** — Ecommerce ↔ WMS with 11 bidirectional bridge definitions, capability policies explicitly authorizing cross-module calls, and module-context isolation for every listener
3. **Module development is fully documented** in CMS-agnostic guides: a 1,285-line development guide, a 30-minute quickstart, a cross-module communication playbook, and CLI scaffolding for all module types
4. **Five distinct module archetypes** demonstrate the kernel's flexibility: auth-owned apps, polyglot services, infrastructure services, CMS sub-modules, and tenant entry modules
5. **The CMS module is just one module** — it uses the same `module.json`, capability bus, event bus, and entity view system as every other module. The kernel has zero CMS-specific code paths.
6. **Operational validation is substantial but incomplete** — cross-module failure isolation passes stress tests with zero data corruption; circuit breaker is fully tested (3-state); throughput is benchmarked to 7.8 req/s with +541% cumulative gains; migration history spans 7 major versions (3.1→6.1) with documented breaking changes. The gap is real-world production evidence: no multi-developer team, no public post-mortems, no load tests beyond 50 concurrent users.

> **Bottom line**: If this were "just a CMS," you could not build a warehouse management system, a bakery operations platform, a guidance counseling case management system, a construction project audit ledger, or an attendance-and-payroll system on it — each with their own authentication, database schema, admin UI, and business logic — all running in the same tenant, communicating via a decoupled event bus. That is the definition of a modular runtime.

> **Honest assessment**: The architecture is sound enough to justify a deep dive. The risk isn't that it's a CMS in disguise — the risk is that it's a **well-architected but under-validated kernel** that needs real-world load, a multi-developer team, and production post-mortems to prove its isolation promises at scale. The pieces are all there; the operational track record is what's missing.
