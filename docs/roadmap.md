---
description: Kernel OS (Community Edition) — Capability Contracts Roadmap
---

# Kernel OS — Roadmap

This roadmap defines the phased implementation plan for **Capability Contracts** and ecosystem stability in Kernel OS.

Guiding principles:

- **Contract-centric capabilities**: `payments.gateway.charge@1`
- **Multi-provider** supported from v1 (deterministic provider ordering)
- **Kernel core contracts are capabilities** under `kernel.*@1`
- **Hooks** are kernel-owned extension points
- **EventBus** is for best-effort side effects
- **Capabilities** are the sanctioned synchronous module-to-module request/response mechanism

---

## Phase 0 — Spec Lock (Required)

Outcome: a stable contract surface before implementation.

- Define capability ID format: `contract.id@major`
- Reserve namespaces:
  - `kernel.*` reserved for kernel-provided contracts
- Define multi-provider semantics:
  - provider ordering: `priority DESC`, then `provider_id ASC`
  - modes: `first`, `pipeline`, `fanout`
- Define enable-time validation rules:
  - module cannot enable if any required capability has no provider
- Define v1 stability policy:
  - breaking changes ship as `@2` capability versions

Acceptance criteria:

- Documented in `docs/module-development-guide.md` and this file

---

## Phase 1 — Capability Contracts MVP (v0.1)

Outcome: capabilities exist end-to-end with deterministic multi-provider selection.

Deliverables:

- Kernel services:
  - `CapabilityRegistry`
  - `CapabilityBus`
  - core exceptions (`CapabilityNotFound`, `CapabilityCallFailed`, etc.)
- Kernel registers core contracts (provider `kernel`):
  - `kernel.auth.user@1`
  - `kernel.auth.require@1`
  - `kernel.audit.record@1`
  - `kernel.http.request_context@1`
  - `kernel.render.context@1`
- Module registration hook point:
  - modules register their own capabilities during `kernel.boot`
- Manifest support:
  - `capabilities.exposes[]` (id, priority, modes)
  - `capabilities.depends[]` (list of required capability IDs)
- Enable-time validation:
  - verify required capabilities exist before module routes are active

Acceptance criteria:

- A module declaring `depends: ["kernel.auth.user@1"]` fails to enable if core contracts are not registered
- Multi-provider selection is deterministic under ties
- `fanout` mode isolates provider exceptions by default

---

## Phase 2 — Tooling + Safety (v0.2)

Outcome: contributors can see and validate the dependency graph.

Deliverables:

- CLI:
  - `baron capability:list`
  - `baron module:validate`
  - `baron module:graph`
- Improved diagnostics:
  - missing capability providers
  - version mismatch (wrong `@major`)
  - provider present but does not support requested mode

Acceptance criteria:

- Validation catches missing providers without running the app
- Graph output lists contract-centric edges: `module -> capability -> providers`

---

## Phase 3 — v1 Stable (v1.0)

Outcome: stable public API for the community ecosystem.

Deliverables:

- Published compatibility policy:
  - kernel SemVer + deprecation windows
  - stable list of `kernel.*@1` contracts
- Observability baseline:
  - request IDs propagated into capability calls
  - structured logs for capability failures
- Conformance tests:
  - deterministic provider ordering
  - missing provider blocks enable
  - handler exceptions isolated

Acceptance criteria:

- Documented invariants + test suite enforcement
- Community modules can target `kernel.*@1` without fear of silent breaking changes

---

## Phase 4 — Post-v1 Enhancements (v1.1+)

Outcome: ecosystem scaling features, added carefully.

Potential deliverables:

- Optional schema descriptors for capability payloads
- Multi-provider strategies beyond priority:
  - explicit provider pinning
  - weighted routing
- Advanced tenant strategies surfaced as contracts (if multi-tenant mode enabled)

Additional near-term (adoption-driven) deliverables:

- Control-plane tenancy readiness (host→tenant resolution, entry-module routing)
- Regression test suite for kernel hardening behaviors (hooks isolation, route conflict detection, module handler exception safety, CSRF enforcement)
- Standardize module web-session cookies via `auth_cookie` in `module.json` so kernel `app()->user()` works consistently across entry modules

### Runtime workflows (business automations)

Outcome: modules can model cross-module business workflows as a kernel-supported runtime primitive.

Deliverables:

- A workflow definition format (stored in DB or version-controlled JSON)
  - triggers: event key + filter predicates
  - steps: ordered capability calls with input mapping
  - guards: role/ACL, tenant constraints
  - idempotency: deterministic workflow run keys
- Execution engine:
  - enqueue + retry semantics
  - per-step timeout handling
  - failure modes: stop, compensate, continue
- Observability:
  - workflow run logs: workflow_id, run_id, triggering event, step status, duration, error
  - admin UI for runs + replay (admin-only)

Acceptance criteria:

- A workflow can be authored without writing PHP code
- Workflows can call capabilities across modules and produce an auditable run history
- Failures are visible and do not crash the originating HTTP request

### Capability Registry export (AI + tooling)

Outcome: the capability registry becomes machine-readable enough for scaffolding and AI-assisted module building.

Deliverables:

- Registry export endpoint (admin-only) returning a single canonical spec:
  - capability IDs, modes, provider ordering, policies
  - payload schema + return schema (lightweight JSON schema)
  - examples: valid payloads and typical responses
- CLI validation and codegen hooks:
  - validate a module manifest against required capability schemas
  - generate provider/client stubs from the registry spec

Acceptance criteria:

- A tool (or AI) can consume the registry export to generate a module skeleton with correct capability stubs

---

## Hardening Roadmap — App Reliability & Safety

Purpose: close operational and security gaps discovered during capability adoption.

### Hardening Phase 1 — Observability Baseline

Deliverables:

- Request ID generation and propagation (HTTP + capability calls)
- Structured log fields for: request_id, module, capability_id, provider
- Capability failure counts and p95 latency metrics (per capability/provider)

Acceptance criteria:

- Every request has a stable request_id across logs
- Capability logs include request_id and provider
- Metrics can surface top failing capabilities without code changes

### Hardening Phase 2 — Capability Security & Caller Context

Deliverables:

- Caller context attached to capability calls (module + user)
- Capability ACLs based on caller (allow/deny by module)
- Auditable security decision logs for denied capability calls

Acceptance criteria:

- A module can be denied from calling a capability even if a provider exists
- Denials are logged with capability_id + caller module

### Hardening Phase 3 — Reliability & Failure Semantics

Deliverables:

- Standard timeout handling for capability calls
- Retry policy (configurable per capability)
- Circuit breaker for repeated provider failures
- SMS provider failover strategy for `sms.send@1` (ordered fallback, no duplicate sends, auditable logs)
- Fallback behavior when provider set changes mid-request

Acceptance criteria:

- Capability call timeouts are enforced and logged
- Repeated failures degrade gracefully without crashing the request

### Hardening Phase 4 — Versioning & Schema Validation

Deliverables:

- Version resolution policy (highest compatible `@major` if not pinned)
- Optional schema descriptors for payload validation (lightweight JSON schema)
- CLI validation for schema compatibility and version mismatches

Acceptance criteria:

- Modules can declare compatible versions and resolve safely
- Invalid payloads are rejected before hitting provider handlers
