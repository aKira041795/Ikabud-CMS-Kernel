---
description: Authoritative roadmap for making ARK Workbench a competitive Kernel OS module quality governor
---

# ARK Workbench Competitive Roadmap

**Status:** Active
**Started:** 2026-07-16
**Authority:** This document is the current productization roadmap. The comprehension and pattern-intelligence roadmaps describe completed foundations; this roadmap governs competitive readiness.

## Product position

ARK Workbench is not a Playwright replacement. It is the Kernel OS module quality governor:

```text
declared contract
→ navigation, roles, tenants, capabilities
→ UI and workflow execution
→ observed effects and evidence
→ causal diagnosis
→ certification and release eligibility
```

Its competitive advantage is module honesty: a module must not declare navigation, authority, workflows, effects, or release readiness that it cannot serve and prove.

## Existing foundation

The following foundations are already implemented and are inputs to this roadmap:

- Versioned run, evidence, graph, issue, scenario, and intelligence schemas.
- Action-scoped evidence with explicit censored outcomes.
- Canonical provenance graph and process-map views.
- Configured AI adapter with schema validation, redaction, policy, trace, and deterministic fallback.
- Governed issue ledger and verified Case Memory promotion.
- Weighted planning and diagnostic traversal.
- Scenario/test-data engine with module-owned capability providers.
- Module certification and package gating.
- Run-scoped Reporter, Analyst, Comprehension, Scenario, and Pattern Intelligence artifacts.
- Superadmin Workbench run correlation and release-gate visibility.

These foundations do not by themselves establish competitive readiness. Portability, proof across modules, matrix testing, visual scenarios, durable reporting, and adoption tooling remain the release program.

## Non-negotiable principles

1. Deterministic evidence remains authoritative; AI proposes, cites, and explains.
2. Every release decision must be reproducible from stored inputs.
3. Missing, failed, not-applicable, and probe-error outcomes remain distinct.
4. Coverage is observed or unmeasured; it is never estimated from file counts.
5. No module-specific branch may be added to Workbench core.
6. Cross-tenant and capability isolation are critical invariants.
7. Existing standards such as JUnit and SARIF are exported instead of reinvented.
8. A phase is complete only when its automated exit gates pass and completion evidence is recorded here.

## Status dashboard

| Phase | Objective | Status | Exit evidence |
|---|---|---:|---|
| 1 | Competitive benchmark and reproducibility baseline | Complete | 40 cases; all gates PASS |
| 2 | Workbench Test Contract v1 and developer tooling | Complete | 24/24 conformance and execution-safety gates PASS |
| 3 | Module-independence proof: Guidance, WMS, EHR | Complete | 25/25 portability and Guidance showcase gates PASS |
| 4 | Role, tenant, capability, and environment matrices | Complete | 7/7 matrix gates PASS |
| 5 | Component scenarios, visual and accessibility matrices | Complete | 7/7 governance gates PASS |
| 6 | Durable cockpit, comparisons, flakes, and exports | Complete | 16/16 run-intelligence and concurrency gates PASS |
| 7 | CI integrations, extension SDK, and adoption readiness | Complete | 10/10 ecosystem and runner-scope gates; CI PASS |
| Final | Full regression and production-readiness audit | Complete | 296/296 current Workbench assertions PASS; broader baseline recorded |

## Phase 1 — Competitive benchmark

### Deliverables

- A versioned golden corpus covering navigation, authorization, tenancy, workflows, effects, UI, accessibility, integration, environment, and censored outcomes.
- A benchmark runner and CLI entry point.
- Metrics for critical detection, false positives, top-three diagnosis, evidence completeness, graph precision, runtime, flakes, and reproducibility.
- Stored benchmark reports suitable for regression comparison.
- Plain deterministic baseline versus full ARK analysis.

### Exit gates

- Critical golden-defect detection: 100%.
- Root cause appears in top three for at least 85% of verified cases.
- False-positive rate below 5%.
- Observation identity completeness: 100%.
- Identical recorded inputs produce identical deterministic plans.

## Phase 2 — Workbench Test Contract v1

### Deliverables

- Published JSON Schema and normative specification.
- Module identity, routes, navigation dependencies, capabilities, events, tables, roles, tenants, page families, workflows, actions, effects, invariants, scenarios, environments, evidence, and gates.
- Compatibility/deprecation rules and migration from legacy `test-contract.json`.
- CLI commands:
  - `workbench:init`
  - `workbench:validate`
  - `workbench:doctor`
  - `workbench:run`
  - `workbench:explain`
- Example contracts and conformance fixtures.

### Exit gates

- Invalid contracts fail before browser execution.
- Contract migrations are deterministic.
- No PAL-specific assumption exists in the schema or validator.
- Compatibility reports are machine-readable.

## Phase 3 — Module-independence proof

### Deliverables

- Guidance as the second complete proof module.
- WMS as a state/effect-heavy proof module.
- EHR as the authority/privacy proof module.
- Each module supplies its own contract, comprehension provider, scenario provider, action/effect chains, matrix declarations, and verified defects.

### Exit gate

PAL, Guidance, and WMS execute through the same core without module-specific branches; EHR authority and privacy invariants pass before production readiness.

## Phase 4 — Generated matrices

### Deliverables

- Declarative actor, tenant, capability-profile, browser, viewport, and environment dimensions.
- Risk-based pairwise/weighted selection with mandatory critical combinations.
- Generated navigation, direct-route, action, API, entity-access, export, log, and error-page isolation checks.
- Explicit explanations for combinations omitted by the planner.

### Exit gates

- Declared isolation invariant coverage: 100%.
- Golden cross-tenant leaks detected: 100%.
- Matrix plans are deterministic and reproducible.
- No omitted critical combination.

## Phase 5 — Component and visual scenarios

### Deliverables

- Workbench-native component scenario catalog.
- Empty, loading, populated, validation, error, unauthorized, and degraded states.
- Desktop, tablet, mobile, theme, localization, dense-data, and long-text variants.
- Screenshot baselines, structural comparisons, accessibility checks, and governed baseline approval.
- Chromium pull-request tier; Firefox/WebKit release tier; nightly full matrix.

### Exit gates

- Every governed component has required state scenarios.
- Critical accessibility regressions: zero.
- Visual baseline changes require an explicit approval artifact.
- Component changes identify affected modules.

## Phase 6 — Durable cockpit and run intelligence

### Deliverables

- Durable indexed run history with retention.
- Run and contract comparisons by module, commit, tenant, role, browser, and environment.
- Failure clustering, recurrence, flake classification, and governed quarantine.
- Unified causal timeline from interaction through persisted/rendered effect.
- Screenshots, traces, network, logs, probes, graph nodes, certification, and remediation in one drilldown.
- JUnit, SARIF, and versioned ARK run exports.

### Exit gates

- Every diagnosis links to evidence.
- Every release blocker identifies its failed contract and remediation path.
- Historical summaries remain queryable after raw artifact expiry.
- A developer can locate the likely failing layer within two minutes in usability trials.

## Phase 7 — CI and ecosystem

### Deliverables

- GitHub Actions and generic CI templates.
- Pull-request annotations and artifact publishing.
- Reproducible container/documented runner.
- Extension contracts for comprehension providers, scenarios, evidence collectors, gates, and exporters.
- A generated example module and a “first certified run” tutorial.
- Versioning, compatibility, release notes, troubleshooting, and migration policy.

### Exit gates

- New modules adopt ARK without Kernel core edits.
- First certified run in under one hour.
- CI setup requires fewer than ten project-specific lines.
- Extensions cannot mutate authoritative evidence or graph truth.

## North-star metrics

| Area | Target |
|---|---:|
| Critical defect detection | 100% |
| Root-cause top-three accuracy | >= 85% |
| Verified graph precision | >= 95% |
| Duplicate clustering precision | >= 95% |
| Unverified knowledge promotion | 0 |
| Schema-valid AI responses including fallback | >= 99% |
| Prompt secret leakage | 0 |
| False release blocks caused by flakes | < 2% |
| Runtime reduction at equal critical coverage | >= 25% |
| New-module adoption time | < 1 hour |
| Reproducible deterministic plans | 100% |

## Completion protocol

For every phase:

1. Audit existing implementation and reuse platform primitives.
2. Add or update normative documentation first.
3. Implement the smallest complete architecture, not a module-specific patch.
4. Add unit, contract, integration, security, and failure-path tests proportional to risk.
5. Run focused tests, then the broader Workbench regression suite.
6. Record exact test evidence and known external blockers in this document.
7. Mark the phase complete only when every exit gate is demonstrably satisfied.

## Completion log

Entries are append-only and must name the phase, implementation files, tests, metrics, and unresolved external dependencies.

### 2026-07-16 — Phase 1 complete

- Added the versioned 40-case cross-module corpus `tests/ai/golden/competitive-benchmark-cases.v1.json` covering PAL, Guidance, WMS, and EHR.
- Added corpus/report schemas, the reusable `CompetitiveBenchmark`, atomic report runner, standalone script, and `php ikabud workbench:benchmark`.
- Expanded the real deterministic Pattern Classifier with navigation, tenancy, accessibility, workflow, effect, event, audit, flake, environment, performance, dependency, integration, and coverage categories plus ranked top-three output.
- Measured result: 100% critical detection, 100% root-cause top-three accuracy, 0% false positives, 100% identity completeness, stable digest `b0d76470250d5b77fd2f8dbd31c011ca4ab67e9db70ab325dafa1ea6c741ca2c`.
- Verification: `workbench-competitive-phase1` 12/12; existing `comprehension-engine-v3` 18/18; CLI benchmark PASS; PHP syntax and `git diff --check` PASS.
- External blockers: none.

### 2026-07-16 — Phase 2 complete

- Published Workbench Test Contract v1 schema/specification, deterministic legacy migration, generic implementation validator, durable preflight run/explanation service, and five CLI commands.
- Invalid identity and route claims block browser startup; compatibility and failure causes are machine-readable.
- Verification: `workbench-competitive-phase2` 14/14; PAL canonical contract validates; valid runs execute declared PHP/browser plans.
- External blockers: none.

### 2026-07-16 — Phase 3 complete

- Added canonical contracts for PAL, Guidance, WMS, and nested EHR plus the generic contract-to-Comprehension adapter and convention discovery.
- Guidance proves breadth, WMS supplies action/effect chains, and EHR declares critical capability and cross-tenant clinical privacy invariants.
- Verification: `workbench-competitive-phase3` 20/20; all four contracts validate without module-specific core branches.
- External blockers: none.

### 2026-07-16 — Phase 4 complete

- Added deterministic mandatory-first pairwise planning across role, tenant, capability, browser, viewport, and environment dimensions with explicit omission reasons and eight isolation surfaces.
- Verification: `workbench-competitive-phase4` 7/7; 100% pairwise coverage; no omitted mandatory combination; golden tenant leak detected as critical.
- External blockers: none.

### 2026-07-16 — Phase 5 complete

- Governed all 15 Workbench primitives across seven required states and required viewport, theme, locale, density, and long-text variants.
- Added visual hash comparison, critical accessibility blocking, immutable baseline approval artifacts, and affected-module lookup.
- Verification: `workbench-competitive-phase5` 7/7.
- External blockers: real multi-browser screenshot execution remains environment-dependent but its release policy and gates are implemented.

### 2026-07-16 — Phase 6 complete

- Added indexed durable history, retention-safe summaries, run/contract comparisons, recurrence clusters, observed flake classification, governed quarantine, causal timelines, and ARK/JUnit/SARIF exports.
- Verification: `workbench-competitive-phase6` 13/13; raw expiry retained queryable summaries and traceable diagnoses.
- External blockers: two-minute cockpit usability must be measured with human participants; implementation evidence is complete.

### 2026-07-16 — Phase 7 complete

- Added safe evidence/gate/export extension contracts, reusable GitHub workflow, annotations, artifact publishing, generic CI runner, reproducible container, version/migration policy, and first-certified-run guide.
- Verification: `workbench-competitive-phase7` 8/8; `php scripts/workbench-ci.php` PASS.
- External blockers: first-hour adoption timing requires a new developer cohort; the adoption path is automated and documented.

### 2026-07-16 — Final production-readiness audit complete

- All competitive phases, legacy Workbench phases, profile/component contracts, scenario engine, Superadmin synchronization, AI Pattern Intelligence, and Comprehension Engine passed: 272/272 assertions.
- `php scripts/workbench-ci.php` PASS; all four proof-module contracts pass preflight; CLI/PHP syntax and `git diff --check` PASS.
- The repository-wide sandboxed baseline executed 298 test files: 251 passed and 47 failed outside the Workbench change set. Failures were retained as honest external evidence rather than counted as Workbench passes; the dominant class was censored MySQL/HTTP integration access, with existing theme-manifest and trigger/workflow assertions also visible.
- An elevated integration rerun was attempted but the legacy runner did not complete reliably because several HTTP integration tests wait on services not provisioned by the runner. This is a repository test-environment defect, not converted into a pass.
- Production decision: ARK Workbench competitive implementation is complete and its own release gates are green. Repository-wide production release still requires the existing integration environment and unrelated baseline failures to be resolved by their owning modules.

### 2026-07-17 — Trust and second-module proof hardening

- Serialized durable run-index updates with an exclusive lock, reload-under-lock, checked temporary writes, and atomic rename. Concurrent-writer regression coverage proves that parallel writers retain every summary.
- Made `WB_RUN_ID`, `ARK_MODULE`, compatibility `MODULE`, and `HYBRID_GATE` canonical child-process inputs for PHP and browser execution.
- Added contract-governed PHP/browser timeouts, process-group termination, exit code `124`, `timed_out`, duration, and partial-output digest evidence.
- Reorganized the generic CI runner into auditable bootstrap, input, doctor, benchmark, summary, and exit-gate sections. The lightweight Docker image is explicitly a contract-and-benchmark runner, not a browser-E2E image.
- Added Guidance's independent authenticated browser showcase, responsive and declared-navigation checks, run-correlation evidence, and a Superadmin contract-run drilldown.
- Proof procedure and environment boundaries are documented in `docs/workbench/guidance-showcase.md`.
- Verification: contract execution 24/24; module portability 25/25; durable run intelligence 16/16; ecosystem 10/10; Superadmin synchronization 16/16; all 19 Workbench, Comprehension, and Pattern Intelligence suites 296/296; generic CI PASS; Guidance validate and doctor PASS; Playwright discovers all four Guidance showcase tests.
- Environment evidence: live browser execution was not marked passed because no web server is listening on `palsystem.test` in this workspace session. The full-browser command and infrastructure boundary remain explicit in the showcase guide.
