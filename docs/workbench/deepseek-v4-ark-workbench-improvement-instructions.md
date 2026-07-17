---
title: DeepSeek v4 — ARK Workbench Improvement Instructions
status: active
audience: implementation agent
authority: supplements docs/workbench/competitive-roadmap.md and docs/workbench/ai-pattern-intelligence-roadmap.md
---

# DeepSeek v4 — ARK Workbench Improvement Instructions

## Mission

Improve ARK Workbench's testing trustworthiness without weakening deterministic release controls or adding module-specific behavior to Workbench core.

ARK Workbench is a Kernel OS module quality governor. It is not an LLM-driven test runner. The AI provider may interpret, correlate, prioritize, and propose tests; deterministic code owns observation, validation, gates, certification, and release eligibility.

## Non-negotiable rules

1. Read `AGENTS.md` and the relevant Workbench docs before editing.
2. Preserve existing user changes. Do not reset, clean, or reformat unrelated files.
3. Use existing generic contracts and capability providers. Do not add PAL-only conditionals to Workbench core.
4. Never let an AI result pass, waive, downgrade, or overwrite a deterministic failure.
5. Every AI claim must cite only evidence IDs supplied to it. Reject unsupported citations and invalid schemas.
6. Never log API keys, authorization headers, cookies, raw personal data, or unredacted request bodies.
7. Distinguish `confirmed-defect`, `false-positive`, `test-defect`, `environment`, and `unmet-prerequisite`; do not collapse them into generic failures.
8. Do not invent entity IDs, routes, workflow states, or expected outcomes. Discover them from observed links or module-owned scenario/test-data providers.
9. Keep generated run artifacts out of commits unless the task explicitly asks for a curated fixture.
10. Update documentation and automated tests with every behavior change.

## Current baseline to preserve

- AI is available through the configured Kernel AI provider in headless CLI and Superadmin flows.
- `WorkbenchAiAnalyzer` validates the provider schema, enforces `allowed_evidence_ids`, records provider/model/latency trace, and falls back deterministically.
- `ClaimContract` validates AI claims before they are accepted into Pattern Intelligence.
- `WB_ISSUE_GATE` / `HYBRID_GATE` are deterministic severity gates; default behavior must remain explicit and reproducible.
- Navigation certification and runtime diagnostic checks detect declared/rendered internal URLs that cannot be served.
- Parameterized routes without observed valid entities are currently `unmet-prerequisite` notes, not product defects.

## Priority 1 — make run evidence trustworthy and self-explanatory

Implement a canonical run provenance block, attached to every top-level Workbench report and export.

Required fields:

```text
run_id, started_at, finished_at, completion_status,
git_sha, module_id, module_version, app_url,
environment fingerprint, tenant/role fixture identity,
scenario/seed version, test-plan version,
gate policy, AI policy, resolved provider/model,
artifact schema versions, redaction status
```

Rules:

- `completion_status` must be `complete`, `interrupted`, `blocked`, or `failed-before-analysis`.
- A partially completed run must never be presented as release certification.
- The effective AI policy must show the resolved model actually used, not only the configured default.
- Each report must reference the exact input artifacts it consumed by relative path and content hash.
- Add tests covering complete, interrupted, provider-fallback, and redacted runs.

Exit condition: a developer can answer “what exactly was tested, with which inputs, and did it finish?” from one report without reading logs.

## Priority 2 — replace prerequisite noise with governed scenario coverage

Extend the generic scenario/test-data contract so modules can provide reproducible prerequisites without Workbench knowing module internals.

Implement or strengthen:

1. Scenario fixtures declaring actor/role, tenant, seed identity, required entity relationships, and lifecycle state.
2. Module-owned providers that create or locate legal test records and return opaque observed links/IDs.
3. Route traversal that prefers observed list-to-detail-to-edit links before requesting a provider.
4. Classification that keeps an unavailable fixture as `unmet-prerequisite` with a precise reason, owner, and recommended provider capability.
5. Fixture cleanup/rollback policy that is tenant-scoped, idempotent, and never deletes user-owned data.

Do not turn missing data into a product failure unless the module contract declares that data must be present.

Exit condition: reference modules can exercise a representative create → view → edit → workflow transition path with real prerequisites, and unrelated parameterized pages remain honestly classified.

## Priority 3 — calibrate AI instead of trusting it by impression

Create a versioned benchmark corpus of verified Workbench cases. Each case must include deterministic evidence, a known classification, an expected root-cause category, and whether AI should propose a next test.

Measure separately:

- deterministic critical-defect recall;
- AI claim acceptance rate;
- AI citation-validity rate;
- AI precision and recall against verified classifications;
- top-three root-cause accuracy;
- false-positive rate;
- rate of useful next-test recommendations;
- provider latency, timeout, fallback, and cost proxy.

Policy:

- Do not publish a single “AI confidence” number without stating the sample size and metric.
- Keep a strict all-or-nothing rejection until per-claim filtering can preserve only independently valid claims with clear provenance.
- Promote a pattern to Case Memory only after human or deterministic verification.

Initial target gates:

```text
critical deterministic recall: 100% on golden cases
AI citation validity:          100%
AI false-positive rate:        below 5%
top-three root cause:          at least 85% of verified cases
reproducible deterministic plan: 100% for identical recorded inputs
```

Exit condition: the Superadmin report shows measured calibration status, not a subjective AI-quality label.

## Priority 4 — make reports actionable for developers

For every issue, render one evidence-backed card containing:

```text
classification, severity, deterministic gate impact,
confidence and basis, exact evidence links,
observed versus expected behavior, reproduction command,
environment/fixture identity, suspected cause,
recommended owner, and next deterministic test
```

UI requirements:

- Clearly label an AI interpretation as `AI-assisted; evidence validated` or `AI unavailable; deterministic fallback`.
- Clearly distinguish release-blocking failures, non-blocking risks, and fixture/environment blocks.
- Show resolved provider/model without exposing credentials; display “Key configured” only, never a secret or a reversible mask.
- Provide JSON, JUnit, and SARIF exports from the same canonical issue identities.

Exit condition: a developer can reproduce or dismiss a finding without asking the Workbench maintainer for context.

## Priority 5 — strengthen release and regression discipline

Add or maintain tests for:

- malformed provider output, unsupported citations, timeout, disabled AI, and deterministic fallback;
- interrupted run provenance and report status;
- scenario provider success, refusal, cleanup, and tenant isolation;
- complete route/navigation coverage including dynamic placeholders and allowed cross-module dependencies;
- cross-role and cross-tenant matrix runs;
- reporter output freshness: an HTML error response or stale result file must not yield a passing test result;
- regression reproduction for every confirmed critical issue.

Run focused tests first, then the smallest relevant live module run. Suggested baseline commands:

```bash
php tests/workbench_phase2_test.php
php tests/workbench_ai_pattern_intelligence_test.php
php tests/workbench_superadmin_sync_test.php
php tests/module_navigation_route_certification_test.php
HYBRID_GATE=critical node tests/browser/run-workbench.js --module=<module-id> --gate=critical
```

Adapt commands to the repository's current test runner; do not claim success from a stale artifact or an exit code alone.

## Implementation method

For each priority:

1. Inspect the existing contract, schema, runner, report, and tests.
2. Write or update the failing focused test first when practical.
3. Make the smallest generic implementation change.
4. Validate syntax and focused tests.
5. Run a relevant end-to-end module proof using controlled fixtures.
6. Inspect generated artifacts for completion, provenance, evidence references, and secret redaction.
7. Record the completed behavior and any remaining limitation in the appropriate Workbench documentation.

Stop and report a blocker if completing a phase requires destructive data cleanup, new production credentials, ambiguous business behavior, or external coordination.

## Definition of done

An improvement is complete only when all are true:

- deterministic authority remains intact;
- AI output is schema-valid, evidence-bounded, traceable, and safely redacted;
- relevant success and failure paths have automated tests;
- a real module proof has completed and its artifacts are fresh;
- documentation states both what is now reliable and what remains unmeasured;
- no unrelated files or generated artifacts are included in the change.

## First recommended work item

Implement Priority 1 first: canonical report provenance and explicit completion status. It eliminates the current ambiguity where a process can emit useful partial artifacts while an interrupted or stale run could be misread as a successful release report. Then proceed to scenario fixtures (Priority 2), because that increases the quality of deterministic evidence before expanding AI interpretation.
