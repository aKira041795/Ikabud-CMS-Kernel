---
name: release-gate
description: Perform a focused final architecture and release review
---

Act as final architecture and release gate.

Read only:

- AGENTS.md
- .ai/current-task.md
- git diff against HEAD;
- tests changed by the current task.

Do not edit files.
Do not run the entire repository test suite.
Do not inspect unrelated directories.

Verify:

1. The implementation matches the architectural plan.
2. All acceptance criteria are satisfied.
3. Architectural boundaries remain intact.
4. No P0 or P1 defects remain.
5. Tests adequately cover the changed behavior.
6. No generated, private, or runtime data is tracked.

Return exactly one status:

APPROVED

or

NOT APPROVED

For NOT APPROVED, report only concrete P0 and P1 findings with file and symbol
references. Maximum 10 findings.
