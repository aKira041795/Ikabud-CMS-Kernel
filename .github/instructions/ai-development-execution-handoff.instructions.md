---
description: "Governed AI-assisted development workflow: /architect → /implement → /review → /release-gate role separation, context efficiency via Lean-CTX, bounded repair loops, layered testing, Playwright verification tiers and quality rules, retry policy, implementation autonomy, token/cost policy, stability principle, and the agent responsibility model. This is the operating directive for all AI-assisted development in this repository."
applyTo: "**/*"
---
# AI DEVELOPMENT EXECUTION & HANDOFF DIRECTIVE

You are operating inside a governed AI-assisted software development workflow.

The objective is to maximize implementation quality, architectural stability, test evidence, and token efficiency while minimizing unnecessary context loading, repeated reasoning, speculative changes, and human intervention.

## Core Development Lifecycle

All substantial work follows:

```
/architect
    ↓
/implement
    ↓
/review
    ↓
/release-gate
```

These are distinct responsibility boundaries.

Do not collapse them into one autonomous activity.

---

# 1. ROLE SEPARATION

## `/architect` — Architecture and Intent

Primary reasoning role: **Codex**

Responsibilities:

- understand the requested behavior;
- inspect relevant architecture;
- identify affected domains/modules;
- identify architectural boundaries;
- define allowed scope;
- define prohibited changes;
- identify dependencies and integration contracts;
- define acceptance criteria;
- define required tests;
- define important Playwright user journeys;
- identify security, concurrency, idempotency, performance, and compatibility implications where relevant.

Produce a concise Architecture/Task Contract.

Do NOT implement the feature unless explicitly instructed.

The contract should contain:

```
task:
objective:

scope:
  allowed:

constraints:

acceptance:

e2e_acceptance:

verification:

risk:

status: READY_FOR_IMPLEMENTATION
```

Keep the contract small and authoritative.

Do not dump unnecessary repository context into the contract.

---

# 2. `/implement` — EXECUTION

Primary implementation role: **DeepSeek**

Execution harness: **Pi or configured execution agent**

Context governor: **Lean-CTX**

DeepSeek owns implementation work.

Codex should not be used for routine implementation unless architectural escalation is required.

## Implementation sequence

Always follow:

```
READ CONTRACT
     ↓
CHECK GIT STATE
     ↓
DISCOVER RELEVANT CONTEXT
     ↓
BASELINE TEST
     ↓
PLAN MINIMAL IMPLEMENTATION
     ↓
EDIT
     ↓
TARGETED TEST
     ↓
FAIL?
 ┌────┴────┐
YES        NO
 │          │
DIAGNOSE    FEATURE TEST
 │          │
FIX         │
 │          │
RETEST      │
 └────┬─────┘
      ↓
STATIC / CONTRACT VALIDATION
      ↓
PLAYWRIGHT VALIDATION
      ↓
GIT DIFF
      ↓
SCOPE CHECK
      ↓
RESULT
```

Do not repeatedly ask the user to authorize normal edit-test-fix cycles that are already within the approved task scope.

---

# 3. CONTEXT EFFICIENCY

Use **Lean-CTX** to minimize unnecessary model context.

Prefer:

```
task
→ relevant architecture
→ relevant source
→ relevant tests
→ relevant recent tool output
```

Avoid:

```
task
→ entire repository
→ entire documentation tree
→ complete test logs
→ unrelated source
```

Retrieve additional context only when required.

Do not repeatedly reload unchanged files unless necessary.

Do not send huge raw command outputs to the model when a compact failure summary is sufficient.

The Task Contract must remain authoritative and should not be compressed into ambiguity.

---

# 4. IMPLEMENTATION PRINCIPLES

Prefer the smallest correct change.

Do not:

- refactor unrelated code;
- redesign architecture during implementation;
- introduce new dependencies unnecessarily;
- modify unrelated modules;
- bypass established contracts;
- create cross-module database access;
- weaken validation;
- disable failing tests merely to achieve PASS;
- introduce compatibility shims unless required;
- silently expand scope.

If implementation discovers an architectural problem outside the approved contract, STOP and return:

```
BLOCKED
ARCHITECTURE_DECISION_REQUIRED
```

Describe:

- what was discovered;
- why the current contract cannot safely resolve it;
- affected components;
- possible options;
- what architectural decision is required.

Do not invent architecture simply to continue execution.

---

# 5. TESTING STRATEGY

Testing is an execution oracle, not an afterthought.

Use layered verification.

## Layer 1 — Targeted Tests

Run the smallest tests directly related to the change.

Examples:

- unit tests;
- affected service tests;
- contract tests;
- targeted PHPUnit tests.

Run these frequently during implementation.

## Layer 2 — Feature Tests

After targeted tests pass, run tests for the affected functional area.

Examples:

```
authentication
checkout
inventory
WMS
CMS
payment
capabilities
module integration
```

## Layer 3 — Release Tests

Broad regression and cross-browser testing belongs near `/review` or `/release-gate`.

Do not repeatedly run the entire suite after every small edit unless necessary.

---

# 6. PLAYWRIGHT IS A FIRST-CLASS VERIFICATION TOOL

For user-facing behavior, use Playwright as an objective feedback system.

The normal browser loop is:

```
IMPLEMENT
   ↓
PLAYWRIGHT
   ↓
PASS ─────────────→ continue

FAIL
 ↓
collect evidence
 ↓
inspect trace
 ↓
inspect DOM/state
 ↓
inspect console
 ↓
inspect network
 ↓
diagnose
 ↓
fix
 ↓
rerun targeted test
```

Do not immediately guess at the cause of browser failures.

Use available evidence first.

---

# 7. PLAYWRIGHT TEST TIERS

Organize browser tests conceptually as:

```
PW-1 TARGET
PW-2 FEATURE
PW-3 RELEASE
```

## PW-1 TARGET

Run one or a few tests directly associated with the implementation.

Use during development loops.

## PW-2 FEATURE

Run the affected business area after targeted tests pass.

Examples:

```
@checkout
@inventory
@wms
@cms
@auth
@payment
```

## PW-3 RELEASE

Run critical E2E journeys and required browser combinations during `/release-gate`.

Do not run PW-3 after every code edit.

---

# 8. PLAYWRIGHT TEST QUALITY

Prefer resilient user-facing locators:

```
getByRole()
getByLabel()
getByText()
getByTestId()
```

Avoid fragile selectors such as:

```
deep CSS chains
nth-child selectors
XPath
```

unless genuinely necessary.

Prefer retryable assertions and Playwright auto-waiting.

Avoid arbitrary delays such as:

```
page.waitForTimeout(2000)
```

unless there is a documented reason.

Test observable behavior rather than implementation details.

---

# 9. TEST BUSINESS OUTCOMES

A successful UI message alone does not necessarily prove successful business behavior.

Where appropriate, validate the complete observable outcome.

Example checkout journey:

```
authenticate
    ↓
create order
    ↓
add item
    ↓
checkout
    ↓
payment
    ↓
inventory reservation/deduction
    ↓
order completion
    ↓
receipt
```

Relevant assertions may include:

```
order completed
payment recorded once
inventory updated correctly
stock movement created
receipt generated
duplicate request prevented
```

Use browser/network/API-visible evidence where appropriate.

---

# 10. PLAYWRIGHT FAILURE EVIDENCE

Prefer compact structured failure information over huge raw logs.

Capture where available:

```
playwright:
  test:
  status:
  attempt:

failure:
  step:
  assertion:
  expected:
  actual:

console_errors:

network_failures:

trace:

screenshot:
```

Lean-CTX should reduce verbose browser/test output to relevant evidence before sending it back to the implementation model where possible.

---

# 11. RETRY POLICY

Do not use retries to hide instability.

During implementation:

```
retries = 0
```

preferred unless otherwise configured.

A failure should trigger diagnosis.

During CI/release:

```
retries = limited
```

may be allowed to detect flaky behavior.

Classify results:

```
PASS
FLAKY
FAIL
```

Critical flaky tests should not silently be treated as healthy.

---

# 12. IMPLEMENTATION AUTONOMY

DeepSeek may autonomously:

- inspect relevant files;
- search repository context;
- edit approved files;
- add/update relevant tests;
- run targeted commands;
- run tests;
- inspect failures;
- repair implementation;
- rerun tests;
- inspect Git diff.

DeepSeek must not autonomously:

- merge;
- push to protected branches;
- delete unrelated code;
- change architecture outside contract;
- weaken security;
- bypass tests;
- expand scope without escalation.

---

# 13. BOUNDED REPAIR LOOP

Autonomous repair must be bounded.

Do not enter endless:

```
edit
→ test
→ edit
→ test
→ edit
→ test
```

loops.

Stop and escalate when:

- repeated attempts fail;
- failures indicate architectural uncertainty;
- required changes exceed approved scope;
- tests conflict with architecture contract;
- environment/infrastructure prevents reliable verification;
- the same failure persists without meaningful new evidence.

Prefer escalation over speculative token consumption.

---

# 14. IMPLEMENTATION RESULT

At completion produce a compact result:

```
status:

task:

changed:

implementation_summary:

verification:

playwright:

scope:
  unexpected_files:

risks:

unresolved:

recommended_next_state:
```

Allowed status values:

```
PASS
FAIL
PARTIAL
BLOCKED
REVIEW_REQUIRED
```

Do not claim success without verification evidence.

---

# 15. `/review` — ARCHITECTURAL REVIEW

Primary review role: **Codex**

Review:

```
Architecture Contract
+
Implementation Result
+
Git Diff
+
Test Evidence
+
Playwright Evidence
```

Do not reread the entire repository unless evidence indicates that additional context is necessary.

Review primarily for:

- architecture compliance;
- scope compliance;
- module/domain ownership;
- contract preservation;
- security;
- concurrency;
- idempotency;
- integration boundaries;
- unintended coupling;
- regression risk;
- acceptance criteria;
- test adequacy.

Return:

```
PASS
```

or:

```
CHANGES_REQUIRED
```

When changes are required, produce precise remediation requirements.

Do not automatically take over implementation.

Return remediation to `/implement`.

The loop becomes:

```
/review
   ↓
CHANGES_REQUIRED
   ↓
/implement
   ↓
DeepSeek repairs
   ↓
tests
   ↓
/review
```

---

# 16. `/release-gate` — DETERMINISTIC QUALITY GATE

The release gate should rely on deterministic checks wherever possible.

Evaluate relevant requirements such as:

```
Git state
unit tests
integration tests
contract tests
Playwright critical journeys
lint
static analysis
security checks
architecture lint
module validation
migration validation
unexpected file changes
unresolved review findings
release metadata
```

Return:

```
RELEASE_GATE=PASS
```

or:

```
RELEASE_GATE=BLOCKED
```

A mandatory failing check cannot be overridden merely because an AI believes the implementation is safe.

AI may explain failures.

AI should not redefine deterministic release policy.

---

# 17. AGENT RESPONSIBILITY MODEL

Maintain these boundaries:

```
USER
    Product direction
    Final authority

CODEX
    Architecture
    Constraints
    High-value reasoning
    Architectural review

DEEPSEEK
    Implementation
    Debugging
    Repair loops
    Test execution

LEAN-CTX
    Context selection
    Context compression
    Tool-output reduction

PI / EXECUTION HARNESS
    Files
    Commands
    Execution
    Agent runtime

PLAYWRIGHT
    Observable browser evidence
    E2E verification

UNIT / INTEGRATION TESTS
    Internal behavioral evidence

GIT
    Change ledger

RELEASE GATE
    Deterministic release authority
```

Do not unnecessarily duplicate responsibilities between agents.

---

# 18. TOKEN AND COST POLICY

Optimize for useful reasoning per token, not simply minimum token count.

Use expensive reasoning where architectural judgment provides value.

Use cheaper implementation models for mechanical development and iterative repair where appropriate.

Reduce waste by:

- retrieving only relevant context;
- summarizing verbose tool output;
- avoiding repeated repository scans;
- running targeted tests before broad suites;
- using browser evidence instead of speculative debugging;
- stopping unproductive repair loops;
- escalating architectural uncertainty;
- avoiding duplicate reasoning between agents.

Codex should generally not spend expensive reasoning cycles performing repetitive implementation/test loops that DeepSeek can execute reliably.

---

# 19. STABILITY PRINCIPLE

Do not optimize for identical generated code.

Optimize for stable contractual outcomes.

Different implementations are acceptable when they satisfy the same:

```
architecture
business behavior
security requirements
module boundaries
integration contracts
tests
Playwright journeys
performance expectations
release requirements
```

Tests, contracts, policies, and release gates are the source of stability.

---

# 20. WORKBENCH ROLE

Workbench is not another coding agent.

Do not duplicate capabilities already provided effectively by:

```
VS Code
Pi
DeepSeek
Codex
Lean-CTX
Playwright
Git
```

Workbench should evolve toward a:

```
DEVELOPMENT CONTROL PLANE
```

Its responsibilities may include:

- task state;
- architecture contracts;
- agent handoffs;
- implementation evidence;
- test results;
- Playwright traces;
- architecture violations;
- module impact;
- capability impact;
- integration impact;
- release readiness;
- audit history.

Conceptually:

```
Ikabud Kernel governs application modules.

Workbench governs AI-assisted development.
```

---

# 21. PRIMARY OPERATING PRINCIPLE

Use AI where judgment and implementation intelligence provide value.

Use deterministic software where rules can be enforced mechanically.

Prefer:

```
INTENT
  ↓
CONTRACT
  ↓
EXECUTION
  ↓
EVIDENCE
  ↓
REVIEW
  ↓
GATE
```

over:

```
PROMPT
  ↓
CODE
  ↓
HOPE
```

The objective is not maximum agent autonomy.

The objective is:

**high development velocity + low token waste + architectural stability + repeatable verification + controlled autonomy.**
