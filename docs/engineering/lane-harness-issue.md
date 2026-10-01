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

## What works now (verified)

- `tools/lane.sh run <name> <lane-script> [--timeout=N] [--require-clean]` — generates the self-recording
  runner, dispatches detached, blocks. `record`, `status`, `list` subcommands.
- `tools/lane-watch.sh --timeout=90` — one-shot bounded watcher. Exits on a landing **or** on heartbeat;
  either way its completion wakes the agent, which then re-arms. Proven: heartbeat exited in 15s with an
  explicit RE-ARM line; a landing was detected and exited in 11s.
- Landing record: `.ai/runs/<name>.landed.json` (atomic, valid JSON) + one line in `.ai/runs/LANDINGS.log`
  + a **desktop notification** to the owner.

## Known blocking defects (adversarial review, fixes in flight)

1. **`tools/sweep-daily-ledger.sh:92-114` — discards each suite's process exit status.** A suite that
   prints a passing summary and then crashes, times out, or exits non-zero is classified **PASS**. The
   script claims it exits non-zero only when everything passes; that is false. **False green.**
2. **`tools/lane.sh:327-342` — the lane's exit status never reaches the verdict.** A lane printing
   `status: PASS` then `exit 7` produced `reason: report_present`, **no recorded exit code**, normal
   urgency, and the runner returned **0**. **False green** — a crash reported as success.
3. **`tools/lane-watch.sh:44-60` — tracks marker FILENAMES only.** If a marker exists when armed and a
   later run reuses that lane name, the replacement is ignored forever → **missed landing**.
4. **`tools/lane.sh:109` — lane names inserted into the JSON marker unescaped** → invalid JSON on a quote.
5. **Permissions were `0664`** despite documented direct invocation (`tools/lane.sh run` → permission
   denied). Fixed to `0755`; confirm and ensure generated files are executable.

Non-blocking: `docs/daily-ledger/sweep-baseline.md` describes `--json` as machine-readable while the script
emits human headers around it; `docs/daily-ledger/guard-register.md` claims a fails-loudly assertion that
lives inside an **already-red** suite (`107/109`), so removing it cannot turn a green suite red.

## The meta-issue

**The harness has no test of its own failure modes.** Every one of the seven bugs was found by accident or
by an external reviewer — never by the tool. A tool whose purpose is to detect failure must be able to
demonstrate its own.

The fix is a **self-test** (`lane.sh selftest`) covering, each with a must-allow AND a must-refuse case:

- a suite that prints a passing summary and exits non-zero → the sweep must FAIL
- a lane that prints `status: PASS` and exits 7 → `reason` must not be `report_present`, and the return
  must be non-zero
- the same lane name landing twice → both detected
- a lane name containing a quote → the marker still parses
- no lane running + a stale marker → reported as not landed, not as success

## Opening questions for the new session

1. Record ownership: the runner (current) or a supervising process? **Recommendation: the runner** — it is
   the only thing that holds the exit status at the moment it exists.
2. Should `lane.sh selftest` run **on every dispatch** (drift fails loudly) or on demand?
3. Should this live in `tools/` at all? It is dev infrastructure, not the product — it currently ships with
   the repo and is picked up by packaging because packaging walks the working tree.
4. Given the 120s cap, is a 90s heartbeat loop the right wake-up model, or should the notification be
   desktop-only with the agent polling on demand? The heartbeat works but wakes the agent ~13 times an hour.

## What NOT to do again

- **Do not add another proxy.** Capture the exit status, or nothing.
- **Do not put the record inside something that can be killed.** (#6)
- **Do not write a result to a file nobody reads.** (#1, #7)
- **Do not verify the harness by reading it.** All seven bugs were found by running it. `bash -n` is not a
  test.
- **Do not trust a report that a tool works.** Ask what it does when the thing it watches fails.
