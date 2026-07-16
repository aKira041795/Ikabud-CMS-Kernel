# ARK Scenario and Test Data Engine

The Scenario Engine lets a human tester add investigation directions and questions without replacing ARK's automatic discovery. Directions are evaluated across design, logic, and semantics; questions remain explicit until evidence supports an answer or a human validates the conclusion.

## Human workflow

1. Write a scenario proposal as JSON or submit a short question/direction through the CLI.
2. ARK compiles and validates a versioned scenario contract.
3. The data provider creates a run-isolated namespace and returns a seed receipt.
4. ARK verifies the seed fingerprint before browser execution.
5. Automatic discovery continues normally; human directions focus additional evidence correlation.
6. The report separates evidence-linked answers from items requiring human validation.
7. ARK verifies post-test drift, preserves the evidence, and cleans tracked scenario data.

No scenario may contain arbitrary SQL or shell commands. Workbench consumes module-owned providers through `workbench.scenario.describe@1`, `seed@1`, `verify@1`, and `cleanup@1`. The built-in JSON sandbox remains an explicit `--provider=sandbox` fallback for modules that do not yet expose the capability contract.

## Commands

```bash
php kernel/Workbench/Scenario/run.php propose \
  --module=project-audit-ledger \
  --input=tests/fixtures/scenarios/pal-human-guided.json

node tests/browser/run-workbench.js \
  --module=project-audit-ledger \
  --scenario=pal-human-guided-approval \
  --gate=off
```

Run artifacts are stored under `test_results/scenarios/<run-id>/`. Persisted proposals are stored privately under `storage/private/workbench/scenarios/<module>/`.

## Trust boundary

- ARK may answer a question only with linked run evidence.
- Ambiguous design or semantic judgments are marked `requires-human-validation`.
- Seed drift blocks readiness; it is never silently repaired during a run.
- Cleanup failure is a distinct runner failure.
- Real application seeding requires a reviewed module adapter; the generic engine cannot execute user-provided code.

Interim diagnostic matches are labeled `provisionally-supported`. The final Workbench Reporter reevaluates every direction against its correlated browser evidence and writes `scenario-guidance.json`; a provisional page match can never overrule a final HTTP failure.

## PAL acceptance

Guided run `20260716090733-049d3b76` passed its browser lifecycle and retained the 100/100 UX telemetry while carrying two tester questions and two focused directions through the evidence bridge. Seed preconditions and postconditions matched, no drift occurred, and cleanup completed. The gap loop found and corrected an interim-evidence defect where a discovered issuance page could be labeled supported before the final reporter recorded its HTTP 404; final guidance is now reporter-owned.

## Kernel OS ownership correction

Scenario execution now defaults to module capabilities rather than a Workbench-owned data implementation:

- `workbench.scenario.describe@1`
- `workbench.scenario.seed@1`
- `workbench.scenario.verify@1`
- `workbench.scenario.cleanup@1`

The headless runner activates only capabilities explicitly declared by the requested module manifest and invokes them through the Kernel capability bus with caller `kernel.workbench`. PAL publishes `testing/domain-contracts.v1.json` and rejects undeclared entity types or expense/receivable data that violates its contract. Workbench governs execution while PAL retains business authority; DiSyL remains the renderer of declared state.
