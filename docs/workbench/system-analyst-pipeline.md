# ARK Workbench System Analyst Pipeline

## Purpose

The System Analyst pipeline turns ARK Workbench from a module-specific browser script into a general application inspection and testing workflow. It learns its runtime test context from the module manifest, rendered navigation, semantic inspection manifest, and links exposed by the application. It does not invent entity IDs or assume PAL workflows.

## Pipeline

1. Static comprehension identifies pages, fields, actions, entities, tables, and data flows.
2. Runtime resolution collects declared navigation and rendered links.
3. Parameterized routes are materialized only with observed entity instances.
4. Routes without a valid instance are classified as unmet prerequisites, not defects.
5. Browser diagnostics run with a consistent semantic inspection surface.
6. Behavioral actions prefer stable `data-wb-action` ownership over visible-text guesses.
7. Experience analysis measures headings, accessible names, heading order, duplicate IDs, controls, forms, tables, and viewport overflow.
8. Evidence correlation combines console, HTTP, diagnostic, behavioral, and UX observations into fingerprinted issues.
9. The analyst report publishes process understanding, coverage, confidence, detailed evidence, and role-specific issue indexes.
10. The existing Comprehension Engine receives normalized evidence for deterministic, Bayesian, learned-case, and configured-AI analysis.

## UX evolution measurements

The pipeline measures product evolution using repeatable browser evidence:

- task duration, interaction count, clicks, field entries, failures, and successful steps;
- final navigation depth;
- keyboard focus sequence, unique controls reached, invisible focus targets, and focus-indicator presence;
- mobile (390 px) and tablet (768 px) horizontal overflow;
- whether actions visible on desktop remain available responsively;
- one-page-H1 structure, heading order, duplicate DOM IDs, and accessible control names;
- action-label consistency for the same semantic action key;
- casing and presentation variants for equivalent terminology.

The UX score starts at 100 and applies bounded penalties by category. Category caps prevent one widespread issue from hiding the state of all other dimensions. Raw metrics remain in the report, so the score is a summary rather than a replacement for evidence.

### Baseline modes

- `WB_UX_BASELINE_MODE=update` writes the approved module baseline.
- `WB_UX_BASELINE_MODE=check` compares the current result and reports improved, stable, or regressed.
- `WB_UX_MIN_SCORE=<number>` sets the minimum accepted score.
- The default `off` mode reports comparison data without changing the baseline.

Baselines are stored at `test_results/ux-baselines/{module-id}.json`. A regression contains the previous and current penalty for every worsened category.

## Generic route policy

- A navigation URL declared by the inspected module is testable.
- A URL discovered from a rendered link is testable.
- A parameterized route is testable only after a matching runtime entity link is observed.
- Reserved action segments such as `create`, `edit`, `new`, `reports`, and `settings` cannot satisfy an entity placeholder.
- An inferred template route that is neither declared nor observed is skipped.
- A parameterized route without an observed entity becomes an `unmet-prerequisite` note.

## Issue accuracy policy

Raw observations are preserved as evidence. User-facing issues are correlated by canonical URL, HTTP status, component, and normalized symptom signature. Dynamic numeric IDs are converted to `{id}` for recurrence matching. A console error and HTTP failure for the same request therefore become one issue with two evidence sources.

Every correlated issue contains:

- stable fingerprint;
- severity and classification;
- occurrence count;
- evidence kinds and raw evidence;
- expected and actual behavior;
- suspected cause and recommendation;
- confidence derived from independent evidence sources.

Confirmed outcomes should be written to the existing Issue Ledger and Case Memory as `confirmed-defect`, `false-positive`, `test-defect`, `environment`, or `unmet-prerequisite`. Only verified outcomes are promoted into durable learning.

## Analyst report contract

The JSON report uses schema `ark.system-analyst-report.v1` and is written to:

`test_results/analyst/{run-id}/system-analyst-report.json`

It includes:

- process understanding;
- static and runtime coverage;
- page-level UX metrics;
- correlated issues;
- confidence statements;
- indexes for web design, frontend, backend, product, and QA.

Role indexes reference the same issue fingerprints so teams work from one evidence set instead of conflicting reports.

## Commands

Focused deterministic regression:

```bash
node tests/browser/analyst/system-analyst.unit.js
php tests/workbench_phase2_test.php
php tests/workbench_phase3_test.php
php tests/workbench_phase4_test.php
php tests/workbench_phase5_test.php
php tests/workbench_phase6_test.php
```

Generic live module analysis:

```bash
ADMIN_USER=<user> ADMIN_PASS=<password> \
node tests/browser/run-workbench.js --module=<module-id> --gate=off
```

Establish and verify a UX baseline:

```bash
WB_UX_BASELINE_MODE=update node tests/browser/run-workbench.js --module=<module-id> --gate=off
WB_UX_BASELINE_MODE=check WB_UX_MIN_SCORE=70 node tests/browser/run-workbench.js --module=<module-id> --gate=off
```

The inspected module needs a `module.json`. A registered PHP comprehension provider is optional: deterministic analyst layers remain operational when no provider exists.

## PAL validation result

Run `20260716054715-ba268595` validated the generic pipeline against PAL:

- 54 statically understood pages;
- 36 runtime-observed pages;
- 263 dynamic checks passed;
- real expense and project IDs were discovered from rendered links;
- the generic Add Item flow selected the correct action and verified a new row;
- form submission created and redirected to a real Job Order;
- duplicate console/HTTP evidence was correlated;
- an analyst report was produced with role-specific references;
- the hybrid Playwright test passed.

Remaining PAL findings are product inputs, not implementation blockers for the generic pipeline. They include repeated dual-H1 page structure, unnamed interactive controls, broken links/routes exposed by the application, and prerequisite-dependent templates that need explicit process relationships.

## UX evolution validation

Baseline run `20260716061206-65feae5a` and comparison run `20260716061416-0c2f2f31` verified the evolution loop:

- score: 48/100;
- comparison: stable, delta 0, no regressions;
- task: 4 interactions, 4 successful steps, 0 failures;
- keyboard: no invisible focus targets in the inspected pages;
- responsive: no verified loss of desktop-visible primary actions and no measured overflow in the sampled page types;
- primary penalties: duplicate page-level H1 structure, unnamed controls, and inconsistent action terminology;
- responsive false-positive logic was discovered during the first baseline attempt, corrected, and rerun before accepting the baseline.

## PAL 80-point remediation

PAL repair loops retained the original 48-point baseline and enforced `WB_UX_MIN_SCORE=80`:

1. Run `20260716074857-2dd30b5c` improved the score from 48 to 74. It removed the competing shell `h1`, standardized Docs/Create Invoice terminology, and introduced accessible naming for legacy and dynamic PAL controls. The unchanged gate continued to fail.
2. The remaining accessibility shortfall revealed that select controls with option text were incorrectly skipped by the progressive naming helper. That logic and decorative-icon terminology normalization were corrected.
3. Run `20260716075217-cace864a` reached 80, improved 32 points over baseline, reported no category regressions, and passed the strict gate.

Final measured penalties are limited to 20 unnamed controls on the dashboard, project form, quotation form, and sales form. Heading, structure, responsive, keyboard, terminology, and task-effort penalties are zero. These remaining controls form the next accessibility hardening backlog; they do not prevent the approved 80-point threshold from passing.

## PAL final accessibility hardening

Focused run `20260716080321-2630707b` identified every remaining unnamed control and verified the repairs:

- dashboard: ten empty Recent Projects links were not merely accessibility omissions—the handler supplied recent audit events to a project-list template. PAL now queries and supplies typed recent-project rows separately from recent activity;
- project form: the mockup file input now has an explicit upload name;
- quotation form: the dynamic material selector now has an explicit name;
- sales form: all eight dynamic line-item controls now expose their table-column meaning.

The focused four-page inventory returned zero unnamed controls. Full run `20260716080334-29848ff7` then scored 100/100, improved 52 points over the unchanged baseline, passed the 80-point gate, and reported zero UX penalty in every measured category.
