# ADR-007: Protocol-First Host Portability

## Status

**Proposed (2026-10-19) — conditional.** This records the conclusion of a three-way architecture
review. It is a direction, not implementation approval. The conditions in
[What must be true before authority moves](#what-must-be-true-before-authority-moves) come first.

## Context

The question was: **why can't any language host the Ikabud kernel, and what exactly is the blocker?**

Ikabud is deliberately polyglot at the extension boundary. `module.json` supports
`"type": "service-module"`; `ServiceProxy` dispatches capabilities over HTTP+JSON; Python providers
already run in production (`modules/weather-service/`, `services/reporting/`); and entity views can be
declared in `module.json` with no PHP bridge code. The host, however, has remained PHP since
[ADR-001](ADR-001-php-as-kernel-host.md).

Three independent positions were taken on whether the host itself is portable:

- **Harness agent (measured from this repository):** the first blocker is the module fleet — 645 PHP
  files / ~256k LOC of PHP callables that cannot run on a non-PHP host.
- **Sol (`openai-codex/gpt-5.6-sol`):** the first blocker is unclear authority — nobody can state
  which observable PHP behaviours are contractual and which are accidental. The module fleet is the
  largest cost, not the first blocker.
- **ChatGPT (chair):** Sol wins the architectural argument; the harness position remains correct
  about migration economics. A new entry-point proxy is not automatically a new kernel host.

The chair's ruling is the basis of this ADR: **approve protocol-first portability, reject fleet-first
rewriting, and defer authority transfer until conformance is executable.**

## Verified evidence (2026-10-19)

| Fact | Measurement | Source |
|---|---|---|
| Kernel size | 303 PHP files / 81.7k LOC | `kernel/` |
| Support size | 32 PHP files / 26.2k LOC | `src/` |
| DiSyL runtime size | 96 PHP files / 27.3k LOC | `kernel/DiSyL/` |
| Module fleet size | 645 PHP files / 256k LOC | `modules/` |
| Modules / migrations | 46 modules / 418 migrations / 70 `module.json` | `modules/` |
| Route maps are executable PHP | `routes.php` returns `['GET' => ['/...' => 'module:handler']]` | `modules/daily-ledger/routes.php` |
| Handlers are PHP callables | auto-loaded from `handlers.php` / `helpers.php`, invoked via `is_callable()` | `src/helpers/module-routes.php:370-387` |
| Manifest points at PHP | `"routes": "routes.php"` | `modules/daily-ledger/module.json` |
| Polyglot providers are DB-less by rule | services must not receive database credentials or kernel secrets | `docs/kernel/polyglot-service-guide.md:18,620` |
| Polyglot runtimes supported | Python, Node.js, Go, Rust, Ruby, anything HTTP+JSON | `docs/kernel/polyglot-service-guide.md:4` |
| Declarative entity sources | `entity_sources` in `module.json`, no PHP bridge | `docs/kernel/polyglot-service-guide.md:347-351` |
| Capability schemas are catalogued | per-capability `schema` exposed | `kernel/Capabilities/CapabilityCatalog.php:256` |
| OpenAPI is route-level | routes + module fragments, not capability contracts | `kernel/Services/OpenApiGenerator.php` |
| ServiceProxy v2 binds tenant | signed `tenant_id`, endpoint, provider, capability, version, body hash; RS256/ES256 + nonce | `kernel/Capabilities/ServiceProxyV2.php:148,154,169` |
| Atomicity is undocumented | targeted grep found no `beginTransaction` in capability dispatch; the polyglot guide has no atomicity/idempotency section | `kernel/Capabilities/`, `kernel/App.php`, `src/helpers/module-manager.php`, `docs/kernel/polyglot-service-guide.md` |
| Stable contracts are declared | manifest, routes, capability IDs, hooks/events, tenant/auth invariants | `docs/kernel/kernel-stable-contracts.md:7-27` |

Two conclusions follow directly:

1. **The capability protocol is already language-neutral.** A provider in any language can implement a
   bounded capability today.
2. **The host's authority is not yet portable as evidence.** The mechanisms exist, but their observable
   equivalence across runtimes has never been tested. `kernel-stable-contracts.md` states *what* is
   stable; it does not state *how* another implementation reproduces it.

## Decision

1. **Treat Ikabud as protocol-first.** Language neutrality is a property of contracts, not source code.
   The kernel's durable asset is the capability/tenant/auth/audit contract, not the PHP implementation.
2. **Reject fleet-first rewriting.** The 645-module, 256k-LOC migration is a cost, not a prerequisite.
3. **Permit a gateway plus a bounded PHP sidecar as scaffolding.** The sidecar must have independent
   health, deployment, versioning, observability, and an explicit ownership boundary. A sidecar with
   unrestricted database credentials and its own tenant decisions is a distributed monolith, not an
   architecture.
4. **Defer authority transfer until conformance is executable.** A proxy that still asks PHP for every
   authoritative decision is a gateway, not a host.
5. **Do not port DiSyL yet.** Rendering stays host-side. Standardize entity-view *semantics* only if a
   concrete deployment requires rendering to cross runtimes.
6. **Define the decisive milestone as one capability, not a platform.** A non-PHP host must make the
   authoritative tenant/capability decision for one capability, with isolated state and durable
   recovery. PHP may veto malformed, expired, unauthorized-target, or operationally unsafe execution.
   It must not reinterpret identity or policy.

## Revised blocker ranking

The three-way review replaced the original ranking. The first blocker is **unproven authority
equivalence under distribution**, not the language or the module count.

1. **Contractual authority and conformance** — which observable behaviours must remain invariant?
2. **Transactional and operational ownership** — who commits, recovers, migrates, rotates keys, and
   revokes access?
3. **Legacy execution dependency** — which PHP routes cannot be delegated at all?
4. **Persistence semantics** — which MySQL/InnoDB behaviours must survive (upserts, locking, collation,
   generated IDs, migration ordering)?
5. **Rendering compatibility** — DiSyL and entity views, only when rendering crosses runtimes.
6. **Deployment feasibility** — can the target infrastructure operate two runtimes safely? This is a
   day-1 go/no-go gate, not a late concern.

## What must be true before authority moves

A forwarding architecture must prove all of the following:

- Exactly one authoritative tenant identity, with no substitutable forwarding headers.
- One effective authorization decision, or explicitly defined defense-in-depth checks.
- Signed, short-lived, audience-bound internal requests with replay protection.
- PHP rejects direct bypass of protected entry points.
- Forwarding preserves route precedence, cookies, CSRF, redirects, uploads, and error semantics.
- Clear ownership of database transactions, migrations, tenant provisioning, key rotation, and
  recovery.
- No distributed atomicity is implied by an HTTP boundary.
- The sidecar has independent health, deployment, versioning, observability, and no unrestricted
  database credentials.
- The deployment target can run both runtimes reliably (persistent processes, ingress control, secrets,
  health management, deployment lifecycle).

## Ninety-day sequence

| Window | Work |
|---|---|
| Days 1–15 | Prove deployment and bypass feasibility on the real target. Inventory route-level state dependencies. Pick one low-risk, idempotent write. Define acceptance thresholds. |
| Days 16–35 | Specify Host Protocol v1 (request, identity, authority, transport, execution, state, operations) and build hostile conformance fixtures. |
| Days 36–60 | Implement the gateway plus one authoritative read. Test tenant isolation, authentication, and failure behaviour. |
| Days 61–90 | Transfer authority for the selected write. Isolate credentials and state ownership. Run replay, outage, key-rotation, audit, and recovery drills. |

## Falsifiers

Stop or redesign if any of these hold:

- The real deployment cannot enforce one non-bypassable authoritative boundary for the pilot capability.
  This is the single stop condition: without it, the result is only a gateway, regardless of
  conformance results.
- Tenant or authorization decisions diverge between the hosts.
- Duplicate requests produce duplicate financial effects.
- Recovery requires unsafe cross-runtime transactions.
- Compatibility requires extensive PHP semantic emulation.
- Operational cost exceeds an equivalent PHP-hardening solution.
- A second host cannot run reliably on the deployment target.

Conversely, a clean non-PHP read/write pilot falsifies the claim that fleet extraction must precede a
second host, but does not yet prove complete kernel portability.

## Open evidence gaps

These were not verified and must not be assumed:

- Conformance coverage of the existing capability protocol.
- Production latency and failure behaviour of cross-process capability calls.
- Ledger-invariant testing for remote writes under partial failure.
- Whether capability authorization decisions can be evaluated without database reads (the policy
  registry is DB-backed).
- The full set of load-bearing MySQL semantics beyond `ON DUPLICATE KEY UPDATE` and transactions.
- Buildability and CI coverage of the gitignored Android clients (a related but separate question).

## What this ADR does not decide

- It does not approve a second host, a kernel rewrite, or a module rewrite.
- It does not port DiSyL or any entity-view renderer.
- It does not create an offline authorization plane.
- It does not change [ADR-001](ADR-001-php-as-kernel-host.md): PHP remains the host.
- It does not make any current module a service module by decree.

## Review record

- **Date:** 2026-10-19
- **Participants:** harness agent (measured repository position), Sol (`openai-codex/gpt-5.6-sol`),
  ChatGPT chair.
- **Method:** three rounds. Sol replied to a measured brief; ChatGPT replied to both positions;
  Sol replied to the chair's ruling. All three converged on the decision above.
- **Transcripts (gitignored, `.gitignore:119`):**
  `.ai/consult/threeway-brief-2026-10-19.md`,
  `.ai/consult/threeway-sol-round1.md`,
  `.ai/consult/threeway-chatgpt-round2-2026-10-19.md`,
  `.ai/consult/threeway-sol-round2.md`.
