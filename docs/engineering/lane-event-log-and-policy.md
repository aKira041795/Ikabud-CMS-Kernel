# Lane Event Log and Role Policy

Two harness primitives added on 2026-10-20, after evaluating DeepSeek Harness (`dsh`). They are
implemented natively in the existing harness; no new runtime was adopted.

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

## Tests

```bash
tools/lane.sh policy-selftest       # 7 cases: allowed, blocked, path scope, read-only
tools/lane.sh events-selftest       # 5 cases: replay, contradictions, policy exit codes
tools/lane.sh selftest              # existing 34-case landing-detection selftest
```

## Integration notes

- `tools/lane.sh run` exports `LANE_EVENT_RUN` and `LANE_RUNS_DIR` to the generated runner, so
  `tools/lane-model.sh` writes to the same run and directory.
- Direct `lane_model_run` callers (chair/Sol lanes) fall back to the log-prefix basename as the run
  name.
- Everything here is additive: the marker, the journal, the watcher cursor, and the acceptance gate
  are unchanged.
