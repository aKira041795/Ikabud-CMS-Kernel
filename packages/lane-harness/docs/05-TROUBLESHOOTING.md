# Troubleshooting — symptom, cause, fix

Start with these two, always:

```bash
bash tools/preflight.sh          # what is this machine missing?
bash tools/lane.sh selftest      # 29 passed, 0 failed — the harness checking itself
```

## The lane "did nothing" / I was not told

**Symptom:** a dispatch looks like it vanished.
**Check:** `bash tools/lane.sh pending`, then `bash tools/lane.sh list`.
The landing record is **durable** — the runner writes it itself, so it survives the monitor being
killed, the terminal closing, and the editor restarting. If `pending` lists it, the recording works
and only the *notification* failed. Read it, then `bash tools/lane.sh ack`.

**A 0-byte lane log is normal, not a hang.** `pi --print` buffers everything until it exits. Judge
liveness by the process, never by file size.

## `$'\r': command not found`, or a shebang that cannot find bash

**Cause:** CRLF line endings — a Windows checkout with `core.autocrlf=true`. It looks like a broken
harness.

```bash
git config core.autocrlf false
printf '*.sh text eol=lf\n' >> .gitattributes
sed -i 's/\r$//' tools/*.sh
```

`preflight.sh` detects stray carriage returns in `tools/*.sh` and names this fix.

## `REFUSING TO DISPATCH` (exit 2)

Three distinct reasons, each with its own message:

| message | cause | fix |
|---|---|---|
| `no acceptance criterion` | you did not pass `--acceptance` **and** `--pass-looks-like` | supply both. A lane that cannot be told apart from a no-op cannot be trusted when it says PASS |
| `the acceptance command ALREADY PASSES on this tree` | the criterion cannot discriminate your change | fix the criterion. **Do not** reach for `--acceptance='exit 1'` |
| `the acceptance command TIMED OUT` | the criterion hangs; a hang is not a failing criterion | make it bounded |

`--no-acceptance-gate="<reason>"` exists for a genuinely non-verifiable lane. It is recorded with its
reason in `.ai/runs/acceptance-gate.log`, and the landing will say `EXECUTION_ONLY`.

## `STILL RUNNING after 90s` — exit code 3

**Not a failure.** No terminal wrapper can stay attached indefinitely (the editor's is capped around
120s), so `run` hands the wait back on purpose, with an instruction, instead of being killed with no
signal. The runner records the landing by itself. **Re-arm the watcher.** `bash tools/lane-watch.sh
--timeout=90`.

## `no landing yet after 90s` from the watcher

Also not a failure — a heartbeat. The watcher is one-shot *by design*: a process that never exits can
never tell you anything. Run it again.

## `state: unverified`, `reason: timeout`

The lane died without recording an exit status — the deadline recorder filled the silence. Treat it
as a crash: partial edits may exist, so check the tree. It is **not** evidence of success, and it is
not "the lane is still going".

## `reason: quota`

The provider refused. Check the credential and the plan for that model:

```bash
pi auth check --provider <id> --json
```

Then look at `tools/model-chain.txt`. If **every** model in the chain is the same provider, one
exhausted account stops the work — that is why a different provider belongs last.

## `verdict: EXECUTION ONLY`

Nothing was checked: the dispatch used `--no-acceptance-gate`, or the generated runner predates the
acceptance feature. It is never `VERIFIED`. Re-dispatch with a criterion.

## `acceptance: TIMEOUT`

Your criterion exceeded `LANE_ACCEPTANCE_TIMEOUT` (default 300s). Raise it for that dispatch:

```bash
LANE_ACCEPTANCE_TIMEOUT=900 bash tools/lane.sh run ...
```

Resist raising it to hide a hang — a criterion that hangs is usually a criterion that is wrong.

## `changed files` looks wrong

Read the **basis**:

- `basis=delta` — the lane's own change, computed as a set difference against a snapshot taken at
  dispatch. This is the good case.
- `basis=tree` — no baseline existed (a runner generated before this feature). The number is the whole
  tree's dirty count and counts *other* work too.
- `basis=unavailable` — **not a git repository.** Attribution is off and the harness says so instead
  of reporting a silent zero.
- `dirty_before=N` — N files were already dirty at dispatch and are **not** claimed as the lane's.
  A path that was dirty before *and* touched again is in that number; git alone cannot tell, and the
  harness reports the ambiguity rather than guessing.

## `pty: NONE` — but I am on Windows

You are not inside WSL. Check the bottom-left corner of the VS Code window: it should read
**WSL: Ubuntu**. Open the folder from the WSL shell with `code .`. See `01-WINDOWS-AND-WSL.md`.

## A lane seems to create no files, or the wrong ones

- Is `tools/` in the folder you actually **opened**? The harness resolves its repository root from its
  own location (`dirname $BASH_SOURCE`).
- In a **multi-root workspace**, `${workspaceFolder}` is the *first* folder. Point the task at a named
  folder: `"cwd": "${workspaceFolder:my-repo}"`.

## The monitor dies with a syntax error at a line that looks fine

If a **lane edited `tools/lane.sh`**, this is the cause — bash re-reads a script file as it executes,
so editing the running harness corrupts the reader. The generated runner still records the landing, so
nothing is lost. It is a self-hosting artifact, not a bug in your change.

## Nothing notifies me at all

Desktop pop-ups are **off by default** (they used to be one per landing, ~16 per self-test run). The
durable channel is the journal, which is never gated:

```bash
bash tools/lane.sh pending        # what you have not been told
bash tools/lane.sh ack            # after you have read it
```

To opt in to pop-ups: `LANE_NOTIFY=1`, or `--notify` on a dispatch. Then check your desktop
notification daemon is actually running (`notify-send` returns 0 even when it delivers nothing, which
is why it is never the only channel).

## `NOT VERIFIED` — is the work bad?

Not necessarily. `NOT VERIFIED` means **your acceptance command did not pass after the lane ran**. It
does not say the lane is wrong; it says the objective is not demonstrated. Three possibilities, in
order of likelihood:

1. the lane did not finish the job (read its report, then `EXECUTION:` on the same landing);
2. the criterion is wrong — run it by hand and read the output in
   `.ai/runs/<lane>.acceptance.log`;
3. the criterion is right and the work is incomplete.

Then dispatch a **repair lane** whose acceptance is the *same* command. Do not relax it.
