---
name: review
description: Review and harden the current implementation
---

Act as a senior implementation reviewer.

Read:

- AGENTS.md
- .ai/current-task.md
- the current uncommitted Git diff

Check for:

- unmet acceptance criteria;
- Kernel or module boundary violations;
- tenant isolation issues;
- security regressions;
- false-success behavior;
- swallowed failures;
- concurrency defects;
- migration problems;
- insufficient tests;
- unrelated changes;
- tracked generated or private artifacts.

Fix confirmed P0 and P1 findings.
Fix P2 findings only when clearly within scope.
Do not perform unrelated refactoring.

Run focused tests afterward.

Append the result to .ai/current-task.md under:

## Developer Review

Include:

- findings corrected;
- findings rejected and why;
- tests run;
- remaining release risks.
