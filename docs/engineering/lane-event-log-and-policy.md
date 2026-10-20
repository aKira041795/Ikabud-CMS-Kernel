# Lane Event Log and Role Policy

Harness primitives added on 2026-10-20, after evaluating DeepSeek Harness (`dsh`) and the
permission/hook models of Claude Code, Cline, Kiro, Aider, and SWE-agent. They are implemented
natively in the existing harness; no new runtime was adopted.

The evaluation is in
`.ai/consult/` (gitignored) and the durable decision record is
[ADR-007](../architecture/decisions/ADR-007-protocol-first-host-portability.md).

## 1. Event-sourced run log

### Why

Lane outcomes used to live in four places that could disagree:

- `.ai/runs/<lane>.log` — the wrapper's log (not a progress signal);
- `/tmp/<prefix>-<model>.log` — the model's own output (where a report often actually is);
- `.ai/runs/<lane>.landed.json` — the marker;
- `.ai/runs/landings.jsonl` — the append-only landing journal.

A successful lane could be reported as `no_report`, and a lane that did the work could be called a
timeout. The event log makes the run itself the source of truth, so those labels can be checked
against evidence instead of inferred from one file.

### Where

`.ai/runs/<lane>.events.jsonl` — one JSON object per line, append-only, locked with `flock`.
Path resolution for direct use: `--runs-dir`, `LANE_RUNS_DIR`, the nearest `.ai/runs`, or
`./.ai/runs`.

### Events

| Event | Written by | Fields |
|---|---|---|
| `run_started` | `tools/lane.sh run` | role, lane, timeout, acceptance, pass_looks_like, touches |
| `attempt_started` | `tools/lane-model.sh` | model, attempt, cap |
| `attempt_finished` | `tools/lane-model.sh` | model, exit, log, bytes, duration |
| `model_completed` | `tools/lane-model.sh` | model, log, bytes |
| `attempt_failed` | `tools/lane-model.sh` | model, reason (timeout/unavailable/crash), exit |
| `policy_violation` | `tools/lane.sh record` | role, touches, detail |
| `run_finished` | `tools/lane.sh record` | state, reason, exit_code, acceptance, log, bytes, status_line, changed_files, run_id, role |

### Commands

```bash
tools/lane.sh events <name>              # human timeline + JSON summary
tools/lane.sh events <name> --json       # summary only
tools/lane-events.py replay <name>       # same, without lane.sh
tools/lane-events.py verify <name>       # contradiction check (exit 1 on a contradiction)
tools/lane-events.py list                # runs with event logs
```

`verify` fails when:

- the landing says `no_report` but a completed model output exists (the report is probably in the
  model log, not the wrapper log);
- an attempt's recorded model log is missing on disk;
- an attempt exited 0 but no `model_completed` event exists;
- a `policy_violation` was recorded while `run_finished.exit_code` is 0.

The event log is **fail-open**: if `python3` or the tool is missing, the lane still runs and records
through the legacy marker/journal path.

## 2. Runtime-enforced lane roles

### Why

"Sol is read-only", "the architect must not edit", and "no lane may push or merge" were
conventions in prompts and instructions. A convention is not a control.

### Roles

| Role | PI tool flags | Intent |
|---|---|---|
| `architect` | `--exclude-tools edit,write` | inspect and reason; never edit |
| `review` | `--exclude-tools edit,write` | read the diff; never edit |
| `release-gate` | `--exclude-tools edit,write` | verify; never edit |
| `implement` | none | full tools; path scope enforced by `--touches` |

### What is enforced

1. **Tool registry.** Read-only roles lose the mutating built-ins.
2. **Git guard.** A temporary `git` wrapper is prepended to `PATH` for the lane. It refuses
   `push`, `merge`, `rebase`, `filter-branch`, `filter-repo`, `reset --hard`, `clean -f*`,
   destructive `remote` operations, and branch/tag deletion. It passes everything else through.
3. **Per-file snapshot.** Before the lane, `git status --porcelain -z` plus a content hash per path
   is written to `.ai/runs/<lane>.policy.before`. After the lane, the same snapshot is compared:
   - `review` / `architect` / `release-gate`: any new, removed, or modified path is a violation;
   - `implement`: changed paths must match `--touches` prefixes or globs.
4. **Exit 97.** A violation sets the lane exit to 97, appends `POLICY_VIOLATION` to the lane log, and
   records a `policy_violation` event. The landing is still recorded, so the evidence is not lost.

### Usage

```bash
tools/lane.sh run my-lane tools/lane-my.sh \
  --role=review \
  --acceptance="php tests/my_contract_test.php" \
  --pass-looks-like="the contract test prints OK"

tools/lane.sh run my-impl tools/lane-my-impl.sh \
  --role=implement \
  --touches="modules/my-module/,tests/my_contract_test.php" \
  --acceptance="php tests/my_contract_test.php" \
  --pass-looks-like="the contract test prints OK"
```

### Limits — read these before trusting it

- The git guard is **PATH-based**, not an OS sandbox. A lane that invokes `/usr/bin/git` directly
  bypasses it. The postcheck still detects the resulting tree change, but the command itself ran.
- Read-only roles remove `edit`/`write`; a bash command such as `sed -i` can still modify a file.
  The postcheck catches it and fails the lane.
- `--touches` matches path prefixes and globs; it is not a capability system.
- The snapshot only sees paths git reports. Changes to ignored files are outside the check.
- A lane can always be run without `--role`; the default is `implement`. Use roles for the lanes
  where the constraint matters.

## 3. Completion gate

`state: landed` says the process held an exit status; it does not say the work met its bar. The
completion gate records that distinction on every landing:

| `completion` | Meaning |
|---|---|
| `PASS` | exited 0 **and** the supplied acceptance criterion passed |
| `UNVERIFIED` | exited 0, but the acceptance gate was bypassed (`--no-acceptance-gate`) |
| `BLOCKED` | non-zero exit, or the criterion failed / timed out / errored |

It is written to the marker (`completion`, `completion_detail`), the journal line, and the
`run_finished` event. A `BLOCKED` landing also emits a `completion_blocked` event. `verify` treats
`completion=BLOCKED` with `exit_code=0` as a contradiction — the exact case where a cheerful
`landed` used to be read as success.

```bash
tools/lane.sh status <name>     # completion + detail, next to acceptance and verdict
tools/lane.sh list              # completion column
```

`tools/lane-watch.sh` prints the completion verdict for every landing and raises its urgency to
`critical` when it is `BLOCKED`, so a lane that exited 0 while failing its criterion cannot be
mistaken for success by a watcher that only sees the state line.

For callers that check only the return code, `run --strict-completion` returns **4** when a clean
exit is `BLOCKED`. It is opt-in because the existing fixture corpus deliberately dispatches lanes
with an always-failing criterion to exercise the pre-dispatch gate.

## 4. Checkpoint and rewind

Before the lane writes anything, the generated runner calls `lane.sh checkpoint <name>`, which
captures recoverable pre-lane state:

- `.ai/runs/<name>.checkpoint.patch` — tracked changes vs `HEAD`, binary-safe;
- `.ai/runs/<name>.checkpoint.untracked.tgz` — untracked, non-ignored files;
- `.ai/runs/<name>.checkpoint.json` — `head`, sizes, availability.

Checkpointing is non-fatal: a harness that cannot snapshot must not block the work.

```bash
tools/lane.sh rewind <name>              # dry-run: what would be restored
tools/lane.sh rewind <name> --execute    # stash current work, restore the checkpoint
```

`rewind` is deliberately dry-run by default. `--execute` first stashes the **current** changes
(including untracked files), so the operation is reversible even when it goes wrong; the stash ref is
printed. It refuses when `HEAD` moved since the checkpoint unless `--force-head` is passed.

## 5. Run board and harness bench

```bash
tools/lane.sh board            # every run with an event log: state, completion, role, reason, attempts, model, duration
tools/lane.sh board --json     # the same data for a UI
bash tools/lane-harness-bench.sh   # all harness selftests as one fail-closed gate
```

`lane-harness-bench.sh` runs the event-log unit tests, the role-policy selftest, the consult
provider tests, and the lane wrapper selftest, writing full logs to
`.ai/runs/harness-bench-<timestamp>/`. It is the benchmark corpus for the harness itself: run it
when `lane.sh`, `lane-policy.sh`, `lane-events.py`, `consult.py`, or the model chain changes.

## Tests

```bash
tools/lane.sh policy-selftest       # 7 cases: allowed, blocked, path scope, read-only
tools/lane.sh events-selftest       # 8 cases: replay, contradictions, policy exit codes, completion
tools/lane.sh selftest              # 38 cases: landing detection, attribution, completion gate
python3 -m unittest discover -s tools/tests -p 'test_consult.py'   # 7 consult provider cases
bash tools/lane-harness-bench.sh    # all of the above as one gate
```

## Integration notes

- `tools/lane.sh run` exports `LANE_EVENT_RUN` and `LANE_RUNS_DIR` to the generated runner, so
  `tools/lane-model.sh` writes to the same run and directory.
- Direct `lane_model_run` callers (chair/Sol lanes) fall back to the log-prefix basename as the run
  name.
- Everything here is additive: the marker, the journal, the watcher cursor, and the acceptance gate
  are unchanged.
