# Development Control Plane — Phase 1 Migration (Observe)

Status: **Phase 1 slice — durable Development Task Ledger (OBSERVE-ONLY)**

This document is the Phase 1 migration plan that repositions ARK Workbench around a
**durable Development Control Plane (DCP)**: a task-centric development lifecycle of
`/architect` → `/implement` → `/review` → `/release-gate`, where every transition and
every stage result is recorded, immutable, and traceable.

Phase 1 adds a **durable Development Task Ledger** and re-frames existing surfaces
around it. It is observe-only: Workbench **ingests** and **reads**; it does not become
an IDE, a chat client, a generic test runner, a Git client, or a multi-agent
orchestrator. Nothing is removed in Phase 1.

Non-goals, restated for the record:

- Workbench is not the coding agent. It never edits source on its own initiative.
- Workbench is not a chat client for Codex, DeepSeek, or Pi.
- Workbench is not a generic test runner; test execution is retained only as a
  governed evidence adapter.
- Workbench is not a Git client; Git evidence is task-scoped and ingested, never
  pushed by Workbench.
- Workbench is not a multi-agent orchestrator; no automatic agent coordination is
  added in Phase 1.

All references are relative to `docs/workbench/` and therefore to the repository root,
e.g. `kernel/Workbench/Runs/RunRepository.php` means `<root>/kernel/Workbench/Runs/RunRepository.php`.

---

## 1. Current Responsibility Audit

Every surface Workbench owns today is inventoried below with concrete file/function
references. This audit is the input to the Classification Matrix in section 2.

### 1.1 Superadmin cockpit UI — `templates/pages/superadmin-workbench.disyl`

The cockpit is a single-page tabbed surface rendered by `kernelHandlePageSuperadminWorkbench()`
(`src/http/superadmin-handlers.php:1673`) with CSS/JS assets in
`public/assets/workbench/` (`workbench-core.js`, `workbench.css`, `workbench-tailwind.css`).

| # | Surface | What it currently does | Where |
|---|---------|------------------------|-------|
| A | **Run Overview** | Summarizes all runs (hybrid ARK + PHP + browser), passed/failed/skipped totals, suite table, run detail drill-down with evidence, source fingerprints, expected-HTTP annotations, failure list, contract-execution table | `wbLoadRuns()`, `wbViewRunDetail()`; fetches `/api/v1/superadmin/workbench/runs` and `POST /api/v1/superadmin/workbench/run` |
| B | **Failure Cockpit** | Filters runs with failures/timeouts/interrupted/gate failures, groups by module/page family, offers a `Rerun` button per suite | `wbLoadFailures()` |
| C | **Issue Report** | Filters and groups issues by severity (critical/major/minor/note) and kind, grouped by module | `wbLoadIssues()`, `wbRenderIssues()`; fetches `/api/v1/superadmin/workbench/issues` |
| D | **Contract Coverage** | Per-module release-gate certification status, contract presence, routes/capabilities/workflows claimed vs tested, PHP/browser test files claimed vs existing, last run | `wbLoadCoverage()`; fetches `/api/v1/superadmin/workbench/modules` + `/coverage` |
| E | **AI Steward** | Renders `ai_diagnosis` (classification, confidence, summary, evidence, suspected files, recommended action), issue severity counts, quick actions including `Run PHP Certification Tests` | `wbLoadSteward()`; consumes `ai_diagnosis` from the issues API |
| F | **Process Map** | Canonical typed graph per module (declared / runtime / AI-inferred / human-verified nodes, edges, provenance, confidence, validation errors, shadow plan) | `wbLoadProcessMap()`; fetches `/api/v1/superadmin/workbench/process-map` |
| G | **Platform Settings** | API-key management (create/revoke) and AI provider configuration (per-provider API keys encrypted at rest, free/paid/custom models, workbench AI policy: enabled/provider/tier/model/timeout/tokens/evidence bytes) | `wbCreateKey()`, `wbRevokeKey()`, `aiSaveProvider()`, `wbSaveAiPolicy()`; `POST /api/v1/superadmin/workbench/keys` and `/ai-settings` |

The page also embeds a **Test Runner** sub-surface inside panel A: a module filter,
per-individual-test buttons for every discoverable `tests/*/*_test.php`, a browser
environment config stored in `localStorage` (`wb_env_config`), and an ARK Hybrid
launcher (`wbRunTests('ark-hybrid', …)`). Output is rendered raw into `#wb-test-output`.
Execution is gated by `workbenchExecutionAllowed()` (`src/http/superadmin-handlers.php:1657`)
which refuses test execution in production unless `IKABUD_DEV_WORKBENCH=true`.

### 1.2 Page handler + HTTP API surface — `src/http/core-routes.php:33-41, 76-79`

All routes require a kernel `superadmin` session; every handler re-checks
`($user['role'] ?? '') !== 'superadmin' || ($user['source'] ?? '') !== 'kernel'`.

| Route (method) | Handler | Reads / writes |
|----------------|---------|----------------|
| `/superadmin/workbench` (GET) | `pageSuperadminWorkbench` → `kernelHandlePageSuperadminWorkbench()` (`src/http/superadmin-handlers.php:1673`) | Renders the cockpit; scans `test_results/*.json` (last 30), discovers test files via `workbenchDiscoverTestFiles()` (`:1623`), reads AI settings + API keys |
| `/api/v1/superadmin/workbench/keys` (GET, POST) | `apiSuperadminWorkbenchKeys` (`:1797`) | Lists / creates / revokes kernel API keys via `Ikabud\Kernel\Services\ApiKeyAuth` |
| `/api/v1/superadmin/workbench/test-results` (GET) | `apiSuperadminWorkbenchTestResults` (`:1876`) | Raw passthrough of a single `test_results/<suite>.json` artifact |
| `/api/v1/superadmin/workbench/runs` (GET) | `apiSuperadminWorkbenchRuns` (`:2336`) | Correlates hybrid engine artifacts by canonical run id (`workbenchHybridRuns()` `:2208`), merges PHP + browser results, sorts by finished time |
| `/api/v1/superadmin/workbench/run` (POST) | `apiSuperadminWorkbenchRunDetail` (`:2456`) | Run detail: hybrid (`run_id`) or suite JSON lookup across `test_results/{,.browser,.ai}` |
| `/api/v1/superadmin/workbench/issues` (GET) | `apiSuperadminWorkbenchIssues` (`:2517`) | Merges browser issue-report, test-failure/gap scans, ARK hybrid analyst/comprehension issues, IssueLedger entries, and `ai_diagnosis` (`steward-diagnosis.json`, pattern-intelligence, or learned diagnosis) |
| `/api/v1/superadmin/workbench/modules` (GET) | `apiSuperadminWorkbenchModules` (`:2689`) | Per-module manifest, contract presence, test counts, last run, certification (`validateModuleCertification`) |
| `/api/v1/superadmin/workbench/coverage` (GET) | `apiSuperadminWorkbenchCoverage` (`:2786`) | Claimed-vs-observed coverage from the latest hybrid run per module (`workbenchObservedClaimedRoutes()` `:2315`) |
| `/api/v1/superadmin/workbench/contracts` (GET) | `apiSuperadminWorkbenchContracts` (`:2932`) | Reads `tests/contracts/modules/_summary.json` |
| `/api/v1/superadmin/workbench/process-map` (GET) | `apiSuperadminWorkbenchProcessMap` (`:2956`) | Builds a module graph via `ComprehensionProviderRegistry` + `GraphBuilder`, includes validation errors and shadow plan |
| `/api/v1/superadmin/workbench/trigger-tests` (POST) | `apiSuperadminWorkbenchTriggerTests` (`:1908`) | Executes `tests/discover.php`, an individual registered test, or `tests/browser/run-workbench.js` (ARK hybrid) via `proc_open`; returns raw stdout/stderr + log byte counts. Gated by `workbenchExecutionAllowed()` |
| `/api/v1/superadmin/workbench/ai-settings` (POST) | `apiSuperadminWorkbenchAiSettings` (`:2070`) | Saves per-provider API keys/models (encrypted via `aiEncryptSensitiveSettings`) and the workbench AI policy into the global module registry |

### 1.3 CLI commands — `ikabud` (dispatch at `ikabud:6061-6160`)

All five contract commands share `WorkbenchContractService` (`kernel/Workbench/Contracts/WorkbenchContractService.php`).

| Command | What it does | Where |
|---------|--------------|-------|
| `workbench:init <module> [--force]` | Creates/migrates `<module>/workbench-contract.json` deterministically (`WorkbenchTestContractMigrator`) | `WorkbenchContractService::initialize()` |
| `workbench:validate <module> [--json]` | Validates schema + claims (routes, capabilities, test files, identity fields, unique ids) | `WorkbenchContractService::validate()` → `WorkbenchTestContractValidator` |
| `workbench:doctor <module> [--url= --user= --pass=]` | Pre-browser release gate: validation + env readiness (TEST_BASE_URL / TEST_ADMIN_USER / TEST_ADMIN_PASS) | `WorkbenchContractService::doctor()` |
| `workbench:run <module> [--gate=critical\|major\|off]` | Creates the canonical run id, records a durable run report at `storage/workbench/runs/<run_id>.json`, executes declared PHP/browser test files with timeout termination | `WorkbenchContractService::run()` |
| `workbench:explain <run-id> [--json]` | Turns a durable run report into a machine-readable cause list + next command | `WorkbenchContractService::explain()` |
| `workbench:benchmark [--json] [--output=]` (related) | Runs the competitive quality gate over the golden corpus and writes provenance-attached reports | `CompetitiveBenchmarkRunner::execute()` (dispatched `ikabud:6062`) |

CI-side: `scripts/workbench-ci.php` drives contract + competitive gates for `ARK_MODULES`
under `.github/workflows/ark-workbench.yml`; `docker/workbench/Dockerfile` is the
contract-and-benchmark runner (deliberately not a browser/hybrid E2E runner).

### 1.4 Artifact families

| Family | Location | Contents |
|--------|----------|----------|
| Durable contract runs | `storage/workbench/runs/<run_id>.json` | `ark.workbench-contract-run.v1` reports: preflight, provenance, executions, outcome (`passed|failed|interrupted|blocked`); written atomically by `WorkbenchContractService::persistReport()` |
| System Analyst reports | `test_results/analyst/<run_id>/system-analyst-report.json` | ux_evolution, issues, coverage, runtime pages; produced by `tests/browser/analyst/*` |
| Comprehension reports | `test_results/ai/runs/<run_id>/comprehension-report.json`; flat `test_results/ai/*.json` (`test-plan.json`, `process-map.json`, `steward-diagnosis.json`, `coverage-report.json`, `evidence.json`) | Semantic comprehension, breakpoints, AI diagnosis |
| Scenario runs | `test_results/scenarios/<run_id>/scenario-run.json` (+ resolution files) | `kernel/Workbench/Scenario/*` prepared/executed/finalized fixtures |
| Playwright artifacts | `test_results/browser/*.json` (per-suite), `manifest.json`, `issue-report.json`, `fingerprint-baseline.json`, `runs/<run_id>/*`, `hybrid-analysis--<browser>.json` | Produced by `tests/browser/WorkbenchReporter.js`, `WorkbenchObserver.js`, `WorkbenchFixture.js`; per-test statuses preserve `passed|failed|skipped|timedOut|interrupted` |
| Module test contracts | `<module>/workbench-contract.json` (e.g. `modules/project-audit-ledger/workbench-contract.json`) | `ark.workbench-test-contract.v1`; ownership, actors, tenancy, pages, workflows, actions, invariants, scenarios, environments, evidence identity, gates, test_files |
| Issue ledger | `storage/private/workbench/issues/` (per-issue JSON + `index.json` + `index.lock`) | `IssueLedger` occurrences, states, diagnoses, resolutions, verified case promotion |
| Process graph | in-memory `ModuleGraph` + `test_results/ai/process-map.json`, `test_results/graph-scan-*.json` | Typed nodes/edges with provenance + confidence via `kernel/Workbench/Graph/*` |
| Provenance | `provenance` block inside every run report/export | `RunProvenance::build()` (`kernel/Workbench/Runs/RunProvenance.php`): run id, timestamps, completion status, git sha, module version, environment fingerprint, tenant/role identity, AI policy, schema versions, input artifact hashes, redaction status |
| Exports | `RunExporter::ark()|junit()|sarif()` (`kernel/Workbench/Runs/RunExporter.php`); `test_results/benchmark/competitive-*.json`, `storage/workbench/ci/*` | ARK JSON, JUnit XML, SARIF 2.1.0 with provenance; competitive benchmark reports |
| Metrics | `storage/private/workbench/metrics.json` | `WorkbenchMetrics::record()` — AI call/fallback counters, last-seen |

### 1.5 Engines

| Engine | Responsibility | Where |
|--------|----------------|-------|
| WorkbenchContractService | Contract lifecycle orchestrator for the CLI: init/validate/doctor/run/explain; canonical run-id creation; env propagation (`WB_RUN_ID`, `ARK_MODULE`, `MODULE`, `HYBRID_GATE`); process-group timeout termination | `kernel/Workbench/Contracts/WorkbenchContractService.php` |
| RunRepository (+ RunIntelligence) | Durable indexed run history keyed by run/module/commit/tenant/role/browser/environment/outcome; flock-serialized index; raw-artifact expiry; `compare()` (new/resolved/persistent issue fingerprints); flake classification, quarantine, timelines | `kernel/Workbench/Runs/RunRepository.php`, `kernel/Workbench/Runs/RunIntelligence.php` |
| IssueLedger (+ IssueCardRenderer) | Append-safe issue clustering, governed state transitions (observed→…→verified→promoted_to_case), evidence-backed diagnosis append, case-memory promotion | `kernel/Workbench/Issues/IssueLedger.php`, `kernel/Workbench/Issues/IssueCardRenderer.php` |
| Comprehension provider registry | Per-module architecture comprehension: convention `WorkbenchComprehensionProvider.php` next to `module.json` or `PalComprehensionProvider`; feeds GraphBuilder + process map | `kernel/Workbench/Comprehension/ComprehensionProviderRegistry.php`, `kernel/Workbench/Graph/*`, `kernel/Workbench/Comprehension/SemanticComprehensionEngine.php` |
| Reporter | Playwright custom reporter: per-suite JSON, aggregate manifest, fingerprint baseline; preserves native statuses | `tests/browser/WorkbenchReporter.js`, `tests/browser/WorkbenchObserver.js`, `tests/browser/WorkbenchFixture.js`, `tests/browser/run-workbench.js` (canonical run id) |
| System Analyst | UX evolution score, runtime discovery, issue extraction from observed pages | `tests/browser/analyst/*` (`AnalystReport.js`, `ExperienceAnalyst.js`, `IssueCorrelator.js`, `RuntimeResolver.js`, `UxEvolution.js`) |
| AI Steward | Provider-neutral, evidence-bounded diagnosis with deterministic heuristic fallback; redaction of secrets; provider trace; metrics | `kernel/Workbench/AI/WorkbenchAiAnalyzer.php`, `kernel/Workbench/Intelligence/PatternIntelligence.php` + `run.php`, `kernel/Workbench/Evidence/EvidenceNormalizer.php` |
| CompetitiveBenchmarkRunner (+ AiCalibrationBenchmark) | Deterministic quality gates over the golden corpus; reproducibility digest; provenance-attached reports | `kernel/Workbench/Benchmark/CompetitiveBenchmarkRunner.php`, `kernel/Workbench/Benchmark/CompetitiveBenchmark.php`, `kernel/Workbench/Benchmark/AiCalibrationBenchmark.php` |
| Scenario engine | Scenario resolution, fixture preparation/cleanup, route traversal, prerequisite classification | `kernel/Workbench/Scenario/*` |
| Extension registry | Provider registration surface for external modules | `kernel/Workbench/Extensions/ExtensionRegistry.php` |
| Governance | Rollout policy + metrics recording | `kernel/Workbench/Governance/WorkbenchRolloutPolicy.php`, `kernel/Workbench/Governance/WorkbenchMetrics.php` |

---

## 2. Classification Matrix

Every surface in section 1 receives exactly one classification. This is the baseline
(verified against the code above) and the contract for Phase 1: **Phase 1 must not
deviate from it except by a separately approved task.**

Legend: **KEEP** = retain as-is (may be wired into task views); **ADAPT** = change
responsibility within Phase 1–4; **DEPRECATE** = stop presenting as a primary workflow,
keep compatible, document replacement; **REMOVE** = delete (none in Phase 1);
**ADD** = new durable surface.

### 2.1 Cockpit UI panels (templates/pages/superadmin-workbench.disyl)

| Surface | Classification | Justification |
|---------|----------------|---------------|
| A. Run Overview | ADAPT | Becomes the task-first development health view: runs render as task stage envelopes, not a flat suite list. |
| Test Runner sub-panel (module filter + individual test buttons + ARK Hybrid launcher) | DEPRECATE | Generic individual/all-test launcher is not the primary Workbench workflow; execution moves behind a governed evidence adapter. Kept functional; replaced by stage-scoped execution via CLI/CI. |
| Raw test-output presentation (`#wb-test-output`, raw stdout/stderr passthrough) | DEPRECATE | Raw output is presentation, not evidence; replaced by normalized stage results with digests. |
| Page identity — "Workbench Test Cockpit" title, `Run PHP Certification Tests` primary CTA, browser env config in `localStorage` | DEPRECATE | Implies Workbench itself is the coding/execution agent; re-framed as a task-first control plane in Phase 1 copy/IA. |
| B. Failure Cockpit | ADAPT | Folded into the task-first health view; the inline `Rerun` button moves to the deprecated launcher path, failure scanning becomes task-linked. |
| C. Issue Report | KEEP | Presents the issue ledger, which is the durable issue record; unchanged except task linkage. |
| D. Contract Coverage | KEEP | Module/capability/ownership knowledge + deterministic gates; unchanged. |
| E. AI Steward | ADAPT | Restricted to evidence-citing analysis only; prose becomes a claim with citations, never authoritative evidence. |
| F. Process Map | KEEP | Architecture/process graph with provenance; unchanged; gains immutable revision linkage. |
| G. Platform Settings | ADAPT | AI provider configuration becomes execution-policy metadata on stages/tasks; API-key management (superadmin authorization) stays. |

### 2.2 Page handler + HTTP API (src/http/core-routes.php:33-41, 76-79)

| Surface | Classification | Justification |
|---------|----------------|---------------|
| `/superadmin/workbench` page handler (`kernelHandlePageSuperadminWorkbench`) | ADAPT | Shell remains; render context shifts to task-first health + task ledger; same superadmin route/gate. |
| `GET /api/v1/superadmin/workbench/keys` | KEEP | Superadmin authorization + headless access keys. |
| `POST /api/v1/superadmin/workbench/keys` | KEEP | Same authorization surface; create/revoke unchanged. |
| `GET /api/v1/superadmin/workbench/test-results` | DEPRECATE | Raw per-suite artifact passthrough; superseded by layered verification summaries (`/runs`) and, in Phase 1, task stage-result ingestion. Kept compatible. |
| `GET /api/v1/superadmin/workbench/runs` | ADAPT | The run cockpit data source; feeds the task-first development health view with layered verification (PASS/FAIL/FLAKY/SKIPPED/NOT_REQUIRED/NOT_RUN kept distinct). |
| `POST /api/v1/superadmin/workbench/run` (run detail) | ADAPT | Drills into task stage envelopes and raw retained artifacts; read-only. |
| `GET /api/v1/superadmin/workbench/issues` | ADAPT | Ledger read stays authoritative; embedded `ai_diagnosis` restricted to evidence-citing analysis. |
| `GET /api/v1/superadmin/workbench/modules` | KEEP | Module/capability/ownership knowledge. |
| `GET /api/v1/superadmin/workbench/coverage` | KEEP | Deterministic coverage knowledge + gates. |
| `GET /api/v1/superadmin/workbench/contracts` | KEEP | Module test contracts + validation summary. |
| `GET /api/v1/superadmin/workbench/process-map` | KEEP | Architecture/process graph surface. |
| `POST /api/v1/superadmin/workbench/trigger-tests` | ADAPT | Becomes a governed evidence adapter (stage-scoped execution), not a general launcher; production gate and registry validation stay. |
| `POST /api/v1/superadmin/workbench/ai-settings` | ADAPT | Provider settings become execution-policy metadata (which provider/model may run for which stage/evidence class). |

### 2.3 CLI commands (ikabud)

| Surface | Classification | Justification |
|---------|----------------|---------------|
| `workbench:init` | KEEP | Module test contract creation/migration is deterministic and normative. |
| `workbench:validate` | KEEP | Contract + claims validation is the deterministic gate. |
| `workbench:doctor` | KEEP | Pre-browser deterministic gate. |
| `workbench:run` | ADAPT | Retains canonical run id + provenance but becomes the governed evidence adapter that emits task stage envelopes for `/implement`. |
| `workbench:explain` | ADAPT | Becomes task-stage explanation scoped to a development task, not just a run id. |
| `workbench:benchmark` | KEEP | Competitive deterministic gate. |

### 2.4 Artifact families

| Surface | Classification | Justification |
|---------|----------------|---------------|
| `storage/workbench/runs/*` (contract run reports) | KEEP | Durable run history with canonical run ids + provenance; becomes the stage-result substrate under task envelopes. |
| `test_results/analyst/*` | KEEP | System Analyst evidence artifacts remain the evidence base. |
| `test_results/ai/runs/*` + flat comprehension artifacts | KEEP | Comprehension evidence remains; content policy evolves under the steward adaptation. |
| `test_results/ai/steward-diagnosis.json` | ADAPT | Diagnosis artifact restricted to evidence-citing analysis; provider trace retained. |
| `test_results/scenarios/*` | KEEP | Scenario-run evidence + cleanup records. |
| `test_results/browser/*` | KEEP | Playwright artifacts (per-suite JSON, manifest, issue-report, fingerprint baseline) remain authoritative evidence. |
| `<module>/workbench-contract.json` | KEEP | Module test contracts + validation. |
| Issue ledger (`storage/private/workbench/issues`) | KEEP | Durable issue record with governed transitions. |
| Process graph artifacts (`test_results/ai/process-map.json`, `graph-scan-*.json`) | KEEP | Architecture/process graph. |
| Provenance block (RunProvenance) | KEEP | Canonical provenance in every run/export. |
| Exports (RunExporter ARK/JUnit/SARIF; benchmark reports) | KEEP | Deterministic gates + versioned exports. |
| Metrics (`storage/private/workbench/metrics.json`) | KEEP | Governance counters. |

### 2.5 Engines

| Surface | Classification | Justification |
|---------|----------------|---------------|
| WorkbenchContractService | KEEP | Contract lifecycle orchestrator; gains stage-adapter methods in Phase 1 without changing existing semantics. |
| RunRepository + RunIntelligence | KEEP | Run history/comparison, flake classification, expiry; DCP task timeline builds on it. |
| IssueLedger + IssueCardRenderer | KEEP | Issue ledger + governed knowledge promotion. |
| ComprehensionProviderRegistry + Graph + SemanticComprehensionEngine | KEEP | Architecture/process graph + module/capability/ownership knowledge. |
| Reporter (WorkbenchReporter/Observer/Fixture/run-workbench.js) | KEEP | Playwright artifacts + canonical run id propagation. |
| System Analyst (tests/browser/analyst/*) | KEEP | Evidence artifacts (ux_evolution, issues, coverage). |
| AI Steward (WorkbenchAiAnalyzer + PatternIntelligence) | ADAPT | Evidence-citing analysis only; agent prose is a claim, never authoritative evidence; deterministic fallback + provider trace retained. |
| CompetitiveBenchmarkRunner + AiCalibrationBenchmark | KEEP | Deterministic competitive gates. |
| Scenario engine (`kernel/Workbench/Scenario/*`) | KEEP | Scenario evidence + fixture cleanup. |
| ExtensionRegistry | KEEP | Provider registration; no orchestration added. |
| Governance (WorkbenchRolloutPolicy + WorkbenchMetrics) | KEEP | Policy + metrics; DCP adds task-level policy metadata on top. |

### 2.6 New surfaces (Phase 1)

| Surface | Classification | Justification |
|---------|----------------|---------------|
| Durable development tasks | ADD | Task records (`/architect → /implement → /review → /release-gate`) in `storage/workbench/tasks/`, CLI-first. |
| Immutable architecture-contract revisions | ADD | Every contract/architecture fact read into a task is pinned to an immutable revision hash. |
| Explicit lifecycle transitions | ADD | Only allowed transitions recorded with actor + timestamp; invalid transitions rejected. |
| Actor/harness/context identity | ADD | Every task action records actor (human / CI / CLI), harness (bash/node/php), and environment fingerprint. |
| Task-scoped Git evidence | ADD | `git_sha`, changed-file list, and diff digest recorded per task; ingested, never pushed by Workbench. |
| Stage-result ingestion | ADD | Stage envelopes absorb normalized artifacts (contract runs, browser evidence, analyst, comprehension) under one task. |
| Review findings | ADD | Findings with severity + evidence links attached to `/review` stage. |
| Release decisions | ADD | `/release-gate` decisions (approve / block / condition) recorded immutably with rationale. |
| Task timeline | ADD | Ordered, immutable event timeline per task. |

### 2.7 REMOVE

**Nothing.** Phase 1 removes no surface, no artifact family, no engine, no route, no
CLI command. Removal of any DEPRECATED surface requires: (a) usage evidence collected
over at least one release cycle, (b) a working replacement in place, and (c) a
separately approved removal task. DEPRECATED surfaces remain fully functional and
compatible.

### Classification totals (baseline)

| Classification | Count |
|----------------|-------|
| KEEP | 34 |
| ADAPT | 14 |
| DEPRECATE | 4 |
| REMOVE | 0 |
| ADD | 9 |
| **Total surfaces** | **61** |

---

## 3. Target Boundaries

The Development Control Plane owns **task lifecycle truth and normalized evidence**;
it does not own external tools. Ownership is explicit so Phase 1 stays observe-only.

### 3.1 What the DCP owns

- **Task records and lifecycle**: durable development tasks, immutable revision pins,
  explicit transitions, actor/harness/context identity, task timeline, release decisions.
- **Normalized evidence**: canonical run ids, provenance, normalized observations
  (`EvidenceNormalizer`), layered verification states (PASS, FAIL, FLAKY, SKIPPED,
  NOT_REQUIRED, NOT_RUN kept distinct), issue ledger, exports.
- **Deterministic gates**: contract validation, certification checks, competitive
  benchmark gates, doctor preflight — all pass/fail/block decisions are Workbench-owned.
- **Knowledge**: module/capability/ownership knowledge and architecture/process graphs,
  each fact carrying provenance + confidence and pinned to immutable revisions.

### 3.2 What the DCP does not own

| External tool | Boundary | Rule |
|---------------|----------|------|
| **Codex / DeepSeek / Pi (agents)** | Agent output (prose, diffs, plans) | Workbench ingests only normalized claims attached to a task stage; **agent prose is a claim, not authoritative evidence**. No automatic coordination in Phase 1. A claim becomes evidence only when confirmed by a deterministic test, verified human review, or a release gate. |
| **Playwright** | Execution tool | Workbench ingests Playwright artifacts (`test_results/browser/*`) as evidence; it does not run or configure browsers beyond the existing governed adapter. |
| **Git** | VCS | Workbench verifies task-scoped Git evidence against the real repository (`GitEvidenceResolver` resolves `git rev-parse HEAD`, the working-tree changed paths, and a content fingerprint, and rejects fabricated heads/paths). It separates the approved baseline (pre-existing dirt captured at import) from task-attributable scope and re-verifies the working tree at release (P1-1). It never stages, commits, or pushes. |
| **CI** | Scheduler | Workbench is called by CI (`scripts/workbench-ci.php`, GitHub Actions) and reads stage results; it does not schedule pipelines. |
| **Code editors / IDEs** | Authoring | Workbench never edits source; humans and authorized agents edit code. |
| **Chat clients** | Conversation | Workbench is not a chat surface for agents; task/actor identity is recorded structurally, not conversationally. |

**Evidence hierarchy (normative):** deterministic test outcomes and verified human
review outrank AI inference and historical probability; AI diagnosis is admissible
only with explicit evidence citations and a provider trace (fallback reason included).
This matches the existing governance in `docs/workbench/ark-workbench-data-governance.md`
and `kernel/Workbench/AI/WorkbenchAiAnalyzer.php`.

---

## 4. Phased Migration

The lifecycle is `/architect` → `/implement` → `/review` → `/release-gate`. Phases
are sequential; each phase has an explicit gate and requires a separately approved
task before it begins.

### Phase 1 — Observe (this slice)

**Scope:** durable Development Task Ledger, observe-only.

- Add: durable development tasks, immutable architecture-contract revisions, explicit
  lifecycle transitions, actor/harness/context identity, task-scoped Git evidence,
  stage-result ingestion, review findings, release decisions, task timeline.
  Task records live at `storage/workbench/development/tasks/<task-id>/` (projection,
  immutable `revisions/`, append-only `events/`, per-task `.lock`, global `index.json`).
  Schemas in `kernel/Workbench/Schemas/development-{task,stage-result,event}.v1.schema.json`.
- Kernel domain: `kernel/Workbench/Development/` — `DevelopmentLifecycle`
  (allow-listed state machine + release prerequisites), `DevelopmentTaskContract`
  (strict `.ai/current-task.md` import + scope normalization),
  `DevelopmentTaskRepository` (locking + atomic-write persistence),
  `GitEvidenceResolver` (resolves/verifies repository HEAD + working-tree changed
  paths; read-only, never mutates Git), and `DevelopmentArtifactIngestor`
  (stage-envelope ingestion, Git-evidence verification, verification-artifact
  hashing, redaction, scope classification, valid transitions only).
- CLI-first ingestion: narrow adapters `workbench:task:ingest` (import architecture),
  `workbench:task:record` (record a stage-result envelope), and `workbench:task:show`
  (list/detail/timeline). They operate on artifacts produced by Codex/DeepSeek/Pi/
  Git/Playwright; they never launch or converse with agents. Existing run commands
  stay backward compatible.
- Read-only HTTP: `/api/v1/superadmin/workbench/tasks` (list),
  `/api/v1/superadmin/workbench/task?id=…` (detail), and
  `/api/v1/superadmin/workbench/task/timeline?id=…` (events) — superadmin +
  kernel-source only, no mutation. The cockpit leads with a Development Task Ledger
  panel (task-first health) and drills into the retained run/failure/issue/coverage/
  steward/process-map evidence.
- Release readiness is deterministic: it requires a real, content-hash-verified
  release-gate artifact (existing JSON on disk declaring `decision: approved` with
  only PASS/NOT_REQUIRED checks) plus the mandatory verification layer set
  (`unit`, `integration`, `playwright` all PASS). Each verification layer is
  artifact-backed and fully task-bound: its referenced test result must be a JSON
  document declaring the recognized result schema, a consistent non-failing
  summary (`exit_code` 0, `total` > 0, `failed` === 0, `passed` > 0,
  `passed + failed + skipped === total`), the task id, the immutable contract
  revision, the resolved Git HEAD, the working-tree fingerprint, the layer it
  certifies, and a runner identity. Public bindings alone are not evidence: the
  document must also carry an `hmac-sha256` runner attestation verified with the
  external `WORKBENCH_EVIDENCE_HMAC_KEY` and key id; the secret is never persisted
  in the artifact or task ledger. Its sha256 content hash is computed at ingest
  (caller-supplied hash strings are never trusted) and re-verified from disk at
  every release evaluation. A fabricated/unverifiable gate, a bare hash string, an
  arbitrary/stale/replayed/unbound/failing file, a missing/tampered verification
  artifact, and any one-off PASS layer cannot unlock `READY_FOR_RELEASE`.
- Implement-stage Git evidence is authoritative and stable: `DevelopmentArtifactIngestor`
  resolves the repository HEAD and working-tree changed paths via
  `GitEvidenceResolver` and fails closed when the caller-supplied head does not
  match `git rev-parse HEAD`, when a claimed path was never changed, or when no
  repository is available. Scope review classifies the Git-resolved path set minus
  the approved baseline, so an envelope that omits real out-of-scope changes
  cannot hide them (they surface as unexpected scope and force `REVIEW_REQUIRED`).
- Baseline separation (P1-3): pre-existing working-tree changes that must remain
  baseline are captured at import — either from an optional `## Baseline` contract
  heading or, by default, from the Git working tree at import time — together with
  per-file content hashes. Task-attributable changes are `current − baseline`;
  a baseline file whose CONTENT changed after import is detected as drifted and
  enters task scope (reviewed, never silently blessed as non-task).
- Working-tree stability (P1-1): at implementation the resolver records the HEAD,
  the task-attributable changed paths, and a path+content fingerprint. The
  authoritative release decision (gate ingest and direct transitions) re-verifies
  all three against the live working tree and FAILS CLOSED when the repository
  cannot be re-verified — later uncommitted changes or an unavailable Git
  environment can never reach `READY_FOR_RELEASE` without revalidation. The
  informational web display may report git as unverifiable but never fabricates
  drift blockers.
- `GET /api/v1/superadmin/workbench/test-results` marked deprecated (compatible);
  UI launcher + raw-output presentation marked deprecated with replacement copy.
- No automatic Codex/DeepSeek/Pi coordination may be added in Phase 1. No code
  execution, no Git writes, no CI scheduling, no database migration.
- **Gate:** all existing Workbench tests pass unchanged; task ledger writes are
  append-only/immutable; read APIs require superadmin; no REMOVE.

#### HMAC runner attestation setup

Verification result artifacts are only accepted when they carry an `hmac-sha256`
runner attestation so a caller cannot self-author a pass by echoing public
task/Git fields. The shared secret is a deployment configuration, never stored in
the artifact or task ledger.

1. **Provision the key** (≥ 32 bytes, e.g. `openssl rand -hex 32`) and set it for
   every runtime that ingests or re-verifies (CLI, CI, php-fpm):
   ```
   WORKBENCH_EVIDENCE_HMAC_KEY=<64 hex chars>
   WORKBENCH_EVIDENCE_KEY_ID=local-dev-v1   # optional; defaults to "default"
   ```
   The app reads these from `.env` on every request (bootstrap populates both
   `$_ENV` and `putenv`), so no FPM pool change is needed when `.env` is present.
   A missing or too-short key fails closed: no verification layer can validate.
2. **Sign result artifacts** after a test run, before recording the stage. Sign
   the exact JSON that will be referenced by the verification layer:
   - CLI: `php ikabud workbench:task:sign --file=<result.json>` adds
     `signature_algorithm`, `attestation_key_id`, and `signature` in place.
   - PHP: `DevelopmentVerificationArtifact::sign($artifact)` returns the HMAC;
     `validate()` reproduces it over canonical JSON (sorted keys, stable list
     order, `JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE`) minus `signature`,
     and compares with `hash_equals`.
3. **Bindings covered by the signature** include `task_id`, `contract_revision`,
   `git_head`, `fingerprint`, `layer`, `runner`, and the non-failing `summary` —
   a replayed artifact from an earlier revision/head/fingerprint is rejected.
4. `attestation_key_id` defaults to `default` when only the key is set; artifacts
   must declare the configured key id.

### Phase 2 — Evidence

**Scope:** make every task stage evidence-complete (one line): each `/implement`
envelope links normalized observations, browser artifacts, and issue-ledger findings,
and each `/review` finding cites only evidence present in the task timeline.
**Later gate:** layered verification (PASS/FAIL/FLAKY/SKIPPED/NOT_REQUIRED/NOT_RUN)
is computed and rendered for task stages; evidence citations are verifiable by id;
no surface removed.

### Phase 3 — Governance

**Scope:** deterministic release governance on top of tasks (one line): `/release-gate`
decisions (approve/block/condition) become the authoritative release input consumed
by certification and CI, with owner/evidence required for every condition.
**Later gate:** release decisions are immutable and auditable; flaky/environment-only
findings cannot block release without verified reproduction; exports (ARK/JUnit/SARIF)
carry task + decision provenance; no surface removed.

### Phase 4 — Orchestration (explicitly later, not scheduled)

**Scope:** optional, separately approved: task-stage envelopes may *request* actions
from external tools (e.g., suggest a Playwright run, open a Pi task, hand a Codex
context) — (one line) the DCP becomes a request/response broker with policy and
quotas, still owning only lifecycle truth and evidence.
**Later gate:** requires a written orchestration proposal, an external-tool
coordination policy, and a separately approved task. Until then, **no automatic
Codex/DeepSeek/Pi coordination exists in any phase**; Phase 1–3 remain observe/govern.

---

## Appendix — decision rules (Phase 1 standing constraints)

1. **No REMOVE in Phase 1.** Removal needs usage evidence + replacement + separate task.
2. **No automatic agent coordination.** Phase 1 adds no Codex/DeepSeek/Pi integration.
3. **Compatibility preserved.** Every DEPRECATED surface stays functional; consumers of
   `test-results` and `trigger-tests` see no breaking change in Phase 1.
4. **Immutable by construction.** Task records, revisions, transitions, and release
   decisions are append-only; corrections are new records, never edits.
5. **Claims are not evidence.** Any AI-originated text stored on a task carries
   `claim: true` plus evidence citations and a provider trace; only deterministic
   outcomes, verified human review, and release-gate decisions are `evidence: true`.
