# Kernel Stable Contracts

## Purpose

This document distinguishes extension points that modules and external integrations can rely on from internals that may be reorganized during kernel refactors.

## Stable Contracts

The following should be treated as compatibility-sensitive.

### 1. Module manifest structure

The following manifest concepts are stable contracts:

- module identity fields such as `id`, `name`, `version`, and `type` (including `"type": "service-module"`)
- route and handler entry declarations
- `owns_tables` and `reads_tables`
- `co_owns_tables` for shared infrastructure tables
- migration and SQL artifact declarations
- capability `provides` and `requires`
- settings field definitions used by module settings UIs
- auth-cookie declarations used by kernel auth discovery
- `auth_owned` declarations for module-owned authentication
- `entry_module` tenant designation
- service endpoint and protocol declarations for polyglot service modules

Changing the meaning of these fields is a breaking platform change.

### 2. Route map conventions

These conventions are stable:

- route files remain declarative
- module handlers continue using `module-id:functionName`
- kernel-owned routing remains the gatekeeper for auth, tenant context, and dispatch

### 3. Capability IDs and payload contracts

Capability identifiers with version suffixes, for example `ecommerce.orders.tracking.sync@1`, are stable contracts.

Rules:

- do not change the meaning of an existing version in place
- add a new version when payload semantics change materially
- keep provider behavior compatible within a version

### 4. Hook and event names

Published hook and event names are compatibility-sensitive once used by modules or integrations.

Rules:

- do not rename hook or event identifiers casually
- do not silently remove event payload fields relied on by existing listeners
- prefer additive payload changes over destructive ones

### 5. Tenant and auth safety invariants

These behaviors are stable and must be preserved:

- fail-closed tenant DB behavior
- tenant-aware JWT rejection when multi-tenancy is enabled
- kernel-owned CSRF enforcement for browser-mutating routes
- centralized security-header application
- module manifest validation before load

### 6. Module settings and entitlement helpers

These helpers are effectively part of the platform surface while modules depend on them:

- tenant settings read/write helpers
- module enable/disable helpers
- entitlement and access-request helpers
- migration synchronization helpers used during provisioning and CLI flows

Internal implementation can move, but external behavior should stay stable during decomposition.

### 7. Entity context and authority contracts (Kernel OS 4.0+)

These contracts govern how entity types resolve to renderable views and how cross-module data ownership works:

- **EntityViewResolver** — `registerView()`, `resolve()`, `viewContract()` define how entity types map to renderable views (compact, full, card, table, timeline). View registrations and builtin defaults are compatibility-sensitive.
- **ContextRegistry** — `registerSchema()`, `registerProfile()`, `registerMode()`, `registerCapability()`, `bindEntityType()` define the entity context resolution pipeline.
- **EntityAuthorityRegistry** — `registerAuthority()` declares which module owns an entity type. Authority changes affect cross-module data ownership.
- **SyncContractRegistry** — `registerContract()` defines entity sync contracts between modules.

### 8. Polyglot service wire protocol (Kernel OS 5.0+)

The ServiceProxy protocol is a stable cross-language contract:

- `POST /capability/call` with JSON `{capability_id, payload, caller}`
- Response: `{"ok": true, "data": {...}}` or `{"ok": false, "error": "..."}`
- Service manifest: `"type": "service-module"`, `service.endpoint`, `service.protocol`, `service.auth.token_env`
- Circuit breaker and retry behavior is bus-managed, not service-managed

### 9. Governed DiSyL component contracts (Kernel OS 4.0+)

The 31 governed components registered via `ComponentRegistry::registerCoreComponents()`:

- Component names (`ikb_entity_list`, `ikb_stat_card`, `ikb_export_button`, etc.)
- Attribute schemas (props, types, defaults)
- Slot contracts (named slots and expected content)

### 10. Export pipeline contracts (Kernel OS 4.0+)

- `KernelExport::register($entityType, $format, $handler)` — handler registration
- Supported formats: `csv`, `docx`, `pdf`
- `ReportManager` template, archive, and scheduled report contracts

## Internal Implementation Details

The following can be reorganized as long as stable behavior remains unchanged:

- file placement of helper implementations
- service extraction from `kernel/App.php`
- front-controller helper extraction from `public/index.php`
- decomposition of `src/helpers/module-manager.php`
- caching strategy details that do not alter externally visible behavior

## Refactor Rule

When in doubt:

1. preserve IDs, names, and payload shapes
2. move implementation behind compatibility shims
3. update docs and tests before removing an old path

## Validation Expectations

Changes touching stable contracts should rerun:

- request dispatch integration coverage
- tenant isolation and fail-closed tests
- manifest and module settings defaults coverage
- any feature-specific bridge or module tests affected by the contract