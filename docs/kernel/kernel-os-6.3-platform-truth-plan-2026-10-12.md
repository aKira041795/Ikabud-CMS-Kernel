# Kernel OS 6.3 — Platform Truth & Contract Evolution (proposed)

**Status: PROPOSED — consolidated from three chair consultations (2026-10-11 / 2026-10-12). Owner approval and Gate 0 are pending; nothing here is approved for implementation yet.**
**Date:** 2026-10-12

**Mission:** make tenant settings and capability execution contracts *inspectable, enforceably consistent, and regression-protected* — without altering valid production behavior.

**Governing invariant (chair):**
> For a specific tenant, caller, and platform version, Ikabud can determine, enforce, and explain the effective contract **without changing that contract merely by inspecting it.**

**Principle to carry into the version:**
> Declarations are claims. Enforcement establishes what is permitted. Inspection explains what was decided. Certification keeps those three consistent.

**Notes:**
- DiSyL release number: determine separately from actual compiler/template changes — do not force a language version bump for a kernel-only cycle.
- Feature-module tracks (daily-ledger and others) continue independently; this cycle does not displace them.
- Constraints unchanged: Bluehost shared hosting, MySQL 5.7, no new infrastructure services, running multi-tenant production.

---

## 1. Where this comes from

| Source | Verdict carried into this plan |
|---|---|
| `docs/reviews/settings-change-stability-2026-10-11.md` | KEEP architecture; sequence was tests → normalization → rename policy → diagnostics |
| `docs/reviews/capability-bus-evolution-2026-10-11.md` | GO provider-resolution truth; BENCHMARK discovery caching; DEFER ADR-006 |
| `.ai/consult/platform-truth-plan.md` (consolidation, 2026-10-12) | Theme approved **with scope and sequence changes** (folded in below) |

The two tracks belong together because they share a governing invariant — not because they need a shared implementation. Settings and capabilities have the same underlying problem: *the platform can declare intended behavior, but cannot yet consistently prove and explain why a particular tenant receives that behavior.*

## 2. Workstreams

### Track A — Settings Contract Integrity
| Item | Ruling | Content |
|---|---|---|
| **A1** | **First gate** — before any change | Expand `tests/manifest_settings_defaults_test.php`: declared type + valid default per editable key; no duplicate keys; `false`/`0`/`""`/`null`/absent distinguished; stored overrides round-trip without type change; invalid-value policy. Establishes existing behavior *before* normalization. |
| **A2** | **Split — not one step** | (1) Observe and characterize existing raw values and precedence; (2) specify canonical behavior + differential tests; (3) enforce only where proven compatible. Read-side coercion can silently change production decisions (a stored `"0"` is not interchangeable across legacy consumers). Warning: defaults merge through per-module `*SettingsDefaults()` helpers — a central normalizer alone cannot prove coverage, and must not become a second settings authority. |
| **A3** | **Narrow initially** | Policy + refusal tests first: rename conflicts stop and report (never silently pick); explicit tenant overrides preserved. Actual key migration only when justified. |
| **A4** | **Diagnostic records first** | Structured effective-value diagnostic records (value, source: manifest default vs tenant override, declared type, invalid/deprecation reason). A UI is not the proof; it can come later. |

### Track B — Capability Resolution Truth
| Item | Ruling | Content |
|---|---|---|
| **B0** | **First gate** — alongside A1 | Read-only truth baseline: declarations, registry entries, selection outcomes, ordering; inventory `function_exists()` / `is_callable()` / `get_included_files()` / global-constant consumers. |
| **B1** | **Conditional** | Normalize declared provider metadata only where ambiguity is demonstrated — do not rewrite valid declarations. |
| **B2** | **Core deliverable** | Explainable resolution: Declared → Eligible/Ready → Authorized as separate states; read-only resolution record with stable reason codes; same decision functions as dispatch (no parallel weaker path); caller-scoped. |
| **B3** | **Benchmark only** | Discovery caching (10.59 ms warm / 53.45 ms cold) is orthogonal to contract truth. Separate decision **after Gate 3**; cache definitions only (never tenant enablement or authorization); prove invalidation + fallback first. Not an exit requirement. |
| **B4** | **Move earlier** | Negative authorization/schema tests constrain the implementation — they run before and alongside B2, not after. |
| **B5** | **Out of cycle** | Lazy activation (ADR-006) is a different risk class (execution lifecycle + compatibility). Excluded; reconsider only against its falsifier thresholds. |

### Track C — Contract Certification & Regression Gates
Smaller than A/B; reuses existing gate and certification tooling.
- Verify settings contract: manifest defaults, duplicate keys, supported types, deprecation rules.
- Verify capability contract: declaration consistency, authorization denials, ordering determinism, explanation parity.
- Prove tenant isolation and refusal on missing context.
- **Frozen baseline discipline:** new violations fail; existing (legacy) violations are classified and explicitly scheduled — do not suddenly reject every legacy module for contracts never previously required.

## 3. Flagship proof — Effective Contract Inspector (ECI)

A **read-only inspection capability**, not a new administration application. Extend an existing architecture/Workbench CLI rather than creating a service.

Proposed interface (conceptual, not yet approved):

```
php ikabud contract:explain setting    --tenant=54 --module=daily-ledger --key=<key>
php ikabud contract:explain capability --tenant=54 --caller=<actor> --capability=<id>@1
```

Setting record: declared type, manifest default, override presence, raw vs interpreted value where permitted, precedence source, invalid/deprecation reason.

Capability record dimensions — never a single `available=true`:
| Dimension | Outcome |
|---|---|
| Declared | yes / no |
| Provider eligible | yes / no |
| Executable readiness | ready / unavailable / **unknown** (unknown ≠ negative — static inspection must not claim what it has not tested) |
| Authorized for caller | allow / deny |
| Selection | selected provider + ordering rationale |
| Explanation | stable reason code + evidence category |

Hard requirements:
- Must not independently reimplement kernel decisions — share a decision result between enforcement and diagnostics, or start with differential testing of the existing decision path.
- Zero side effects: no writes, no module activations, no authorization changes. **Inspecting must not change execution.**
- Caller-scoped: ordinary tenants cannot enumerate other tenants' providers, policies, or implementation paths.

Flagship demonstration (passes when all of these hold): two tenants with different explicit settings · two caller scopes with different permissions · multiple provider candidates · one unavailable provider · one deliberate conflicting rename. Every outcome explained, unsafe ambiguity refused, effective behavior unchanged, no unauthorized disclosure.

## 4. Execution gates

| Gate | Work | Exit criteria |
|---|---|---|
| **0 — Baseline & risk census** (read-only) | Settings consumer/raw-value inventory; capability resolution + dispatch call graph; **enforcement census**; certification debt classified; performance baseline | Coverage and known debt quantified; any newly discovered fail-open or cross-tenant incident triaged immediately |
| **1 — Characterize contracts** | A1 + B0 together; negative authorization and invalid-value fixtures added **before** implementation changes | Existing outcomes reproducible incl. false/zero distinctions, provider ordering, refusal behavior |
| **2 — Settings proof** | Canonical values + rename policy specified; only behavior-preserving normalization or narrowly scoped migrations; structured diagnostic records | Diagnostics agree with real consumers on tested paths; explicit overrides preserved; conflicts stop rather than choose |
| **3 — Capability proof** | Declaration + provider-resolution diagnostics; explanation-to-dispatch parity proven | Selected / rejected / unauthorized / unavailable / unknown distinguished correctly, with zero side effects |
| **4 — Certification & operator proof** | ECI integrated with existing CLI; release gates wired; scoped multi-tenant HTTP/CLI + performance runs | Deterministic explanations, zero new contract regressions, zero unauthorized disclosure, no unacceptable runtime regression |

Discovery caching gets its own benchmark decision **after Gate 3**. Lazy activation is excluded from the cycle.

## 5. KPI contract (exit thresholds)

| Measure | Threshold |
|---|---|
| Existing settings defaults + override precedence | 100% pass on inventoried contract fixtures |
| Settings diagnostic agreement | 100% agreement with actual effective values on tested consuming paths |
| Provider resolution agreement | 100% agreement with the real selection/authorization path on the test matrix |
| Tenant isolation | 0 unauthorized cross-tenant disclosures or allows |
| Missing/invalid authority scope | 0 fail-open outcomes |
| Rename conflicts | 100% detected and refused; no silent overwrite |
| Ordering determinism | Same candidate set + context ⇒ identical selection |
| Diagnostics side effects | 0 persistent writes; 0 unintended module activations |
| Contract regressions | 0 new violations against the established baseline |
| Performance | No material regression vs captured baseline (provisional: 5% median threshold + p95 inspection; warm and cold; report the inventory denominator — never claim platform-wide coverage from new tests alone) |

Operator-facing change: an operator can ask *"why does this tenant get this behavior?"* and receive the effective contract + source, instead of a database-and-helper investigation. Measurable operationally by timing seeded incident diagnosis before/after.

## 6. Explicitly out of scope

Lazy module activation (ADR-006) · discovery caching as a cycle deliverable · a new central settings framework · ECI web UI (CLI + structured output first) · marketplace UI · real AI provider integration · broad entity-schema redesign · broad module-certification cleanup (separate maintenance backlog) · service-health alerts.

## 7. Entry evidence (Gate 0 — mandatory before implementation)

1. Current **enforcement census** by module and entry point (does the capability/authority layer actually govern the operations it claims?).
2. Complete settings consumer and raw-value inventory (normalization compatibility risk).
3. Current provider-resolution and dispatch call graph (proves explanation can share the real decision path).
4. Current certification failures, classified by severity (is Track C gate alignment or urgent remediation?).
5. Existing Workbench extension contracts (smallest safe ECI integration).
6. Baseline performance + incident-diagnosis measurements (makes later claims falsifiable).

## 8. Reconciliation with in-flight authority/governance work (verified 2026-10-12)

The authority/governance port (work contract: `.ai/authority-governance-port.contract.md`, gitignored local copy) has **substantially landed** in this tree:

- **Dispatch-time enforcement exists:** `kernel/Http/AuthorityDispatchGuard.php` — `assertAllowed()` (:102) throwing `AuthorityDispatchDeniedException` (:113, :159); refuses declared-contract violations, permits undeclared routes and missing configuration (fail-closed on violation, never fail-closed on missing config).
- **Census machinery exists:** `kernel/Workbench/Governance/` — `AuthorityCensus::compare()` (:51), `GovernanceCensus.php`, `ExtensionPointCensus.php`, `CapabilityDiff.php`.
- **Tests present:** `tests/authority_dispatch_enforcement_test.php`, `tests/authority_census_contract_test.php`, `tests/authority_scope_test.php`, `tests/workbench_governance_census_test.php`, `tests/extension_point_census_test.php`, `tests/capability_authority_audit_test.php`.
- **Docs present:** `docs/kernel/authority-and-governance.md`.
- Open items to verify at Gate 0: whether `TemplateOutputTooLargeException` is actually thrown from the render path (contract item 9); the contract's `tests/module_route_authority_test.php` is not present under that name.

**The chair's September census figures** (only 5 of 33 Akira routes dispatch-enforced; none of 52 inventoried daily-ledger operations dispatch-enforced) **could not be located in any tracked file** — treat them as unverified. Gate 0's census run replaces them with fresh, reproducible numbers.

**Decision fork at Gate 0:** if the census shows a material set of security-sensitive production operations outside enforceable authority — and that gap is an active risk the inspector cannot address — **switch the cycle theme to "Authority Enforcement Coverage" first** (the direction of the port contract's SLICE B) and run Platform Truth afterwards.

## 9. Stop conditions (falsifiers)

1. Gate 0 enforcement gap (above) → theme switch to Authority Enforcement Coverage.
2. Characterizing raw settings values shows normalization would require a platform-wide migration → take normalization out of the cycle; ship characterization + tests + diagnostics only.
3. Diagnostics cannot share the real decision path without risky refactoring → differential testing only; do not fork decision logic into a parallel path.

## 10. Definition of done

- A tenant's effective setting is explainable with its source and precedence.
- A caller's capability outcome is explainable with accurate declaration, readiness, authorization, and selection evidence.
- Inspecting either contract cannot change its execution outcome.
- The certification system prevents new covered-contract regressions.
- No uncontrolled tenant behavior change, authorization relaxation, or material performance regression has been introduced.

> **The cycle succeeds not when Ikabud has more manifests, more diagnostics, or more tests — but when the kernel can reliably answer: "Why did this tenant get this behavior, and can we prove that answer matches execution?"**

## 11. Artifacts

- Consults (local, gitignored): `.ai/consult/settings-stability.md` · `.ai/consult/capability-bus-improvement.md` · `.ai/consult/platform-truth-plan.md`
- Tracked reviews: `docs/reviews/settings-change-stability-2026-10-11.md` · `docs/reviews/capability-bus-evolution-2026-10-11.md`
- Related: `docs/architecture/decisions/ADR-006-registration-implementation-separation.md` · `.ai/authority-governance-port.contract.md` · `docs/kernel/kernel-os-disyl-roadmap-status.md`
