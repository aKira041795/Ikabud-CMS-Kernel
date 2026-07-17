---
name: implement
description: Implement the current architect-approved task
---

Act as the primary implementation developer.

Read:

- AGENTS.md
- .github/copilot-instructions.md
- .ai/current-task.md

Implement the current task.

Rules:

- Follow the task file exactly.
- Do not redesign the approved architecture.
- Do not broaden scope.
- Preserve Kernel, module, ARK, DiSyL, tenancy, capability, ownership,
  migration, and rendering boundaries.
- Add focused tests for all changed behavior.
- Run the smallest relevant test set first.
- Fix failures introduced by this change.
- Do not commit or push.
- Do not modify .ai/current-task.md.

Before finishing:

1. Review the uncommitted diff.
2. Remove generated or private runtime artifacts.
3. Confirm every acceptance criterion.
4. Run focused validation again.

Append an implementation report to:

.ai/current-task.md

Use this heading:

## Implementation Report

Include:

- files changed;
- tests run;
- results;
- deviations;
- remaining risks.
