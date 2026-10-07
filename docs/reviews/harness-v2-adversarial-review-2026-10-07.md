# Harness v2 adversarial review — 2026-10-07

**status: FINDINGS**

## Scope and method

I reviewed the diff from `2c927e08` through `HEAD`, the three published probes, and the new
selftests. I treated each guard as a mutation target. Before every mutation I copied the affected
file to `/tmp/rev-lane.sh` or `/tmp/rev-lane-model.sh`; after that run I restored it immediately.

Baseline evidence:

```text
$ bash tools/harness-acceptance-verify-probe.sh
== probe: 4 passed, 0 failed ==
$ bash tools/harness-changed-files-probe.sh
== probe: 4 passed, 0 failed ==
$ bash tools/harness-chain-liveness-probe.sh
== probe: 5 passed, 0 failed ==
$ bash tools/lane.sh selftest
== selftest: 28 passed, 0 failed ==
$ bash tools/lane-model-selftest.sh
selftest: 19 passed, 0 failed
```

Before selftest, `pgrep -af '[l]ane-harness-'` named only this review's own
`bash tools/lane-harness-review.sh` path (PID 100623 and its parent dispatch), not another lane.

## Guard-by-guard mutation results

Every row below is a **RUN**, not a code-reading inference. “Red” means the published probe or
selftest rejected the mutation. Commands shown are the test command run while the stated temporary
mutation was installed.

| Guard | Mutation expected to make it red | Mode | Observed result and actual output |
|---|---|---:|---|
| probe-A | Disable the `acceptanceSupplied` post-landing execution block, deleting the re-run. Command: `bash tools/harness-acceptance-verify-probe.sh`. | RUN | **RED** — `FAIL A ... acceptance='SKIPPED' verdict='EXECUTION_ONLY'`; `probe: 1 passed, 3 failed`. |
| probe-B | After `status_line`, force `acceptance=FAIL` when status contains `FAIL`, letting self-report govern. Same probe command. | RUN | **RED** — `FAIL B the self-report overrode the verifier (acceptance='FAIL' verdict='NOT_VERIFIED')`; `probe: 3 passed, 1 failed`. |
| probe-C | Initialize absent acceptance to `PASS` instead of `SKIPPED`. Same probe command. | RUN | **RED** — `FAIL C ... acceptance='PASS' verdict='VERIFIED'`; `probe: 3 passed, 1 failed`. |
| probe-D | Return 1 from `cmd_run` when `verdict=NOT_VERIFIED`, rather than returning the lane status. Same probe command. | RUN | **RED** — `FAIL D the exit-code contract changed (rc=1 acceptance='FAIL')`; `probe: 3 passed, 1 failed`. |
| S12 | Disable the supplied acceptance-command block. Command: `bash tools/lane.sh selftest`. | RUN | **RED** — `FAIL S12 ... acceptance=SKIPPED verdict=EXECUTION_ONLY`; `selftest: 25 passed, 3 failed`. |
| S12b | Force `acceptance=FAIL` from a `status: FAIL` line. Same selftest command. | RUN | **RED** — `FAIL S12b advisory status overrode acceptance (acceptance=FAIL verdict=NOT_VERIFIED)`; `selftest: 27 passed, 1 failed`. |
| S12c | Default the no-command path to `acceptance=PASS`. Same selftest command. | RUN | **RED** — `FAIL S12c ... rc=0 acceptance=PASS verdict=VERIFIED`; `selftest: 27 passed, 1 failed`. |
| S13 | Restore content-first quota detection before the exit-status branch. Same selftest command. | RUN | **RED** — `FAIL S13 ... (rc=0, reason=quota)`; S13b remained PASS; `selftest: 27 passed, 1 failed`. |
| S13b | Disable quota-pattern classification in the nonzero-exit branch. Same selftest command. | RUN | **RED** — `FAIL S13b ... (rc=1, reason=crash)`; S13 remained PASS; `selftest: 27 passed, 1 failed`. |
| S14 | Force `ATTR_PATHS_JSON="[]"`, losing the lane-owned path. Same selftest command. | RUN | **RED** — `FAIL S14 attributed the pre-existing stray or lost the lane's own path`; `selftest: 27 passed, 1 failed`. |
| S14b | Force an empty delta's `ATTR_CHANGED` from 0 to 1. Same selftest command. | RUN | **RED** — `FAIL S14b an idle lane was credited with changed paths`; `selftest: 27 passed, 1 failed`. |
| S14c | Change the no-baseline legacy basis from `tree` to `delta`. Same selftest command. | RUN | **RED** — `FAIL S14c legacy record without a baseline broke`; `selftest: 27 passed, 1 failed`. |
| L1 | Replace the derived cap with 20 seconds, allowing the 12-second first stub to complete. Command: the L1 fixture invocation from the probe. | RUN | **RED** — `attempt 1/2: hang; cap=20s`, `used=hang rc=0`, `GUARD L1 RED`. |
| L2 | Likewise set cap 20 for `hang,hang,ok`, so model 1 completes instead of model 3 being reached. Command: the L2 fixture invocation. | RUN | **RED** — `attempt 1/3: hang; cap=20s`, `used=hang rc=0`, `GUARD L2 RED`. |
| L3 | Replace the explicit-timeout branch condition with `false`, making the derived 3000-second cap win. Command: the L3 fixture invocation. | RUN | **RED** — `used=hang elapsed=12`, `GUARD L3 RED`. |
| L4 | Remove the word `timeout` from both per-attempt and final timeout diagnostics. Command: the L4 fixture and published assertion. | RUN | **RED** — two `exit=124` attempts and `used= rc=1`, but only `attempt was cut`; `GUARD L4 RED`. |
| L5 | Remove division by attempts-left: `attempt_cap=$remaining`. Command: `bash tools/harness-chain-liveness-probe.sh`. | RUN | **RED** — `FAIL L5 the run overspent its budget: elapsed=17s > 15s`; `probe: 4 passed, 1 failed`. |
| D1 | Set `ATTR_CHANGED=$ATTR_TREE_DIRTY`, restoring the whole-tree count. Command: `bash tools/harness-changed-files-probe.sh`. | RUN | **RED** — `FAIL D1 ... changed_files='3' basis='delta' dirty_before='2'`; `probe: 1 passed, 3 failed`. |
| D2 | Replace `comm -13 "$before" "$now"` with `cp "$now" "$delta"`, claiming pre-dirty paths. Same probe command. | RUN | **RED** — `FAIL D2 ... changed_files='2' basis='delta' tree_dirty='2' dirty_before='2'`; `probe: 1 passed, 3 failed`. |
| D3 | Force `ATTR_CHANGED=1` whenever the computed delta is empty. Same probe command. | RUN | **RED** — `FAIL D3 an idle lane was credited (changed_files='1' basis='delta')`; `probe: 2 passed, 2 failed`. |
| D4 | Change only the legacy no-baseline basis from `tree` to `delta`, violating D4's stated requirement while retaining valid JSON. Same probe command. | RUN | **GREEN — FINDING** — `PASS D4 PIN: ... valid JSON (basis='delta')`; all directions passed: `probe: 4 passed, 0 failed`. |

**Totals: 21 guards attacked; 21 mutation runs; 20 mutations turned their target red; one did
not (D4).**

## Findings

### D4 — the probe does not enforce its stated legacy basis

What is wrong: D4's direction says the no-baseline record's basis must be `tree`, but its condition
checks only record exit status and JSON validity. `basisD4` is printed, not asserted. Thus a record
that lies about its basis is certified.

Command (with temporary `ATTR_BASIS="delta"` in the legacy branch):

```text
$ bash tools/harness-changed-files-probe.sh
PASS D4 PIN: a legacy record with no baseline is valid JSON (basis='delta')
== probe: 4 passed, 0 failed ==
```

Observed: **GREEN**. This guard failed to turn red. S14c does catch the same mutation, but that does
not repair D4's false claim or make D4 independently discriminating.

### L5 — the minimum cap can overspend the advertised whole-run budget

What is wrong: the implementation computes a share, then raises it to
`LANE_MODEL_TIMEOUT_MIN` even when that exceeds all remaining budget. L5 tests only a budget/min
combination where the floor is harmless. This contradicts the stated whole-run invariant for valid
positive settings.

Command on the unmodified implementation:

```text
$ LANE_MODEL_CMD=/tmp/lane-chain-liveness-stub.sh LANE_MODEL_BUDGET=1 \
  LANE_MODEL_TIMEOUT_MIN=2 LANE_MODEL_TIMEOUT='' LANE_MODEL_CHAIN='hang,ok' \
  bash -c 'source tools/lane-model.sh; t0=$SECONDS; lane_model_run "$LANE_MODEL_CHAIN" p \
  /tmp/lm-rev-floor >/dev/null 2>&1; echo "used=$LANE_MODEL_USED elapsed=$((SECONDS-t0)) budget=$LANE_MODEL_BUDGET"'
used=ok elapsed=2 budget=1
```

Observed: the whole run used 2 seconds against a 1-second run budget. The division-removal mutation
is caught by L5, but the implementation still violates the generalized contract through its floor.

## Required questions

### 1. Any guard that cannot fail?

**Yes. D4's `basis=tree` assertion cannot fail because it is not an assertion at all:** the
`basis=delta` mutation above stayed green. D4 and L3 are also explicitly labelled PINs that pass on
the pre-change tree by design; L3 is not decorative because its targeted “ignore explicit timeout”
mutation did turn it red. D4 still detects malformed/missing JSON, but not the basis requirement it
claims to pin.

### 2. Does implementation contradict its own comment or evidence?

**Yes, in the model budget path.** `grep -nE 'attempt_cap=|LANE_MODEL_TIMEOUT_MIN'
tools/lane-model.sh` shows the share at line 169 and the unconditional minimum floor at line 170.
The measured `elapsed=2 budget=1` result contradicts “divide the remaining run budget” and the
probe's “THE WHOLE RUN must finish inside its budget” statement. I found no acceptance-verdict or
classification comment contradicted by the measured normal-path evidence.

### 3. Is the exit-code boundary intact?

**Yes on the unmodified tree.** The baseline probe-D says:

```text
PASS D a clean execution with a failing criterion still returns 0 (verdict is reported, not encoded)
```

The mutation that returned 1 for `NOT_VERIFIED` made probe-D red with `rc=1 acceptance='FAIL'`.
That is the exact case that would break. Source evidence is `tools/lane.sh:766: return "$exitCode"`.
S3 remains the opposite-direction check for a crashing lane.

### 4. Was anything over-built?

**No prohibited second framework found.** The implementation re-runs the existing shell acceptance
command once, derives a small three-value verdict, snapshots status paths, and divides a budget.
There is no worktree, ownership registry, content/mtime attribution, richer state machine, or NLP
interpretation of status text. The intentionally missing capability is attribution of a second edit
to a path already dirty at dispatch; the code reports that ambiguity instead of adding hashing or
ownership machinery. The L5 floor defect is incorrect arithmetic, not over-building.

### 5. Does the legacy/self-hosting path hold?

Yes. I invoked the legacy four-argument form directly and parsed the result:

```text
$ bash tools/lane.sh record review-legacy .ai/runs/review-legacy.log 0 legacy
[lane record] review-legacy: state=landed reason=report_present exit=0 acceptance=SKIPPED bytes=89 files=0
$ php -r '/* decode and print fields */' .ai/runs/review-legacy.landed.json
json=valid basis=tree acceptance=SKIPPED verdict=EXECUTION_ONLY
record_rc=0
```

This proves valid JSON and graceful tree-basis degradation for the generated runner's legacy call.
S14c independently passed on the baseline and turned red when that basis was mutated.

## Restoration

Final restoration checks:

```text
$ bash -n tools/lane.sh && bash -n tools/lane-model.sh
(exit 0)
$ git status --porcelain -- tools/lane.sh tools/lane-model.sh tools/lane-watch.sh
(no output)
```
