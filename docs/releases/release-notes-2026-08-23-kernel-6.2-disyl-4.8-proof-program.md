# Kernel 6.2 / DiSyL 4.8 — Proof Program

> **Released:** 2026-08-23
> **Theme:** Evidence-backed trust-boundary delivery on `origin/master` (`HEAD f4dadcb8`).
> **Previous:** [CMS Akira — Product Suite & Extension Architecture](release-notes-2026-08-05-cms-akira-product-suite.md)

---

## Executive Summary

This release is the delivered **Kernel 6.2 / DiSyL 4.8 proof-program batch**. It promotes the proof items that are now implemented, tested, and wired into verification lanes on master: CI architecture baselining, DiSyL 4.8 typed assignment strictness, durable transactional events, entity-view ownership defaults, DiSyL conformance/jurisdiction checking, coordinated provisioning, clean-room proofs, entity source schemas, workflow retention/provenance, signed service requests, capability authorization policy registry, aggregate perf gating, certification gating, and reproducible browser journeys.

**Delivered release facts:**
- architecture gate supports `--baseline`, `--fail-on-new`, and `--strict`, with semantic fingerprints and CI wiring
- DiSyL 4.8 typed `{set}` strict validation is live in interpreted + compiled runtimes
- `php tools/disyl-conformance-check.php` reports `promotion: promoted=41 partial=0` and `poc5: lane_green=YES`
- module certification is `69/69` (100%)

---

## What's New

### 1. Architecture gate + semantic fingerprints (POC 1)

Delivered command:

```bash
php ikabud architecture:check --baseline=FILE --fail-on-new --strict
```

Delivered evidence surface:
- semantic finding identity: `src/helpers/architecture-check-fingerprint.php`
- CI integration: `.github/workflows/ci.yml`
- tests: `tests/architecture_check_baseline_test.php`, `tests/architecture_check_fingerprint_test.php`

### 2. DiSyL 4.8 typed assignment + strict mode

Delivered syntax:

```disyl
{set name: string = expr}
```

Supported types:
- `string`
- `int`
- `float`
- `bool`
- `array`
- `json`
- `date`
- `datetime`
- `reference`

Delivered behavior:
- strict typed-assignment mode is opt-in and default-off
- interpreted + compiled runtimes both honor it
- runtime mismatch emits `[[DiSyL strict type mismatch: ...]]`
- mismatch also emits `disyl.strict.[strict]` in `app.log`
- `{math equation="..."}` is supported
- `{json_encode(...)}` / `{json_decode(...)}` remain supported from 2026-08-05

Files:
- `kernel/DiSyL/TemplateEngine.php`
- `kernel/DiSyL/Compiler/TemplateCompiler.php`
- `kernel/DiSyL/Types/TypeChecker.php`

### 3. Doc/test hygiene

Delivered hygiene updates:
- EBNF file `docs/disyl/disyl-grammar-v4.7.ebnf` carries marked v4.8 additions
- `tests/discover.php` separates offline vs canary discovery
- `--offline` default skips `*_canary_test.php` and `@canary`

### 4. Durable transactional events (POC 3)

Delivered eventing model:
- producer-owned outbox + consumer inbox
- MySQL 5.7-safe atomic lease-based claim
- idempotent consumption
- retry/backoff
- dead-letter support
- crash recovery via lease expiry sweep
- `EventBus::fireDurable()` joins the surrounding transaction

Files:
- `migrations/015_kernel_durable_event_outbox.sql`
- `src/helpers/durable-event-outbox.php`
- tests: `tests/durable_event_outbox_test.php`, `tests/durable_event_worker_test.php`, `tests/eventbus_durable_test.php`

### 5. Module-owned entity-view defaults (POC 4)

Delivered ownership move:
- `products` / `ecommerce_product` defaults now live in `modules/ecommerce/helpers/43-entity-views.php`
- kernel fallback remains structural-only and does not claim business entities, fields, or actions

### 6. DiSyL conformance / jurisdiction / promotion lane (POC 5)

Delivered proof surface:
- canonical inventory: `config/disyl-feature-inventory.json`
- 41 constructs classified as:
  - `declarative_core` = 19
  - `governed_extension` = 16
  - `compatibility_only` = 0
  - `prohibited_application_logic` = 6
- checker: `php tools/disyl-conformance-check.php`
- CI gate: `.github/workflows/ci.yml` `coding-standards` job
- bounded loops:
  - interpreted = `10000`
  - compiled = `100000`
- status-doc pattern: `docs/poc6-shadow-build-status.md`

### 7. Provisioning coordinator + clean-room proofs (POC 6)

Delivered coordinator:
- `tenantRunCoordinatedProvisionMigrations()`
- `tenantCasStatus()`
- state flow `pending → provisioning → active`
- failure returns to `pending`
- module becomes active only after migration + ownership verification

Files:
- `src/helpers/module-migrations.php`
- consumer paths: `kernel/Services/TenantProvisioner.php`, `ikabud`
- regression: `tests/provisioning_coordinator_test.php`

Delivered clean-room proofs:
- shadow build tool: `tools/poc6-shadow-build.php`
- artifact: `storage/poc6/proof-shadow-build-<head>.json`
- status doc: `docs/poc6-shadow-build-status.md`
- lifecycle proof: `tools/poc6-lifecycle-proof.php`
- artifact: `storage/poc6/proof-lifecycle-<head>.json`
- status doc: `docs/poc6-lifecycle-status.md`

### 8. Entity source schemas (Phase 2 #5)

Delivered behavior:
- `registerView()` accepts optional `source_schema`
- owner-sources-only gate
- owner defaults to provider
- cross-module default deny with `cross_module_approved` escape
- fixed type vocabulary
- field/type validation
- ecommerce adoption delivered
- kernel structural-only fallback preserved

Test:
- `tests/entity_source_schema_test.php`

### 9. Workflow retention & provenance (Phase 2 #6)

Delivered schema/helpers:
- migration: `database/migrations/023_kernel_workflow_retention.sql`
- helper: `src/helpers/workflow-retention.php`

Delivered behavior:
- canonical JSON payload hash
- immutable run-hash recording
- retain-provenance redaction
- full purge path
- retention-window redaction
- append-only transition insert

Test:
- `tests/workflow_retention_provenance_test.php`

### 10. ServiceProxy v2 + capability authorization registry (Phase 2 #7)

Delivered ServiceProxy v2:
- `kernel/Capabilities/ServiceProxyV2.php`
- canonical JSON with sorted keys, no whitespace, UTF-8, no trailing newline
- fixed signed-header set: method, path, host, body-hash, timestamp, nonce, kid, alg, endpoint, provider, capability, version
- RS256/ES256 allowlist
- timestamp skew ≤300s
- endpoint/provider/capability/version binding
- atomic nonce reservation in `nonce_reservations`
- duplicate replay rejection
- key-rotation overlap
- fail-closed on storage/key outage
- migration: `database/migrations/024_kernel_service_proxy_v2_nonce.sql`

Delivered authorization registry:
- `kernel/Capabilities/CapabilityAuthorizationRegistry.php`
- persisted versioned policy table `capability_authorization_policies`
- seeded `proof_lane.ping@1` policy version 2
- default-deny
- policy-version selection
- caching + `invalidate()`
- fail-closed on DB outage
- `capability.authz.decision` audit logging
- additive CapabilityBus wiring
- unregistered v1 capabilities unchanged
- v1 `ServiceProxy` never silently downgrades a v2-requiring capability
- migration: `database/migrations/025_kernel_capability_authorization_policies.sql`

Tests:
- `tests/service_proxy_v2_test.php`
- `tests/capability_authorization_registry_test.php`

### 11. Perf gate (Guardrail 8)

Delivered behavior:
- `tests/kernel_load_test.php` adds `--json`, `--baseline=FILE`, `--fail-on-delta=PCT`
- default textual output remains byte-identical
- standalone gate: `tools/perf-gate.php`
- baseline: `storage/perf-baseline.json` (gitignored)
- aggregate load-delta only; no per-request latency claim

Test:
- `tests/perf_gate_test.php`

### 12. Certification gate (Track 9)

Delivered state:
- `69/69` modules certified (`100%`)
- gate: `tools/module-certification-gate.php`
- rule: `≥90%` floor, `fail=0`, exit `0`

Test:
- `tests/module_certification_rate_test.php`

### 13. Browser journeys (PW-2 / PW-3)

Delivered green browser journeys:
- `tests/browser/builder-journey.spec.js`
- `tests/browser/report-approval-journey.spec.js`
- `tests/browser/async-rendering-journey.spec.js`

Delivered reproducible environment seed:
- `database/seeds/browser_environment.php`

Delivered supporting fixes/fixtures:
- report-approval routes in `modules/cms/routes.php`
- kernel-DB escalation fix in `modules/cms/handlers/78-import-export.php`
- async DiSyL fixture `modules/project-audit-ledger/templates/project-audit-ledger/pages/settings-async-rendering.disyl`

---

## Migration Notes

New additive-only migrations in this release:
- `migrations/015_kernel_durable_event_outbox.sql`
- `database/migrations/023_kernel_workflow_retention.sql`
- `database/migrations/024_kernel_service_proxy_v2_nonce.sql`
- `database/migrations/025_kernel_capability_authorization_policies.sql`

Operational notes:
- all four are additive release migrations
- MySQL 5.7 compatibility remains required
- existing v1 ServiceProxy paths remain present; v2 is enforced where the policy/handler requires it
- after deploying this kernel batch, run `php ikabud tenant:migrate <tenant_id|tenant_key|domain>` for each existing tenant so `023_kernel_workflow_retention.sql`, `024_kernel_service_proxy_v2_nonce.sql`, and `025_kernel_capability_authorization_policies.sql` are applied per-tenant
- until an existing tenant is re-migrated, the authorization registry treats an absent `capability_authorization_policies` table as "no policies": ungoverned v1 flows keep working, while v2-requiring capabilities default-deny

---

## Verification Evidence

| Area | Evidence |
|---|---|
| Architecture gate | `src/helpers/architecture-check-fingerprint.php`, `.github/workflows/ci.yml`, `tests/architecture_check_baseline_test.php`, `tests/architecture_check_fingerprint_test.php` |
| DiSyL 4.8 typed assignment | `kernel/DiSyL/TemplateEngine.php`, `kernel/DiSyL/Compiler/TemplateCompiler.php`, `kernel/DiSyL/Types/TypeChecker.php`, `tests/disyl_engine_test.php` |
| Durable events | `tests/durable_event_outbox_test.php`, `tests/durable_event_worker_test.php`, `tests/eventbus_durable_test.php` |
| Conformance lane | `config/disyl-feature-inventory.json`, `tools/disyl-conformance-check.php`, `tests/disyl_conformance_test.php` |
| Provisioning | `tests/provisioning_coordinator_test.php` |
| Shadow build | `tests/poc6_shadow_build_test.php`, `storage/poc6/proof-shadow-build-<head>.json`, `docs/poc6-shadow-build-status.md` |
| Lifecycle proof | `tests/poc6_lifecycle_test.php`, `storage/poc6/proof-lifecycle-<head>.json`, `docs/poc6-lifecycle-status.md` |
| Entity source schemas | `tests/entity_source_schema_test.php` |
| Workflow retention/provenance | `tests/workflow_retention_provenance_test.php` |
| ServiceProxy v2 | `tests/service_proxy_v2_test.php` |
| Authorization registry | `tests/capability_authorization_registry_test.php` |
| Perf gate | `tests/perf_gate_test.php` |
| Certification gate | `tests/module_certification_rate_test.php` |
| Browser journeys | `tests/browser/builder-journey.spec.js`, `tests/browser/report-approval-journey.spec.js`, `tests/browser/async-rendering-journey.spec.js`, `database/seeds/browser_environment.php` |

---

## Known Noise

Known non-blocking noise present in current verification lanes:
- PHP 8.5 deprecations in existing legacy/v1 paths (for example `ReflectionProperty::setAccessible`, `curl_close`)
- CMS dashboard warnings around ecommerce / `cms_user_services`
- `disyl.strict.keyof` warnings in CLI contexts

These are legacy/noise items, not new proof-program features.
