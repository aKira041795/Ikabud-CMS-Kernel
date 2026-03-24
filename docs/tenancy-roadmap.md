---
description: Tenancy roadmap (separate DB per tenant + domain-based entry)
---

# Tenancy Roadmap (Separate DB per tenant, Domain/Subdomain Entry)

## Goals

- **One Application Kernel OS codebase** serving many tenants.
- **Tenant selection by full domain / subdomain** (preferred convention: full domain per client).
- **Hard data isolation**: **separate database per tenant**.
- **Module independence preserved**: modules communicate through **capability contracts only**.
- **Intuitive usage**: opening `https://client-domain.tld` (or `https://guidance.client-domain.tld`) lands in the correct module experience.

## Non-Goals (for initial implementation)

- Horizontal multi-region scaling.
- Cross-tenant analytics.
- Per-tenant plugin marketplaces.

---

# Core Architectural Decisions

## Decision A: Two-plane data model

### 1) Control Plane (shared)
A single, shared **control plane** stores tenant registry + provisioning metadata.

**Responsibilities**:
- Map `Host` -> `tenant_id`
- Store tenant DB connection info (or references to it)
- Store per-host “entry module” mapping
- Provisioning state (created, migrated, suspended)

### 2) Tenant Plane (isolated)
Each tenant has its own **tenant database** containing:
- Kernel tables that are tenant-scoped (recommended)
- Module tables (prefixed as today)

**Result**: “tenant isolation by construction”.

## Decision B: Kernel is the authority for tenant context
Tenant resolution and DB selection must be performed by the kernel before:
- routing
- capability calls
- authentication/session issuance

## Decision C: Multiple hostnames can map to one tenant
Support:
- `client-domain.tld` -> tenant `A`
- `guidance.client-domain.tld` -> tenant `A`
- `ledger.client-domain.tld` -> tenant `A`

This enables multiple “entry apps” (Guidance / Daily Ledger) without creating separate tenants.

---

# Tenant Identity & Resolution

## Canonical tenant identity
- `tenant_id` (integer) and/or `tenant_key` (stable string)

## Resolution inputs
- `HTTP_HOST`
- (Optional, future) `X-Forwarded-Host` when behind a trusted proxy

## Resolution outputs
- `tenant_id`
- `tenant_key`
- `tenant_status` (active/suspended)
- `entry_module_id` (optional)

## Failure behavior
- Unknown host -> 404 “Tenant Not Found”
- Suspended tenant -> 403/maintenance page

---

# Database Strategy (Separate DB per tenant)

## Connection selection
For each HTTP request:
1. Connect to **control DB** (or read cached mapping)
2. Resolve tenant by host
3. Select tenant DB connection based on tenant record
4. All subsequent `app()->db()` / connection pool operations use the **tenant DB**

## Recommended: kernel tables live in tenant DB
Keep kernel tables (users, audit, rate limiting, refresh tokens, etc.) inside the tenant DB.

**Benefits**:
- Single DB per request after resolution
- “Everything is tenant-scoped” naturally

## Control DB schema (minimum)
Proposed tables:
- `kernel_tenants`
  - `id`
  - `tenant_key`
  - `primary_domain`
  - `status`
  - `entry_module_id` (nullable)
  - `created_at`, `updated_at`
- `kernel_tenant_domains`
  - `id`
  - `tenant_id`
  - `domain`
  - unique index on `domain`
- `kernel_tenant_db_connections`
  - `id`
  - `tenant_id`
  - `db_host`, `db_name`, `db_user`, `db_pass_ref` (or encrypted)
  - `db_port`, `db_charset`
  - `created_at`, `updated_at`

## Secret handling
Avoid storing plaintext DB passwords where possible:
- Prefer environment variables / secret manager references (`db_pass_ref`)
- If storing encrypted, encryption key must be kernel-controlled

---

# Routing Model (Module-Direct Entry)

## Entry module mapping
Each hostname can optionally declare an `entry_module_id` (e.g. `guidance`, `daily-ledger`).

### Behavior
- Kernel continues to be the true entry point.
- Kernel’s `/` route uses `entry_module_id` to:
  - redirect to the module’s landing route, or
  - dispatch module landing handler.

### Invariants
- Modules do not own host-level routing.
- No module can override kernel routes.

---

# Module Enablement Model (Tenant vs Global)

## Initial model (implemented runtime behavior)

Modules remain globally installed at the codebase level, but tenant database provisioning is now entry-aware.

For tenant DB schema creation, the provisioner runs:

- tenant-safe kernel migrations
- the tenant's `entry_module_id`
- shared modules required by that entry module's declared capabilities / hook integrations

**Pros**:
- avoids polluting a CMS tenant DB with unrelated Daily Ledger / Guidance / Ticketing schema
- keeps globally shared integrations available where the entry module actually depends on them
- avoids a full per-tenant module registry for the current rollout

**Cons**:
- module availability is still global at the runtime/code level
- provisioning correctness depends on manifest-declared capabilities/hooks being accurate

## Later evolution (optional): Per-tenant activation

Add a control-plane registry such as `kernel_tenant_modules(tenant_id, module_id, enabled)`.

**Migration path**:
- Phase 1–3: keep global enablement.
- Phase 4+: introduce per-tenant enablement as an optimization and UI feature.
- Provisioning can migrate:
  - all modules (default), or
  - only enabled modules (optional later).

## Cross-tenant settings management (implemented)

A `superadmin` kernel role provides cross-tenant feature settings management without requiring tenant-context switching:

- **Superadmin settings page** (`/superadmin/settings?tenant_id=X`): presents a tenant picker, loads only modules relevant to the selected tenant (filtered by `entry_module_id` + CMS `_installed_submodules` whitelist), and persists changes to the target tenant's `tenant_module_settings` rows.
- **Tenant DB isolation**: cross-tenant helpers use `app()->dbForTenant($tenantId)` to connect to each tenant's own database. This is essential because tenants may have separate databases (e.g., `cmsmoduletwo` has its own `cmsmoduletwo` DB distinct from the main tenant DB). Connections are looked up from `kernel_tenant_db_connections` and cached per request.
- **Tenant-specific API helpers**: `readTenantModuleSettingsForTenant()`, `saveTenantModuleSettingsForTenant()`, `getModuleSettingsForTenant()`, and `isModuleEnabledForTenant()` all accept an explicit `tenant_id` parameter and connect to the target tenant's DB.
- **Relevance filtering**: tenants with an `entry_module_id` only see that entry module (and, for CMS tenants, installed sub-modules). Tenants without an entry module see all globally enabled modules.
- **Source-guarded access**: all superadmin endpoints verify both `role === 'superadmin'` and `source === 'kernel'`, preventing CMS-defined superadmin roles from accessing kernel settings.
- **Save API** (`POST /api/v1/superadmin/modules/settings`): requires `tenant_id` in multi-tenant mode, validates the tenant exists, and only allows changes to keys declared in the module's `settings_fields` manifest.

---

# Capability + Context Integration

## Tenant context propagation
Extend the request context to include `tenant_id`.

### Requirements
- Capability calls must have access to tenant identity via context.
- Audit log records must include tenant identity.

## Recommended new/updated kernel capabilities
- `kernel.tenant.resolve@1`
  - Input: `{ host, path }`
  - Output: `{ tenant_id, tenant_key, entry_module_id }`
- `kernel.tenants.list@1` (admin-only)
- `kernel.tenant.get@1` (admin-only)
- `kernel.tenant.provision@1` (admin-only)
  - Runs migrations on a tenant DB and validates module manifests

## Shared capability modules (e.g., SMS)
Shared capabilities must behave correctly per tenant:
- Read settings from the tenant DB
- If caching, cache by `tenant_id`

---

# Authentication & Session Binding

## Tenant-bound auth
Tokens/sessions must be bound to the resolved tenant:
- Include `tenant_id` (or `tenant_key`) in JWT claims
- Validate claim matches tenant resolved from host

## Cross-tenant token replay prevention
A JWT minted on tenant A must not work on tenant B.

---

# Operational Safety

## Disable/maintenance controls
- Control DB can mark tenant as suspended.
- Kernel shows maintenance page and blocks capability calls for that tenant.

## Auditability
- Provisioning events recorded in control DB
- Runtime audits recorded in tenant DB (audit_logs)
- Tenant provisioning must apply the tenant-safe kernel runtime artifacts, including `audit_logs`, workflow tables, and kernel event tables, in addition to entry-module migrations

## Predictable performance
- Host->tenant lookup cached in-process per request
- Optional external cache later (APCu/Redis) but not required for initial rollout

---

# Phased Implementation Plan

## Phase 0 — Documentation + Contracts (no runtime changes)
- Define:
  - tenant model
  - control DB schema
  - context propagation rules
  - error pages (tenant not found / suspended)

**Verification**:
- Domain mapping and DB selection flow reviewed end-to-end.
- Clear failure behavior documented (unknown domain / suspended tenant).

## Phase 1 — Control DB + Tenant Resolution
- Add control DB connection
- Implement host->tenant resolution
- Add domain mapping tables + indexes
- Add admin-only API to list/get tenants

**Verification**:
- Unknown domain returns 404 tenant-not-found page.
- Known domain resolves tenant in logs (include request_id + resolved tenant_id).
- Tenant suspension blocks early with a predictable response.

## Phase 2 — Per-request tenant DB switching
- After tenant is resolved, set App DB connection / pool to tenant DB
- Ensure module DB guardrails remain intact under tenant DB switching

**Verification**:
- Confirm `app()->db()` queries go to the tenant DB (not control DB) after resolution.
- Confirm module DB guardrails still block undeclared tables under tenant DB.
- Confirm shared services (e.g., SMS) read settings per tenant DB.

## Phase 3 — Tenant-bound auth
- Add `tenant_id` into JWT/session
- Enforce host/tenant binding at request start

**Verification**:
- Token minted on tenant A is rejected on tenant B (host mismatch).
- Login/logout/refresh flows continue working within the same tenant.

## Phase 4 — Entry module mapping
- Add `entry_module_id` per domain
- Implement kernel `/` behavior to load correct entry module experience

**Verification**:
- `client-domain.tld` lands on the configured entry module.
- `guidance.client-domain.tld` and `ledger.client-domain.tld` can map to the same tenant and open different entry modules.

## Phase 5 — Provisioning workflow
- `kernel.tenant.provision@1`:
  - create tenant DB
  - run tenant-safe kernel migrations only
  - run entry-aware module migrations (entry module + required shared integrations)
  - seed minimum data
  - validate module manifests

**Verification**:
- Provisioning creates an empty tenant DB, migrates only the schema required for that tenant experience, and enables first login.
- Provisioning is idempotent (safe re-run) and records outcome.

### Current implementation note

The current CLI provisioner now excludes control-plane schema from tenant DBs and avoids running unrelated module migrations for entry-scoped tenants such as CMS-only tenant databases.

## Phase 6 — Multi-host per tenant (optional)
- Multiple subdomains mapping to same tenant
- Per-host entry module overrides

**Verification**:
- Multiple hostnames resolve to the same tenant DB reliably.
- Host-specific entry module overrides behave predictably.

---

# Testing Strategy (MVP)

## Automated checks (recommended)

- Tenant resolution:
  - host parsing normalization
  - unknown host handling
  - suspended tenant handling
- DB switching:
  - correct tenant DB selected
  - control DB is not used for tenant reads/writes after switching
- Auth binding:
  - JWT includes tenant_id
  - tenant_id claim must match resolved tenant
- Entry module:
  - `/` landing reflects per-host entry module

## Manual smoke tests (shared hosting)

- Configure two hostnames pointing to the same codebase with two separate tenant DBs.
- Login on tenant A, confirm tenant B cannot reuse the token.
- Set different SMS settings per tenant, confirm each tenant sends using its own credentials.

---

# Open Questions

- Do we need a global, cross-tenant kernel admin user store in the control DB?
- Do we support per-tenant enabled/disabled modules, or is module enablement global?
- How do we handle email/SMS sender identity per tenant (branding/compliance)?

---

# Acceptance Criteria (MVP)

- Request to unknown domain returns tenant-not-found page.
- Request to known tenant domain uses the tenant’s DB.
- Logging and audit for tenant requests is written to tenant DB.
- JWT/session cannot be replayed across tenants.
- `guidance.client-domain.tld` and `ledger.client-domain.tld` can map to the same tenant DB and land in different module entry experiences.
