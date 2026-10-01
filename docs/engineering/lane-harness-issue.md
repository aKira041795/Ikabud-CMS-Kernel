# The lane harness — the actual issue

Handover document, 2026-10-01. Read this first in a new session.

## What the harness is for

Dispatch an AI lane (`pi --print --model <x> <prompt>`) as a background process running for 5–60 minutes,
and **know when it lands and how it ended — without a human having to ask**.

It exists because a landed lane went unnoticed twice, until the owner asked *"and?"*. The owner's words:
*"i want you to fix the harness where it must always detect if a process has landed so i don't waste time
doing follow-up."*

## THE ROOT ISSUE

**The harness infers the outcome from side-effects, instead of capturing the outcome where it is actually
known.**

A lane's outcome is known exactly once: at the moment its process exits, as an **exit status**. Every other
signal — log byte count, a pid being alive, a marker file's existence, a `status:` line in the text — is a
**proxy**, and every proxy can be stale, absent, ambiguous, or unread.

The tool has been rewritten **seven times**, each replacing one proxy with another:

| # | proxy used | how it failed |
|---|---|---|
| 1 | detached waiter writing `/tmp/<lane>-wait.out` | nothing ever read the file |
| 2 | `kill -0 <pid>` | tests the wrapper shell, not the work |
| 3 | `pgrep -f "<lane script>"` | matched the **caller's own command line** — always believed the lane was running, waited the full timeout |
| 4 | `$?` captured inline | expanded by the **wrong shell** — every lane reported `exit 0`, **including a crash** |
| 5 | status line text | CR from the pty → **invalid JSON** marker |
| 6 | the monitoring process records the landing | monitor killed at the 120s cap → **landing recorded nowhere** |
| 7 | detached cover writing `/tmp/<lane>-cover.log` | **nothing read the file** — identical to #1 |

## The two architectural faults underneath

**A. The authoritative record must be owned by the process that holds the outcome.**
The exit status can only be captured by the lane's own supervisor, at the instant the lane exits. A record
written by a watcher is a record that dies with the watcher — and watchers die, because the terminal wrapper
kills long commands. Current state: `lane.sh run` generates a **self-recording runner** that runs the lane,
captures `$?`, and calls `lane.sh record`. Nothing depends on a monitor surviving.

**B. Observation and notification must be separate concerns.**
Recording must be **guaranteed**; notification may be **best-effort**. These were conflated, which is why
#6 and #7 happened. Concretely: a command notifies its caller **only when it FINISHES**, so a monitor that
loops forever **can never notify**. Notification therefore has to be either a desktop notification (fires
from the recording process) or a **bounded, re-armable poll** whose completion is the wake-up.

## Environment constraints — undocumented, and built against wrongly

1. **A terminal command is capped at ~120 seconds** by the wrapper; longer commands are killed.
   (A monitor therefore cannot simply block until a 30-minute lane finishes.)
2. **A command notifies its caller only when it FINISHES.** Forever-loops never notify.
3. `script -qec` drives a pty in **raw mode** → output carries **CRLF**.
4. Nested quoting through `lean-ctx -c '...'` breaks on any inner single quote — one of my readings
   returned `0` for a 6729-byte file and I reported the corruption as fact. **Put scripts in files.**
5. `notify-send` **is** available (DISPLAY=:0.0, DBUS set) — the owner-facing channel.

## What works now (verified 2026-10-01, second session)

- `tools/lane.sh run <name> <lane-script> [--timeout=N] [--slice=90] [--wait-grace=N] [--require-clean]`
  — generates the self-recording runner, dispatches it, and waits in **bounded slices**. If the lane is
  still running at the slice boundary it returns **3** and prints the re-arm command, instead of blocking
  past the terminal cap where a killed process produces no signal at all. The runner records the landing
  itself, so handing the wait back never loses it.
- `tools/lane-watch.sh [--timeout=90] [--forever]` — one-shot bounded watcher. Exits on a landing **or** on
  heartbeat; either way its completion wakes the agent, which then re-arms.
- Subcommands: `run`, `status`, `list`, `pending`, `ack`, `record`, `selftest`.
- **`tools/lane.sh selftest` — 10 cases, each with a must-allow and a must-refuse direction. Currently
  10/10 green, exit 0, repeatable, and each new guard falsified by mutation before being trusted.**

Exit status of `run`: `0` landed clean · `<n>` landed with the lane's exit code · `3` still running,
re-arm · `1` unverified / timeout.

Two records, with different jobs:

| artefact | role |
|---|---|
| `.ai/runs/<name>.landed.json` | per-lane snapshot, atomic, valid JSON; carries `state` |
| `.ai/runs/landings.jsonl` | **append-only journal — the queue the agent is notified from** |
| `.ai/runs/.reported.cursor` | how many journal entries have been **actually reported** |
| `.ai/runs/LANDINGS.log` | human-readable history, one line per landing |

## THE ACTUAL ROOT CAUSE (reproduced, not theorised)

The record was durable. **Delivery was not.**

`lane-watch.sh` decided what was new by snapshotting which `.landed.json` files existed when it armed, and
skipping everything in that snapshot. That uses **file existence as a proxy for acknowledgement** — "the
marker was already there" was treated as "someone was already told". Because the watcher exits on every
landing *and* on every heartbeat, it is disarmed most of the time, so the miss was the **normal path**, not
an edge case. Every landing in a disarmed window was swallowed permanently, with no second chance.

This is the same class of error as all seven original bugs — inferring state from a side-effect instead of
recording it — reintroduced by the fix that was supposed to end it.

### Reproduced, before the fix

| test | result |
|---|---|
| land a lane with **no watcher armed**, then arm | `no landing yet` — **swallowed** |
| two lanes land in one window, one arm | only the first is reported; re-arming then swallows the second **forever** |
| lane prints `status: PASS` then exits 7 | marker said `crash`, but `LANDINGS.log`/the desktop toast said `report_present` |
| lane killed mid-work (`--timeout`), monitor gave up | certified `report_present`, `exit_code: null`, **returned 0** |
| every landing | appeared **twice** in `LANDINGS.log` and produced two desktop toasts |
| `notify-send` with no `DISPLAY`/`DBUS` | returns **0** — the owner-facing channel cannot report its own failure |

### The fix

1. **The journal is the queue; a cursor records what was reported.** The watcher reports every journal entry
   after the cursor and advances the cursor **only after actually printing them**. A landing that occurs
   while disarmed is therefore reported on the next arm. The failure direction is over-reporting, never
   under-reporting. First-ever run adopts existing history instead of replaying it, and says so.
2. **One commit per landing.** `commit_landing` owns marker + journal + human log + toast, guarded by an
   atomic `mkdir` lock, so nothing appears twice.
3. **The monitor may not certify.** It waits only for the runner's own record (marker or exit sentinel). If
   it gives up, it writes `state: unverified` / `reason: timeout` and returns non-zero — never
   `report_present`. When a marker exists it reports the marker's values **verbatim**, so a report can no
   longer contradict the record.
4. **Process matching removed entirely.** `pgrep -f <lane-script>` matched the monitor's *own* command
   line, because the lane-script path is one of its arguments — so "the lane is gone" was never true and
   the pid written to `.pid` was frequently the monitor's. Detection is now the runner's record, only.
5. **Batched reporting.** N landings cost one wake, not N.
6. **All landings in one arm are reported before exiting**, and `run` now prints the arm command at dispatch
   so the notify loop is self-documenting.

## Original blocking defects — status

1. **`sweep-daily-ledger.sh` discarded each suite's exit status** → fixed (commit `fcae47ce`).
2. **The lane's exit status never reached the verdict** → fixed on the *runner* path in `fcae47ce`, but the
   **monitor** path still certified a killed lane as a landing. Now fixed by (3) above; guarded by S4/S4b.
3. **The watcher tracked marker filenames** → superseded entirely: it now tracks a recorded cursor.
   Guarded by S1/S2.
4. **Unescaped lane names in the marker** → fixed; guarded by S6.
5. **Permissions `0664`** → `0755`, re-confirmed.

Non-blocking (unchanged): `docs/daily-ledger/sweep-baseline.md` describes `--json` as machine-readable while
the script emits human headers around it; `docs/daily-ledger/guard-register.md` claims a fails-loudly
assertion that lives inside an **already-red** suite (`107/109`), so removing it cannot turn a green suite red.

## The meta-issue — now addressed

**The harness had no test of its own failure modes.** Every one of the seven original bugs was found by
accident or by an external reviewer — never by the tool. That is what let this class of bug keep coming back.

`tools/lane.sh selftest` now exists. Each case has a must-allow AND a must-refuse direction, because a guard
that never refuses is unproven and a **wrong** guard is worse than none — it is trusted.

| case | direction | asserts |
|---|---|---|
| S1 | must-allow | a landing during a **disarmed** window is reported on the next arm |
| S2 | must-refuse | that same landing is **not** reported again (skipped, not passed, if S1 reported nothing — a vacuous green is not a green) |
| S3 | must-refuse | `status: PASS` then `exit 7` → non-zero, reason ≠ `report_present` |
| S4 | must-refuse | a lane killed mid-work → `unverified`, non-zero |
| S4b | must-allow | the same guard does **not** refuse a lane that finished inside its budget |
| S7 | must-refuse | a lane still running at the **slice** boundary returns 3 and writes **no verdict** |
| S7b | must-allow | handing the wait back does **not** lose the lane — it still lands, is recorded, and is reported |
| S5/S5b | must-refuse | exactly one journal entry and one human log line per landing, measured **per run** |
| S6 | must-refuse | a lane name containing a quote still yields valid JSON |

**The self-test was falsified before it was trusted:** re-introducing the original defect (treat everything
already on disk as already reported) makes **S1 fail** and the suite exit 1. A guard that has never been
seen to fail is not evidence.

That falsification also found a bug in the self-test itself: S5/S5b counted the **whole** journal, so they
failed on every run after the first. They now measure from a per-run baseline.

## Opening questions — answered

1. **Record ownership:** the **runner**. Confirmed by construction — it is the only process holding the exit
   status at the instant it exists. Proven end-to-end: the monitor was `kill -9`'d mid-flight and the landing
   still reached the journal and was reported on the next arm.
2. **Selftest on every dispatch?** No — **on demand**, and before a work session. It takes ~35s and fires
   real desktop toasts (the toast *is* under test). Running it per dispatch would be noise and latency for a
   regression that only a code change can introduce.
3. **Does this belong in `tools/`?** It is dev infrastructure, not product. It ships because packaging walks
   the working tree; that is a packaging concern, not a reason to leave the defect unfixed.
4. **Wake-up model:** bounded slices, and the desktop toast is a courtesy. Two corrections to this
   document's own earlier claims:
   - **The ~120s cap does not always kill.** Measured 2026-10-01: a sync command ran **150s**
     (14:54:45 → 14:57:15) and returned its output. The earlier "capped at ~120s" observation was real on
     a different invocation path (through the `lean-ctx` wrapper). Both can be true, so the design must be
     correct **either way** — which is why `run` exits deliberately at `--slice=90` rather than relying on
     either behaviour: a process that exits on purpose always produces a wake-up; a killed one produces
     nothing.
   - **The toast CANNOT be the only channel — but it does work in practice.** `notify-send` returns **0**
     with no `DISPLAY` and no `DBUS`, so it silently does nothing and cannot report its own failure; any
     `|| true` around it is invisible. **Confirmed by the owner on 2026-10-02: the pop-ups do appear.** That
     was the one link neither the code nor a self-test can check, and it settles the push path: the runner
     calls `notify_landing` on every commit, detached, inheriting `DISPLAY`/`DBUS` — so a landing is pushed
     to the owner even when no agent turn is live. Keep the journal as the reliable channel regardless: a
     non-interactive dispatch (no `DISPLAY`) would toast nothing, and a toast is transient.

### The user-facing loop

```
bash tools/lane.sh run <name> <script> --slice=90      # dispatch; wakes you at 90s or on landing
   -> exit 3 + RE-ARM line       (still running — nothing lost)
bash tools/lane-watch.sh --timeout=90                  # re-arm; wakes you with the result
   -> LANDING(S) DETECTED: n + state/reason/status
```

Never a "is it done yet?" prompt: every return either reports the landing, or says the lane is still going
and what to re-arm.

And if no turn is live at all, the **runner still toasts** on commit — the toast fires from `commit_landing`
inside the detached runner, not from any monitor, which is why it survives the monitor being killed.

### What remains unverified

- The **async-terminal completion notification** path. Every command used in verification returned
  synchronously, so it was never exercised. It is not load-bearing: the wake-up works because a `run`
  invocation returns within its slice, not because of that notification.
- Anything about a **non-interactive dispatch** (no `DISPLAY`/`DBUS`) — the toast would silently no-op
  there. The journal covers it; nothing counts on the toast.

## What NOT to do again

- **Do not add another proxy.** Capture the exit status, or nothing.
- **Do not put the record inside something that can be killed.** (#6)
- **Do not write a result to a file nobody reads.** (#1, #7)
- **Do not confuse existence with acknowledgement.** "It was already on disk" is not "someone was told".
  This is the defect that survived seven rewrites; if a consumer needs to know whether something was
  consumed, **record** that, do not infer it.
- **Do not verify the harness by reading it.** All seven original bugs were found by running it — and the
  replacement for their root cause was itself found by running it. `bash -n` is not a test.
- **Do not trust a report that a tool works.** Ask what it does when the thing it watches fails.
- **Falsify a guard before trusting it.** A must-refuse case that has never been seen to refuse is not a
  guard, it is decoration.
