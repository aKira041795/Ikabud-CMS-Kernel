# Autonomy ledger and bounded repair loop

Three tools make autonomous work **measurable** before it is made broader:

| Tool | Purpose |
|---|---|
| `tools/autonomy-contract.py` | Refuses an unusable task before dispatch: a tautological or already-passing criterion, or high-risk paths declared routine. |
| `tools/autonomy-ledger.py` | Records every task's outcome and reports the autonomy rate. |
| `tools/lane-autonomy.py` | Runs one task, then a bounded number of repair attempts when its own acceptance criterion fails. |

All three are additive. `tools/lane.sh` remains the dispatcher; it still refuses to dispatch without a
failing acceptance criterion and a `--pass-looks-like` description.

## 1. The ledger

Records are append-only JSON lines in `.ai/autonomy/ledger.jsonl` (inside the gitignored `.ai/`
directory). The **latest record per task wins**, so updating a task is a re-record, not an edit.

```bash
python3 tools/autonomy-ledger.py report
python3 tools/autonomy-ledger.py report --json --window 50
python3 tools/autonomy-ledger.py list
python3 tools/autonomy-ledger.py --dir /tmp/ledger report    # --dir is global: before the subcommand
```

### Record fields

| Field | Values | Meaning |
|---|---|---|
| `task` | id | Stable task identifier. |
| `class` | `routine`, `high-risk` | Only routine tasks can count as autonomous. |
| `started_at`, `finished_at` | ISO-8601 | Wall-clock bounds (UTC). |
| `owner_interventions` | integer | Times a human had to unblock or correct the work. |
| `intervention_reasons` | list | Why (for example `merge`, `approval`, `ambiguous-acceptance`). |
| `attempts` | integer | Implementation + repair attempts. |
| `acceptance` | `PASS`, `FAIL`, `BLOCKED`, `SKIPPED`, `UNKNOWN` | Did the task's own criterion pass? |
| `gate` | `PASS`, `BLOCKED`, `SKIPPED`, `UNKNOWN` | Did the release gate pass? |
| `merged` | boolean | Was the result landed? |
| `rolled_back` | boolean | Was the landing reverted? |
| `cost_usd` | number | Optional spend. |
| `notes` | text | Short human-readable reason. |

### What counts as autonomous

A task is autonomous only when **all** of these hold:

- `class = routine`;
- `owner_interventions = 0`;
- `acceptance = PASS`;
- `gate = PASS`;
- `merged = true`;
- `rolled_back = false`.

The rate is `autonomous / routine`. High-risk tasks are reported separately and never inflate the
rate; a rollback removes the task from the autonomous set.

`gate = SKIPPED` deliberately does **not** count. A verified tree that has not passed the release
gate is not an autonomous delivery, and pretending otherwise is how a measurement becomes a
vanity metric.

## 2. The task contract

`tools/autonomy-contract.py` validates a task before a token is spent and writes a JSON contract
that `tools/lane-autonomy.py --contract` consumes.

```bash
python3 tools/autonomy-contract.py \
  --task fix-import \
  --objective "Fix the import so a duplicate row is rejected with a clear error." \
  --acceptance "php tests/import_contract_test.php" \
  --pass-looks-like "the contract test prints OK and exits 0" \
  --touches "modules/import/,tests/import_contract_test.php"
```

It refuses:

- an acceptance command that cannot fail (`true`, `:`, `exit 0`, a bare `echo`, or a trailing
  `|| true`);
- an acceptance command that **already passes** on the current tree, because a criterion that is
  already satisfied cannot prove the change;
- a `--class routine` declaration when a touched path is high-risk (`kernel/`, migrations,
  `config/`, `public/index.php`, `bootstrap.php`, module manifests, lane-harness tooling,
  workflows/instructions, or auth/payment/security/crypto/JWT paths).

With no `--class`, the classifier chooses `high-risk` when any touched path matches; otherwise
`routine`. The probe can be skipped only with `--no-probe="<reason>"`, which is recorded in the
contract. The contract is written to `.ai/autonomy/contracts/<task>.json`.

## 3. The bounded repair loop

```bash
# preferred: validate once, then hand the contract to the loop
python3 tools/lane-autonomy.py --contract .ai/autonomy/contracts/fix-import.json --max-attempts 2

# or pass the fields directly
python3 tools/lane-autonomy.py run \
  --task fix-import \
  --objective "Fix the import so a duplicate row is rejected with a clear error." \
  --acceptance "php tests/import_contract_test.php" \
  --pass-looks-like "the contract test prints OK and exits 0" \
  --touches "modules/import/,tests/import_contract_test.php" \
  --max-attempts 2
```

The loop:

1. **Attempt 1** writes a task prompt (objective, allowed scope, fixed acceptance criterion) and
   dispatches a lane through `tools/lane.sh run`.
2. If the lane exits without `completion = PASS`, the criterion is verified directly. A zero exit
   alone is **not** success.
3. On failure, a compact **failure bundle** is assembled from the landing record, acceptance log,
   lane log, events, the tails of `storage/logs/app.log` and `storage/logs/error.log`, and the
   diff limited to `--touches`.
4. **Repair attempts** receive the same criterion plus that bundle. The prompt forbids changing the
   criterion or widening the scope.
5. The outcome is recorded in the ledger.

### Stop conditions

| Condition | Exit | Ledger `acceptance` |
|---|---|---|
| Acceptance passes | `0` | `PASS` |
| Model quota, fatal error, empty report, timeout, unverified landing | `3` | `BLOCKED` |
| Policy violation (lane exit `97`) | `3` | `BLOCKED` |
| Overall wall-clock cap reached | `3` | `BLOCKED` |
| Identical failure repeats (same acceptance hash) | `3` | `FAIL` |
| Maximum attempts reached | `1` | `FAIL` |
| Zero exit but the marker never says `completion = PASS` and the criterion still fails | `3` | `FAIL` |

The identical-failure rule is what prevents the endless `edit -> test -> edit` loop: if two
attempts produce the same acceptance output, more attempts are speculation, not repair.

### What the loop does not do

It never merges, pushes, or runs the release gate. It produces a verified tree and a ledger
record; landing stays a separate, risk-gated step. Until that step re-records the task with
`gate=PASS` and `merged=true`, the autonomy rate reports `0%` — which is the honest state of a
harness that can verify but has not yet been trusted to land.

```bash
# after a successful landing + release gate
python3 tools/autonomy-ledger.py --dir .ai/autonomy record fix-import \
  merged=true gate=PASS
```

## 4. Dry runs

```bash
python3 tools/lane-autonomy.py --contract .ai/autonomy/contracts/fix-import.json --dry-run
```

`--dry-run` writes the attempt-1 prompt (and, without `--lane-script`, the generated lane script)
but dispatches nothing and writes **no ledger record**. Use it to inspect the prompt composition
without spending tokens.

## 5. Tests

```bash
python3 -m unittest discover -s tools/tests -p 'test_autonomy_contract.py'  # 6 cases
python3 -m unittest discover -s tools/tests -p 'test_autonomy_ledger.py'    # 5 cases
python3 -m unittest discover -s tools/tests -p 'test_lane_autonomy.py'     # 3 integration cases
bash tools/lane-harness-bench.sh                                          # all harness gates
```

The contract tests cover a valid contract, a tautology, an already-passing criterion, a high-risk
path declared routine, an explicit high-risk class, and the loop consuming the contract in a dry
run. The repair-loop tests use a stub lane that fails once and then creates the acceptance flag, a
stub that always fails (identical-failure detection), and a dry run (no record).
