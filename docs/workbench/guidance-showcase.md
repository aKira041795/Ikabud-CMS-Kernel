---
description: Independent Guidance proof for the ARK Workbench contract, comprehension, browser evidence, and Superadmin cockpit
---

# Guidance Workbench showcase

Guidance is the second complete ARK Workbench proof module. It uses the same generic contract service, Comprehension adapter, browser launcher, evidence identity, and Superadmin correlation path as every other module. No Guidance branch exists in Workbench core.

## Proof contract

The module owns `modules/guidance/workbench-contract.json`. Its `guidance-independent-showcase` scenario requires:

- an authenticated Workbench shell;
- successful traversal of every declared Guidance admin navigation URL;
- desktop and mobile viewport evidence;
- correlation under the canonical contract run ID.

The browser implementation is `tests/browser/modules/guidance/showcase.spec.js`. It receives `WB_RUN_ID`, `ARK_MODULE`, `MODULE`, and `HYBRID_GATE` from the contract service and fingerprints the manifest, contract, and Comprehension provider.

## Deterministic preflight

Run these before provisioning a browser environment:

```bash
php ikabud workbench:validate guidance
php ikabud workbench:doctor guidance
```

Both commands must pass before the browser process starts. A failed contract, route claim, navigation dependency, or release gate blocks execution and remains explainable.

## Comprehension proof

`modules/guidance/WorkbenchComprehensionProvider.php` uses the generic `ContractComprehensionProvider`. The provider exposes Guidance-owned routes, workflows, actions, effects, invariants, and both declared scenarios to the updated ARK Comprehension Engine.

The portability regression is:

```bash
php tests/workbench_competitive_phase3_test.php
```

It proves registry discovery, contract adaptation, scenario visibility, browser-target ownership, timeout policy, and required run-correlation evidence.

## Browser proof

A real browser run requires the full application environment: Node and Playwright browsers, the Guidance web host, database fixtures, and valid admin credentials. The lightweight Workbench Docker runner intentionally does not claim this capability.

With that environment available, run:

```bash
php ikabud workbench:run guidance --gate=critical
```

The contract service enforces the declared browser timeout and terminates the browser process group on expiry. Its durable report records outcome, timing, exit code, timeout state, and an output digest even when execution fails partway through.

## Evidence and cockpit

The canonical report is written to:

```text
storage/workbench/runs/<WB_RUN_ID>.json
```

Reporter and other ARK engines use the same `WB_RUN_ID`. Superadmin Workbench shows a contract-only report immediately, then correlates later Reporter, Analyst, Comprehension, Scenario, and Pattern Intelligence artifacts into one hybrid drilldown. The contract panel preserves the gate, targets, exits, durations, and timeout evidence.

Use `workbench:explain` with the emitted run ID when a run is blocked, fails, or times out. Contract failure remains a release blocker; missing browser infrastructure is environment evidence, never converted into a passing result.
