# Kernel 6.2 / DiSyL 4.8 — Proof-Program Promotion Report

> **Decision date:** 2026-08-23
> **Commit state assessed:** `origin/master` `HEAD f4dadcb8`
> **Purpose:** principle-by-principle promotion decision for the delivered proof program

---

## Promotion Summary

| Item | Status |
|---|---|
| POC 0 / net batch state | PROMOTED |
| POC 1 — architecture gate | PROMOTED |
| POC 3 — durable transactional events | PROMOTED |
| POC 4 — module-owned entity-view defaults | PROMOTED |
| POC 5 — DiSyL conformance/jurisdiction | PROMOTED |
| POC 6 — provisioning + clean-room | PROMOTED |
| Phase 2 #5 — entity source schemas | PROMOTED |
| Phase 2 #6 — workflow retention/provenance | PROMOTED |
| Phase 2 #7 — ServiceProxy v2 + authz registry | PROMOTED |
| Guardrail 8 — perf gate | PROMOTED |
| Track 9 — certification | PROMOTED |
| 4b — browser journeys | PROMOTED |
| POC 2 | N/A |

---

## Decision Detail

### POC 0 / Net batch state
- **Objective:** prove the 6.2 trust-boundary batch is delivered on master with evidence-backed verification.
- **Status:** **PROMOTED**
- **Key evidence:** `docs/releases/release-notes-2026-08-23-kernel-6.2-disyl-4.8-proof-program.md`, `.github/workflows/ci.yml`, `docs/kernel/kernel-os-disyl-roadmap-status.md`
- **Notes:** batch claim is limited to delivered, tested items only; no narrative release claim is implied.

### POC 1 — CI boundary gate + semantic fingerprint
- **Objective:** make architecture boundary drift measurable and CI-enforceable without line-number churn noise.
- **Status:** **PROMOTED**
- **Key evidence:** `src/helpers/architecture-check-fingerprint.php`, `.github/workflows/ci.yml`, `tests/architecture_check_baseline_test.php`, `tests/architecture_check_fingerprint_test.php`
- **Notes:** semantic identity is line-stable sha1; promotion claim is about the gate and fingerprinting, not about eliminating all historical debt.

### POC 2
- **Objective:** no 6.2 proof-program promotion claim was delivered for POC 2 in this batch.
- **Status:** **N/A**
- **Key evidence:** none in this increment's delivered fact set
- **Notes:** intentionally omitted rather than embellished.

### POC 3 — durable transactional events
- **Objective:** prove durable event publication/consumption across retry, crash, and MySQL 5.7 constraints.
- **Status:** **PROMOTED**
- **Key evidence:** `migrations/015_kernel_durable_event_outbox.sql`, `src/helpers/durable-event-outbox.php`, `kernel/EventBus.php`, `tests/durable_event_outbox_test.php`, `tests/durable_event_worker_test.php`, `tests/eventbus_durable_test.php`
- **Notes:** promotion is for producer-owned outbox + consumer inbox, lease claim, idempotency, retry/backoff, dead-letter, and crash recovery.

### POC 4 — module-owned entity-view defaults
- **Objective:** remove business default ownership from the kernel and return it to the owning module.
- **Status:** **PROMOTED**
- **Key evidence:** `modules/ecommerce/helpers/43-entity-views.php`, `kernel/EntityContext/EntityViewResolver.php`, `tests/entity_source_schema_test.php`
- **Notes:** kernel fallback remains structural-only by design.

### POC 5 — DiSyL conformance / jurisdiction / promotion lane
- **Objective:** define the blessed DiSyL surface, validate its jurisdiction, and prove interpreted/compiled parity.
- **Status:** **PROMOTED**
- **Key evidence:** `config/disyl-feature-inventory.json`, `tools/disyl-conformance-check.php`, `tests/disyl_conformance_test.php`, `.github/workflows/ci.yml`
- **Notes:** promotion lane reports `promotion: promoted=41 partial=0` and `poc5: lane_green=YES`.
- **Justified not-applicable / debt:** some constructs are intentionally `renderable:false` or `not_applicable` on specific LSP/resource-limit surfaces; those exclusions are explicit inventory/checker data, not gaps.

### POC 6 — provisioning + clean-room
- **Objective:** prove provisioning state coordination and clean-room lifecycle discipline.
- **Status:** **PROMOTED**
- **Key evidence:** `src/helpers/module-migrations.php`, `kernel/Services/TenantProvisioner.php`, `ikabud`, `tests/provisioning_coordinator_test.php`, `tools/poc6-shadow-build.php`, `tests/poc6_shadow_build_test.php`, `storage/poc6/proof-shadow-build-<head>.json`, `docs/poc6-shadow-build-status.md`, `tools/poc6-lifecycle-proof.php`, `tests/poc6_lifecycle_test.php`, `storage/poc6/proof-lifecycle-<head>.json`, `docs/poc6-lifecycle-status.md`
- **Notes:** lifecycle proof covers 21 steps including forbidden paths, durable-event-once, module toggles, pack/install/uninstall, and failed-install restoration.

### Phase 2 #5 — entity source schemas
- **Objective:** make entity-source ownership and field typing explicit at the view-registration boundary.
- **Status:** **PROMOTED**
- **Key evidence:** `kernel/EntityContext/EntityViewResolver.php`, `modules/ecommerce/helpers/43-entity-views.php`, `tests/entity_source_schema_test.php`
- **Notes:** owner defaults to provider; cross-module access is default-deny unless `cross_module_approved` is declared.

### Phase 2 #6 — workflow retention / provenance
- **Objective:** preserve immutable provenance while allowing policy-driven payload redaction/purge.
- **Status:** **PROMOTED**
- **Key evidence:** `database/migrations/023_kernel_workflow_retention.sql`, `src/helpers/workflow-retention.php`, `tests/workflow_retention_provenance_test.php`
- **Notes:** append-only transition insert is part of the promotion claim.

### Phase 2 #7 — signed service requests (ServiceProxy v2)
- **Objective:** make service-module requests replay-resistant, version-bound, and fail-closed.
- **Status:** **PROMOTED**
- **Key evidence:** `kernel/Capabilities/ServiceProxyV2.php`, `database/migrations/024_kernel_service_proxy_v2_nonce.sql`, `tests/service_proxy_v2_test.php`
- **Notes:** signed-header set, RS256/ES256 allowlist, nonce reservation, body-hash binding, timestamp skew, endpoint/provider/capability/version binding, and key-rotation overlap are all part of the promoted surface.

### Phase 2 #7 — capability authorization registry
- **Objective:** move capability authorization for governed capabilities into a persisted, versioned, default-deny registry.
- **Status:** **PROMOTED**
- **Key evidence:** `kernel/Capabilities/CapabilityAuthorizationRegistry.php`, `database/migrations/025_kernel_capability_authorization_policies.sql`, `kernel/Capabilities/CapabilityBus.php`, `kernel/Capabilities/ServiceProxy.php`, `tests/capability_authorization_registry_test.php`
- **Notes:** v1 unregistered capabilities remain legacy-unchanged; v1 `ServiceProxy` refusal to silently downgrade a v2-required capability is explicitly promoted.
- **Justified legacy debt:** unregistered legacy v1 capabilities remain outside the new registry by additive design.

### Guardrail 8 — performance gate
- **Objective:** add an evidence-backed aggregate performance regression gate without changing default load-test output.
- **Status:** **PROMOTED**
- **Key evidence:** `tests/kernel_load_test.php`, `tools/perf-gate.php`, `tests/perf_gate_test.php`, `storage/perf-baseline.json`
- **Notes:** promotion claim is aggregate load delta only; it does not assert per-request latency guarantees.

### Track 9 — certification
- **Objective:** prove fleet-wide certification discipline is measurable and gateable.
- **Status:** **PROMOTED**
- **Key evidence:** `tools/module-certification-gate.php`, `tests/module_certification_rate_test.php`
- **Notes:** delivered state is `69/69` certified (`100%`).

### 4b — browser journeys
- **Objective:** prove key browser journeys in a reproducible seeded environment.
- **Status:** **PROMOTED**
- **Key evidence:** `tests/browser/builder-journey.spec.js`, `tests/browser/report-approval-journey.spec.js`, `tests/browser/async-rendering-journey.spec.js`, `database/seeds/browser_environment.php`, `modules/cms/routes.php`, `modules/cms/handlers/78-import-export.php`, `modules/project-audit-ledger/templates/project-audit-ledger/pages/settings-async-rendering.disyl`
- **Notes:** the browser seed is part of the proof surface.
- **Justified not-applicable / debt:** browser verification depends on the seeded fixture environment; this is intentional, not a release-packaged public demo path.

---

## Overall Decision

The **principles above are promoted with evidence**.

This is **not** a blanket statement that the whole product is “release-ready on narrative.” It is a Manifesto-style proof-program decision: promotion is granted **per principle, per gate, per artifact-backed result**. Narrative release-gate manifest generation is the next step and should be run separately.
