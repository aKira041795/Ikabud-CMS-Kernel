# ARK Workbench AI Pattern Intelligence Roadmap

## Vision

ARK Workbench should evaluate both explicit conformance and latent quality.

Deterministic testing answers:

> Does the observed system satisfy the requirements and thresholds we can directly measure?

AI-assisted pattern intelligence answers:

> Does the system exhibit a combination of signals that resembles a healthy pattern, a known defect pattern, or an emerging risk that ordinary assertions do not expose?

The AI acts like a specialist inspecting metal grain after annealing. It can recognize subtle structural patterns, compare them with prior samples, consult relevant technical knowledge, and recommend additional verification. It does not replace the calibrated instruments that determine conformance.

## Core operating principle

AI may interpret, connect, retrieve, challenge, prioritize, and propose. Deterministic systems must observe, execute, measure, verify, gate, authorize, and promote knowledge.

```text
Deterministic observation
        ↓
Evidence normalization and correlation
        ↓
AI pattern assessment
        ↓
Assumption retrieval and verification
        ↓
Targeted deterministic tests
        ↓
Human or policy-governed verdict
        ↓
Scoped case learning
```

No AI assertion becomes a product fact merely because it is plausible or confidently stated.

## Dual quality verdict

Every Workbench run should produce two independent judgments.

### Conformance verdict

Deterministic and gate-controlled:

- pass;
- fail;
- blocked by environment;
- inconclusive because required evidence is missing.

### Latent-quality verdict

AI-assisted and explicitly non-conformational:

- healthy;
- observe;
- targeted verification recommended;
- analyst review required;
- unknown because comparable evidence is insufficient.

A system may pass conformance while receiving an elevated latent-risk assessment. AI cannot silently convert latent concern into deterministic failure or waive a deterministic failure.

## Software grain signature

Workbench should construct a stable signature for each process execution.

### Process structure

- node and edge count;
- branching factor;
- workflow depth;
- state-transition density;
- unresolved or unverified edges;
- prerequisite complexity;
- cross-module dependencies;
- shortest valid user path and observed path cost.

### Runtime behavior

- duration and latency distribution;
- request count and dependency calls;
- retries, recoveries, and timeouts;
- console and network anomalies;
- state changes and rollback behavior;
- database and API shape consistency;
- temporal ordering of effects.

### User interaction

- clicks and field entries;
- task duration;
- failed actions and validation recovery;
- backtracking and repeated actions;
- navigation depth and context switches;
- keyboard reachability;
- responsive behavior;
- accessibility and terminology consistency.

### Evidence quality

- static and runtime coverage;
- independent evidence-source count;
- source freshness;
- missing prerequisites;
- confirmed versus inferred relationships;
- provider availability;
- similarity to verified cases;
- contradictory evidence.

The signature is versioned and stored alongside the immutable run evidence.

## Assumption verification through retrieval

AI can consult a broad body of information to verify or challenge its initial interpretation. Retrieval must be governed and source-aware.

### Internal sources

Highest-value sources include:

- module manifests and declared capabilities;
- routes and authorization rules;
- templates and semantic inspection manifests;
- handlers, services, repositories, and database migrations;
- API schemas and event contracts;
- process graphs and generated test paths;
- browser traces, screenshots, network logs, and console output;
- issue ledger and verified case memory;
- previous healthy and failed run signatures;
- approved architecture decisions and product documentation;
- superadmin Workbench policies and effective provider settings;
- source-control changes relevant to the observed behavior.

### Approved external sources

Depending on policy, AI may consult:

- official framework and library documentation;
- formal standards and specifications;
- accessibility standards and authoritative guidance;
- security advisories from authoritative sources;
- published protocol and browser documentation;
- approved industry or domain references;
- approved organizational knowledge systems.

External retrieval must not silently expand the testing scope, expose confidential information, or turn generic recommendations into application requirements.

### Source authority hierarchy

When sources conflict, Workbench should apply an explicit hierarchy:

1. Observed runtime behavior and immutable evidence
2. Enforced code, schema, authorization, and database constraints
3. Approved product requirements and architecture decisions
4. Verified historical cases from the same component and state
5. Official standards and primary technical documentation
6. Similar cases from other modules
7. AI inference
8. Unverified secondary information

The report must show when a lower-authority source conflicts with a higher-authority source.

## Assumption lifecycle

Each AI assumption becomes a tracked object rather than prose embedded in a report.

```json
{
  "assumption_id": "asm-approval-context-001",
  "claim": "Rejected approval may lose project context after queue reload",
  "claim_type": "inferred",
  "confidence": 0.74,
  "scope": {
    "module": "project-audit-ledger",
    "workflow": "approval",
    "states": ["pending", "rejected"]
  },
  "supporting_evidence": ["obs-14", "graph-edge-32"],
  "contradicting_evidence": ["test-approval-07"],
  "retrieved_sources": ["case-113", "route-contract-approval"],
  "verification_status": "proposed",
  "verification_tests": ["test-plan-approval-reload-02"],
  "expires_when": ["workflow-version-changed", "route-contract-changed"]
}
```

Allowed verification states:

- proposed;
- retrieval-supported;
- retrieval-contradicted;
- test-scheduled;
- confirmed;
- rejected;
- inconclusive;
- expired.

## Claim discipline

Every AI statement must be labelled as one of:

- `observed`: directly present in captured evidence;
- `derived`: computed deterministically from evidence;
- `retrieved`: supported by an identified source;
- `inferred`: a model interpretation supported by evidence;
- `predicted`: a forward-looking risk estimate;
- `unknown`: evidence is insufficient.

Every non-trivial claim must cite evidence or retrieved source identifiers. Unsupported claims are rejected during schema validation.

## AI task boundaries

### AI is allowed to

- interpret process meaning;
- identify patterns across many evidence sources;
- retrieve relevant internal and approved external knowledge;
- compare current signatures with healthy and defective cases;
- rank root-cause hypotheses;
- identify contradicting evidence;
- estimate latent risk;
- propose targeted tests;
- propose graph relationships and prerequisites;
- critique terminology, information design, and recovery flows;
- produce role-specific explanations;
- recommend candidate changes for human review.

### AI is not allowed to

- declare deterministic pass or fail;
- fabricate routes, entities, requirements, or evidence;
- waive a quality gate;
- update an approved baseline without authorization;
- write production data;
- change authorization or security policy;
- promote an unverified assumption into case memory;
- release or deploy a change;
- conceal contradictory evidence;
- treat an external recommendation as a binding requirement without approval.

## AI authority levels

Superadmin settings should define the maximum authority permitted per module and data classification.

| Level | Name | Permitted behavior |
|---:|---|---|
| 0 | Disabled | No provider invocation |
| 1 | Explain | Summarize deterministic results with citations |
| 2 | Hypothesize | Rank causes, patterns, and latent risks |
| 3 | Retrieve | Verify assumptions against approved information sources |
| 4 | Propose | Generate executable test plans and improvement candidates |
| 5 | Sandboxed execution | Submit schema-valid tests to controlled execution |
| 6 | Change recommendation | Produce source patches requiring explicit approval |

Routine Workbench operation should normally stop at Level 5. Baseline changes, knowledge promotion, production mutation, and releases remain outside AI authority.

## Structured AI contracts

### Input contract

The model receives a bounded evidence packet:

```json
{
  "task": "latent_quality_assessment",
  "module": "project-audit-ledger",
  "run_id": "...",
  "process_signature": {},
  "correlated_issues": [],
  "successful_checks": [],
  "failed_checks": [],
  "contradictory_evidence": [],
  "similar_verified_cases": [],
  "retrieval_policy": {},
  "authority_level": 3,
  "constraints": {
    "no_invented_routes": true,
    "no_unverified_requirements": true,
    "citations_required": true
  }
}
```

Successful checks must be included. They are necessary to reduce the probability of hypotheses contradicted by downstream success.

### Output contract

```json
{
  "latent_verdict": "targeted-verification-recommended",
  "risk": 0.68,
  "confidence": 0.77,
  "claims": [],
  "assumptions": [],
  "pattern_matches": [],
  "contradictions": [],
  "recommended_tests": [],
  "unknowns": [],
  "human_review_required": false
}
```

The response is rejected when it contains invalid evidence IDs, unobserved entity IDs, unknown routes, unsupported numeric claims, actions above the configured authority, or invalid schema.

## Retrieval safeguards

- Allowlist information sources by module, environment, and data classification.
- Minimize evidence before provider transmission.
- Redact credentials, tokens, personal information, and restricted fields.
- Record source URI or internal identifier, retrieval time, version, and content fingerprint.
- Prefer primary and authoritative sources.
- Verify temporal applicability; outdated guidance is marked stale.
- Keep retrieved text separate from executable instructions.
- Treat retrieved content as untrusted data, not system instructions.
- Enforce per-run cost, token, latency, and source-count budgets.
- Cache immutable references by content fingerprint.
- Make every external lookup visible in the final manifest.

## Pattern and risk model

The first latent-risk model should combine deterministic features with AI interpretation.

```text
latent risk =
    verified-case similarity
  + unexplained anomaly density
  + graph uncertainty
  + temporal drift
  + cross-layer shape mismatch
  + untested high-criticality paths
  - successful downstream evidence
  - verified healthy-case similarity
  - confirmed false-positive matches
```

Initial policy bands:

- `0.00–0.29`: healthy;
- `0.30–0.59`: observe;
- `0.60–0.79`: generate targeted verification;
- `0.80–1.00`: analyst review required.

These bands are provisional. They must be calibrated against verified historical outcomes.

## Targeted verification generation

AI-proposed tests must reference graph nodes and semantic actions.

Every proposal contains:

- hypothesis being tested;
- required preconditions;
- runtime-resolved entity requirements;
- starting and expected terminal states;
- semantic actions;
- expected deterministic observations;
- cleanup strategy;
- business criticality;
- execution cost;
- information gain if passed or failed.

The Weighted Path Planner selects proposed tests using risk, coverage, execution cost, and expected information gain. Playwright or another deterministic adapter executes the selected plan.

## Learning policy

Workbench learns from verified outcomes, not model responses.

Durable case classes:

- confirmed defect;
- false positive;
- test defect;
- environment failure;
- missing prerequisite;
- expected behavior;
- verified UX improvement;
- verified regression;
- correct AI abstention;
- incorrect AI prediction.

Cases are scoped by component family, action, workflow state, rendering mode, data shape, and applicable version range. Changed routes, schemas, or workflows trigger revalidation or expiry.

## AI evaluation program

AI providers and models must pass a golden evaluation suite before promotion.

### Required cases

- known backend/view-model mismatch;
- missing prerequisite;
- ambiguous selector/test defect;
- accessibility defect;
- authorization failure;
- environment failure;
- successful workflow with noisy observations;
- temporal regression;
- cross-module context loss;
- novel case where abstention is correct.

### Metrics

- root cause top-1 and top-3 accuracy;
- evidence citation accuracy;
- unsupported claim rate;
- false-positive and false-negative rate;
- correct abstention rate;
- confidence calibration;
- proposed-test validity and executability;
- defect discovery uplift over deterministic ranking;
- cost and latency;
- reproducibility across repeated runs.

No provider is promoted solely because it is newer, larger, or more fluent.

## Implementation roadmap

### Phase 0 — Final evidence ordering

Objective: ensure AI sees the authoritative evidence set.

- Move AI analysis after browser reporter correlation.
- Merge diagnostic, behavioral, HTTP, console, UX, graph, and baseline evidence.
- Attach successful checks and contradictions.
- Ensure one underlying problem has one correlated issue.
- Version the immutable AI input packet.

Exit gate: AI input contains final correlated issues and no reporter-only blind spots.

### Phase 1 — Claim and assumption contracts

Objective: prevent persuasive but unsupported conclusions.

- Add claim-type schema.
- Add assumption lifecycle schema.
- Require evidence and source IDs.
- Reject invented routes, entities, and requirements.
- Display observed, retrieved, inferred, predicted, and unknown distinctly.

Exit gate: all golden responses validate, and intentionally unsupported responses are rejected.

### Phase 2 — Governed retrieval

Objective: let AI verify assumptions against relevant knowledge.

- Implement internal source adapters.
- Add approved external-source policy.
- Add source hierarchy and conflict reporting.
- Add redaction and data-classification enforcement.
- Record provenance, freshness, fingerprint, cost, and retrieval decisions.
- Defend against instructions embedded in retrieved content.

Exit gate: every retrieved claim is traceable and policy-compliant.

### Phase 3 — Pattern signatures

Objective: create comparable software grain structures.

- Version the process/runtime/interaction/evidence signature.
- Store signatures for healthy and failed runs.
- Add distance and drift calculations.
- Connect signature features to graph nodes and issues.
- Establish module-specific and cross-module comparison rules.

Exit gate: repeated identical runs produce stable signatures within defined tolerance.

### Phase 4 — Verified case retrieval

Objective: ground predictions in confirmed outcomes.

- Retrieve similar confirmed defects and healthy cases.
- Retrieve confirmed false positives and test defects.
- Scope matches by state, component, version, and rendering mode.
- Include both supporting and contradicting cases.
- Expire cases when contracts materially change.

Exit gate: case retrieval passes precision tests and never promotes unverified model output.

### Phase 5 — Latent-risk assessment

Objective: predict non-obvious risks without corrupting conformance.

- Implement independent latent-quality verdict.
- Combine deterministic features with structured AI assessment.
- Require contradictions and unknowns.
- Calibrate confidence and risk bands.
- Add analyst-review thresholds.

Exit gate: latent verdict cannot change conformance verdict and achieves agreed calibration on golden cases.

### Phase 6 — Targeted test generation

Objective: convert predictions into falsifiable tests.

- Generate graph-bound test plans.
- Validate preconditions, actions, routes, and cleanup.
- Score expected information gain.
- Execute only in the sandbox.
- Feed results back to the assumption lifecycle.

Exit gate: generated tests are schema-valid, executable, isolated, and improve diagnostic resolution.

### Phase 7 — Superadmin AI governance

Objective: make effective authority and provider behavior explicit.

- Configure provider, model, authority level, modules, and data classes.
- Configure source allowlists, budgets, timeouts, and retention.
- Record effective configuration per run.
- Test configured, disabled, degraded, unavailable, and fallback modes.
- Prevent settings from weakening deterministic gates.

Exit gate: every provider action is attributable to an effective policy decision.

### Phase 8 — Golden evaluation and promotion

Objective: treat AI changes as testable platform changes.

- Build versioned golden cases from verified Workbench history.
- Compare providers, models, prompts, retrieval strategies, and schemas.
- Track accuracy, abstention, calibration, cost, and latency.
- Require improvement before promotion.
- Support rollback to the prior AI configuration.

Exit gate: promoted configuration meets published evaluation thresholds with no safety regression.

### Phase 9 — Controlled change recommendations

Objective: allow AI to propose product changes without granting autonomous mutation.

- Generate evidence-linked patch proposals.
- Predict expected telemetry improvement.
- Require explicit approval.
- Apply in isolated workspace.
- Run deterministic regression and UX comparison.
- Accept or revert based on measured outcomes.

Exit gate: no source change or baseline update occurs without authority and verification.

## Initial ARK integration priorities

The current PAL evidence identifies the first concrete integrations:

1. Run AI after the final Workbench Reporter issue report is complete.
2. Remove the gap between the analyst report and reporter-only HTTP findings.
3. Supply successful lifecycle steps as contradiction evidence.
4. Teach the engine that an observed DOM state change and persisted submission refute an “Add Item broken” hypothesis.
5. Convert the dashboard recent-projects mismatch into the first verified cross-layer pattern case.
6. Add correct-abstention golden cases for prerequisite-dependent fields.
7. Replace generic `template` and `unknown` diagnoses with evidence-linked assumptions or explicit abstention.
8. Record the effective superadmin provider, model, policy, and authority in every report.

## Success criteria

ARK Workbench AI pattern intelligence is ready for production use when:

- deterministic tests complete normally without AI;
- every AI claim cites valid evidence or an approved source;
- AI cannot change conformance, baselines, or release decisions;
- retrieved sources are traceable, current, and policy-compliant;
- successful evidence materially lowers contradicted risk estimates;
- predictions produce falsifiable targeted tests;
- confidence is calibrated against verified outcomes;
- correct abstention is rewarded;
- verified learning improves future ranking without creating broad false suppressions;
- provider changes are evaluated and reversible;
- reports clearly separate observation, retrieval, inference, prediction, and verdict.

## Final boundary

AI should function as a well-read, pattern-sensitive system analyst with access to approved evidence and reference material. It may investigate whether its assumptions are supported, contradicted, outdated, or incomplete. The Workbench remains the laboratory: it controls the evidence, runs the tests, measures the result, enforces the boundary, and decides what becomes trusted knowledge.

## Implementation completion — 2026-07-16

All roadmap phases are implemented and connected to the final Workbench Reporter lifecycle:

| Phase | Delivered control | Recheck result |
|---|---|---|
| 0 | Final-evidence assembly after correlation and comprehension | Pass |
| 1 | Typed, evidence-linked claim validation and persisted claim/assumption schemas | Pass |
| 2 | Allowlisted retrieval with provenance, fingerprints, redaction, and untrusted-content marking | Pass |
| 3 | Stable software-grain signatures and drift distance | Pass |
| 4 | Scoped retrieval restricted to verified, current cases | Pass |
| 5 | Independent latent-risk verdict with contradiction credit and explicit unknown handling | Pass |
| 6 | Authority-gated, graph-bound, sandbox-only targeted-test validation | Pass |
| 7 | Effective Superadmin policy resolution with deterministic fallback | Pass |
| 8 | Golden-case metrics, correct-abstention scoring, promotion thresholds, and rollback metadata | Pass |
| 9 | Evidence-linked, approval-required, isolated change-recommendation gate | Pass |

The integrated implementation lives in `kernel/Workbench/Intelligence/PatternIntelligence.php`; the reporter invokes `kernel/Workbench/Intelligence/run.php` only after its authoritative issue report and comprehension work are complete. Machine-readable contracts live in `kernel/Workbench/Schemas/`.

The phase regression suite is `tests/workbench_ai_pattern_intelligence_test.php`. It currently passes 18/18 assertions, including the gap discovered during integration where an empty verified-case set previously caused a latent-risk crash.

### PAL acceptance run

Live run `20260716085159-0f276ed4` completed against `http://palsystem.test`:

- Browser conformance: pass (1/1 test).
- UX evolution telemetry: 100/100, above the required 80 threshold.
- Discovery: 54 pages, 210 fields, 29 creatable flows, and 239 data flows.
- Dynamic checks: 299 passed; the final reporter retained 11 major HTTP 404 findings.
- Behavioral lifecycle: job order created, item added, submitted, persisted, and redirected successfully.
- Pattern intelligence: final evidence assembled from analyst, reporter, and comprehension sources; latent verdict `observe` at risk 0.25 without changing conformance.
- Effective AI policy: deterministic-only because the resolved Superadmin Workbench AI setting was disabled. The artifact records that fallback explicitly and does not imply a provider call occurred.

Artifact: `test_results/browser/runs/20260716085159-0f276ed4/pattern-intelligence.json`.

The 11 HTTP findings remain visible by design. Passing browser/UX telemetry does not suppress them, and AI cannot relabel the conformance verdict or update a baseline. They are actionable PAL route-availability findings for a separate product-fix cycle.
