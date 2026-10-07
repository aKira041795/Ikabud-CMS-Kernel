# The lane harness — the actual issue

Handover document, 2026-10-01. Updated 2026-10-03 — the root-issue analysis above still stands; the
state of play at the end of the 2026-10-03 session is in "STATE OF PLAY" below. Read this first in a
new session.

**Second 2026-10-03 session (later): the two open HARNESS items are now built, not described.** The
pre-dispatch acceptance gate and the three-model chain exist as code with their own falsifiable checks.
That session could not execute a single command — its shell would not start at all (host sandbox
misconfiguration) — so the changes were left reviewable-but-unverified.

**VERIFIED 2026-10-04: everything below has now been run, and all of it passes.** The sandbox condition
had cleared on its own; nothing in the code needed changing for it to run. §5 carries the measured
results — including the two gate branches the selftest did **not** cover (the hang refusal and the
deliberate override), which were falsified by hand rather than assumed, and the third model in the chain,
which had never been proved to exist.

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

- `tools/lane.sh run <name> <lane-script> [--timeout=N] [--slice=90] [--wait-grace=N] [--require-clean]
  --acceptance="<cmd>" --pass-looks-like="<what PASS looks like>"]`
  — generates the self-recording runner, dispatches it, and waits in **bounded slices**. If the lane is
  still running at the slice boundary it returns **3** and prints the re-arm command, instead of blocking
  past the terminal cap where a killed process produces no signal at all. The runner records the landing
  itself, so handing the wait back never loses it.
- `tools/lane-watch.sh [--timeout=90] [--forever]` — one-shot bounded watcher. Exits on a landing **or** on
  heartbeat; either way its completion wakes the agent, which then re-arms.
- Subcommands: `run`, `status`, `list`, `pending`, `ack`, `record`, `selftest`.
- **`tools/lane.sh selftest` — 10 cases at that date, each with a must-allow and a must-refuse direction.
  10/10 green, exit 0, repeatable, and each new guard falsified by mutation before being trusted.**
  (The suite has since grown to **18 cases**: three S9 gate cases added on 2026-10-03, S10/S10b for
  the tooling advisory and S11/S11b for the notification gate on 2026-10-04. All 18 pass as of
  2026-10-04 — see §5 and §6.)

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
| S8 | must-allow | a lane killed by its own `--timeout`, with the monitor gone, is still recorded exactly once (`reason: timeout`) |
| S9 | must-refuse | a dispatch with **no acceptance criterion** is refused (rc=2) and leaves no runner and no marker |
| S9b | must-refuse | a criterion that **already passes** on this tree is refused — it cannot discriminate the change |
| S9c | must-allow | a failing criterion plus a stated PASS **does** dispatch and land (a gate that refuses everything is a wrong guard) |

S9/S9b/S9c are the pre-dispatch acceptance gate (added in the second 2026-10-03 session). Every fixture
lane in this suite now dispatches **through** the gate, so the gate is exercised on every selftest run
rather than only when a lane is dispatched by hand. All three pass as of 2026-10-04, and the gate log
proves the point rather than the count: that same run left entries for `st1`, `st3`, `st4`, `st4b`, `st7`,
`st8` and `st9c`, so the fixture lanes genuinely travel through the gate instead of around it.

**The self-test was falsified before it was trusted:** re-introducing the original defect (treat everything
already on disk as already reported) makes **S1 fail** and the suite exit 1. A guard that has never been
seen to fail is not evidence.

That falsification also found a bug in the self-test itself: S5/S5b counted the **whole** journal, so they
failed on every run after the first. They now measure from a per-run baseline.

## Opening questions — answered

1. **Record ownership:** the **runner**. Confirmed by construction — it is the only process holding the exit
   status at the instant it exists. Proven end-to-end: the monitor was `kill -9`'d mid-flight and the landing
   still reached the journal and was reported on the next arm.
2. **Selftest on every dispatch?** No — **on demand**, and before a work session. It takes ~35s. It used
to fire real desktop toasts on the grounds that "the toast *is* under test" — which on 2026-10-04 measured
as ~16 pop-ups per run, from the command run most often. It now points `LANE_NOTIFY_CMD` at a stub, which
asserts the notify path instead of demonstrating it; see §6. Running it per dispatch would be noise and
latency for a regression that only a code change can introduce.
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
   - **The toast CANNOT be the only channel — but it did work in practice.** `notify-send` returns **0**
     with no `DISPLAY` and no `DBUS`, so it silently does nothing and cannot report its own failure; any
     `|| true` around it is invisible. **Confirmed by the owner on 2026-10-02: the pop-ups do appear.**
     That was the one link neither the code nor a self-test can check, and it settles the push path: the
     runner calls `notify_landing` on every commit, detached, inheriting `DISPLAY`/`DBUS` — so a landing is
     pushed to the owner even when no agent turn is live.
   - **…and on 2026-10-04 the owner turned it off, because it was flooding the desktop.** That is the
     correction this section now carries, and it is the same lesson as the earlier one: the channel worked,
     and it worked *too often*. Pop-ups are **OFF by default** (`LANE_NOTIFY=1`, or `run --notify`) because
     one landing raised **two** pop-ups — `commit_landing` toasted it and `lane-watch.sh` toasted it again —
     so the loudest source of flooding was two per landing, ~16 per selftest run, from the commands that
     run constantly. The watcher now has its own switch, so one landing yields exactly one notification.
     **Nothing was lost:** `LANDINGS.log` and the journal are untouched by the gate and were always the
     reliable channel. A courtesy nobody can decline is not a courtesy, it is a screen to clear.

### The user-facing loop

```
bash tools/lane.sh run <name> <script> --slice=90 \
     --acceptance='<cmd that fails on this tree>' \
     --pass-looks-like='<what PASS looks like on the target>'
                               # dispatch; wakes you at 90s or on landing
   -> REFUSED (exit 2)           if the criterion is missing, already passes, or times out
   -> exit 3 + RE-ARM line       (still running — nothing lost)
bash tools/lane-watch.sh --timeout=90                  # re-arm; wakes you with the result
   -> LANDING(S) DETECTED: n + state/reason/status
```

The two gate flags are mandatory for `run` — see §1 and §3. `--no-acceptance-gate="<reason>"` is the
recorded override for a lane that genuinely cannot be verified; it is appended to
`.ai/runs/acceptance-gate.log` with its reason.

Never a "is it done yet?" prompt: every return either reports the landing, or says the lane is still going
and what to re-arm.

And if no turn is live at all, the **runner still records** on commit — the record is written from
`commit_landing` inside the detached runner, not from any monitor, which is why it survives the monitor being
killed. With `--notify` (or `LANE_NOTIFY=1`) it raises a pop-up from that same detached process, so the alert
does not depend on a monitor surviving either.

### What remains unverified

- The **async-terminal completion notification** path. Every command used in verification returned
  synchronously, so it was never exercised. It is not load-bearing: the wake-up works because a `run`
  invocation returns within its slice, not because of that notification.
- Anything about a **non-interactive dispatch** (no `DISPLAY`/`DBUS`) — the toast would silently no-op
  there. The journal covers it; nothing counts on the toast.

### Residual gap found in the field and closed (2026-10-02)

A real 50-minute lane (`closefind`) was killed by its own `--timeout` and **recorded nothing at all** —
no marker, no journal line, no notification. The only way to learn it had ended was to ask. That is the
original complaint, one layer further out than the fixes above.

Why the runner cannot solve it: `timeout` signals `script`, and `script` terminates the session with a
signal bash never gets to handle. An `EXIT HUP INT TERM` trap in the generated runner **does** fire on a
direct `TERM` (verified: `state=landed reason=timeout exit=143`) but is useless in the real chain.

The fix is that **the record must not live inside the tree that gets killed**: `run` now spawns a
**detached deadline recorder** (`lane.sh watchdog <name> <secs>`), which outlives the kill and commits
`unverified` / `reason: timeout` if nothing else recorded by `timeout + --deadline-grace` (default 90s).
It refuses to invent a verdict while the lane is still running. Guarded by **S8**.

Related: `commit_landing`'s lock is now left in place after committing, so a late second caller cannot
write a duplicate — the monitor and the runner can both record, and `run` clears the lock at dispatch.


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

---

# STATE OF PLAY — 2026-10-03 (for the review session)

Two systems, and they are in very different health. Read this before planning anything.

## 1. Lane harness — improved, and the remaining weakness is the CHAIR, not the tool

**Shipped this session** (each with the check that proves it):

| fix | commit | the check |
|---|---|---|
| ONE status recogniser; `status_line()` and `classify_log()` had drifted, so a lane reporting `## Status: Complete` was announced as having no status line while its own marker held it | `a3de6ccf` | 5 status formats classify correctly; a log with no verdict and one with `Status:` mid-sentence stay `unknown`; selftest 11/11 |
| The verdict no longer asserts "every model was unavailable" when every attempt actually CRASHED | `ea9a34a2` | `LANE_MODEL_CMD=false` -> "NOT a quota result"; a rate-limit stub -> "unavailable" |
| `lane_model_run` fails loudly on bad arguments instead of `$3: unbound variable` | `01a4cf9d` | arg-count cases |
| Single-source model invocation + single-source unavailability signatures | `b8b53035`, `5f7086c6` | `tools/lane-model-selftest.sh` 14/14 |
| Watchdog process leak (was spawning one per selftest run) | `5f7086c6` | watchdog count 0 before/after/leaked across 12 selftest runs |

**Measured reliability, honestly.** Of **458** landings today, **433 are the harness's own selftest
artifacts** (named throwaway lanes that deliberately crash/timeout so the selftest can assert they are
recorded). Counting those as failures is wrong and I made that mistake first. Excluding them:

    25 real dispatches -> 9 landed with a report, 9 landed with an unclassifiable verdict
                          (the drift bug above, now fixed), 5 crash, 2 quota/timeout

**Roughly 4 of those 5 crashes were caused by ME, not the tool:** `harppui-a` (I omitted an argument),
`harppui-b` (my quoting error), `harnessflaky` twice (my brief told the lane to
`pkill -f 'lane.sh watchdog'`, which matched the lane's OWN supervisor and SIGTERM'd it — `exit=143`,
`log=0b`). The harness executed my instructions faithfully, including the bad ones.

**Still open (owner-only):** nothing in the harness. The two code-level gaps below are now closed by
checks; what remains is §2's product decisions.

**Closed in the second 2026-10-03 session:**

| gap | how it is closed | the check that proves it |
|---|---|---|
| **No third model fallback** — when Sol and DeepSeek were both unavailable the work simply stopped | `tools/model-chain.txt` is the ONE ordered chain (Sol → DeepSeek Flash → Terra); `lane-model.sh` exposes `$LANE_MODEL_CHAIN` and `lane_model_chain_ok`, and now WARNS when a caller passes fewer than `LANE_MODEL_CHAIN_MIN` models — at the exact moment of the defect | `bash tools/lane-model-selftest.sh` — a stub that refuses the two real primary model names must fall through to `openai-codex/gpt-5.6-terra`; a two-model chain must be REJECTED; the canonical chain must be ACCEPTED |
| **No pre-dispatch gate on acceptance criteria** | `tools/lane.sh run` now REFUSES to dispatch unless `--acceptance="<cmd>"` fails on this tree AND `--pass-looks-like="<text>"` is given; a deliberate override is allowed only as a recorded reason | `bash tools/lane.sh selftest` — S9 (no criterion → refuse, nothing runs), S9b (a criterion that already passes → refuse), S9c (failing criterion + stated PASS → dispatch and land) |

The gate is in `cmd_run`, before `--require-clean` so any artifact the criterion writes is caught by the
cleanliness check rather than handed to the lane. Both halves are enforced because they catch different
errors: (a) alone was the observed gap — the criterion DID fail on HEAD and still could not pass on the
new code, because it asserted a MECHANISM the change had replaced. Every override is appended to
`.ai/runs/acceptance-gate.log` with its reason, so a bypass is visible rather than silent.

## 2. HARPP — works, except the one thing it exists for

**Proven** (commands, not claims):
- 11/11 nav destinations in a real browser: `node tools/harpp-ui-verify.js` -> 200 on every page,
  0 console errors, 0 contrast pairs under 4.5, only radius above 3px is the unread badge.
- **It has now carried a real message** (first time): `node tools/harpp-message-path-probe.js` ->
  conversation created, message persisted, notification row created. Before this the install had
  **0 conversations / 0 messages / 0 notifications** while 310 unit tests passed — unit tests proved
  the pieces, nothing proved a message travels.
- UI: "Slate & Signal" palette, 3px radius ceiling, Deploy moved to Admin, Settings rebuilt as one
  concern-grouped column, three provably-dead things removed.
- Messenger wart fixed (`9efbfac0`): two native `prompt()` dialogs replaced by one styled in-page
  dialog, Cancel now says "New conversation cancelled." instead of returning silently, and an invalid
  session id is caught inline with the reason rather than producing an unexplained 422.

**THE STRUCTURAL BLOCKER — not a bug, a property of the origin.**
`node tools/harpp-push-capability-probe.js` on `http://harpp.test`:

    isSecureContext : false        serviceWorker : undefined      PushManager : true (useless)
    swRegistration  : null         console errors: 0

A push subscription needs a service worker; a service worker needs a **secure context**. So on this
origin push is impossible, `harpp_push_subscriptions = 0`, and the notification sits at
`status=pending` forever. HARPP's whole purpose is notifying the owner **away from the workstation**,
and that has **never once worked**. This cannot be coded around.

**Decisions only the owner can make:**
1. **HTTPS for HARPP.** Until then "check the workstation" is unavoidable. This is the single item
   blocking the presence goal. Note the one codeable-looking alternative and why it does not satisfy the
   goal: a `localhost` origin IS a secure context, so a push subscription would become possible there —
   but `localhost` is reachable only from the workstation itself, and presence means reaching the owner
   **away** from it. So a localhost origin would let the push path be *proved*, not *used*. Reported as a
   fact rather than offered as a fix.
2. **Desktop vs app settings ownership.** `~/.config/harpp/config.json` legitimately holds secrets and
   machine paths, but it ALSO holds `harpp_authority`, `cms.model`, `tenant_id` and `advisor.backend`,
   which are control-plane policy — so the desktop can silently disagree with the app. It also points at
   `tenant_id: 212` / `harpp.ikabudkernel.com` while local is `1232` / `harpp.test`. Recommend: app owns
   policy, desktop reads it down. **Deliberately NOT implemented:** inverting that polarity touches both
   the Python bridge and the PHP control plane, the owner has not confirmed which keys are policy, and
   the session that found it had no runnable shell to verify even one direction. Escalated as
   `ARCHITECTURE_DECISION_REQUIRED` rather than guessed at.
3. **Bluehost deploy** — back up the DB (migration `062` rewrites ~186 rows),
   `php scripts/generate-release-manifest.php`, deploy + `db/tenant-upgrade.sql` (applies `062`-`073`),
   **then** `modules/daily-ledger/database/repair_login_names_20260918.sql`.

## 3. The thing to fix next — NOW BUILT: the acceptance criterion is enforced, not restated

This section is kept as the evidence for the gate, not as a plan. The gate exists now:
`tools/lane.sh run` refuses to dispatch without a criterion that fails on this tree plus a stated PASS.
See §1 and §5.

Four of my false reds this session had **one** cause: **I wrote the acceptance criterion before the
change, then trusted the criterion.**
- A probe asserted a BUTTON WAS CLICKED rather than that a row persisted.
- A probe answered both `prompt()` dialogs with the same string containing spaces, which the
  `harness_session_id` validator correctly rejects — I nearly reported a product defect for my own
  invalid input.
- A probe demanded no dialog interaction while my own design REQUIRED pressing Create. The lane
  reported **BLOCKED** and was right; my spec was the defect.
- A "95 divergent templates" verdict that could not occur in production (`incoming_count` is always set).

Restating this as a lesson has a measured record today of **three restatements, zero prevented**. So do
not add another rule. Build the check:

> **A pre-dispatch gate that refuses to dispatch unless (a) the acceptance command has been run against
> `HEAD` and shown to FAIL, and (b) the brief states what PASS looks like on the target.**

**(a) alone is insufficient** — that was exactly the gap: the criterion DID fail on HEAD and still could
not pass on the new code, because it encoded the old interaction model. Assert OUTCOMES (a row exists, a
message persists), never MECHANISMS (one click suffices), whenever the design changes the mechanism.

The built form of that check, and why it is a check rather than prose:

    bash tools/lane.sh run <name> <script> \
      --acceptance='<cmd that fails on this tree>' \
      --pass-looks-like='<what PASS looks like on the target>'

It runs (a) live at dispatch — stronger than "has been run against HEAD", because it proves the criterion
is unsatisfied on the tree the lane actually starts from — refuses when the command passes, refuses when
it times out (a hang is not a failing criterion), and records (b) beside it. `--no-acceptance-gate="<reason>"`
exists for genuinely non-verifiable lanes and is appended to `.ai/runs/acceptance-gate.log`, so an
override is evidence rather than silence.

**A lane that reports BLOCKED with a precise reason is doing its job.** Three times this session the
BLOCKED lane was right and the spec was wrong.

## 4. Instruments you can run right now

    node tools/harpp-ui-verify.js             # 11 pages: status, console, radius, contrast
    node tools/harpp-message-path-probe.js    # does a message really travel? (asserts the outcome)
    node tools/harpp-push-capability-probe.js # is push even possible on this origin?
    python3 tools/harpp-selector-audit.py harpp   # 95 checked, 0 missing
    bash tools/lane.sh selftest               # MEASURED 18/18  (2026-10-04; +S9*, +S10*, +S11*)
    bash tools/lane-model-selftest.sh         # MEASURED 19/19  (2026-10-04; was 14/14 before the chain cases)
    cat tools/model-chain.txt                 # the ONE ordered model chain (3 models)
    cat .ai/runs/mcp-advice.log               # which lanes were told they are python-dominant, and when

Both counts above are **measured**, not expected: re-run on 2026-10-04, `19 passed, 0 failed` and
`18 passed, 0 failed`. The selftest dispatches every fixture lane THROUGH the gate, so the gate is
exercised on every selftest run rather than only when a lane is dispatched by hand — and S10/S10b now
exercise the tooling advisory in both of its directions while they are at it.

## 5. Verification of the second 2026-10-03 session's changes — RUN 2026-10-04, ALL PASS

**Everything in this section has now been executed.** For the record, the reason the earlier session
could not do it was purely environmental: its shell would not start at all — every command (including
`pwd`) failed before running:

    Sandboxing is enabled but this policy is not supported on this host: This host cannot bring up the
    private network namespace Bubblewrap needs whenever the sandbox is allowed to reach the network, so
    no sandboxed command or service can start. (Bubblewrap: network.proxy requires 'slirp4netns' on
    PATH: No such file or directory (os error 2). Install slirp4netns or omit network.proxy.) Apply the
    fix it names, or set sandbox.enabled to false to run without the sandbox.

The host cleared on its own, and **no code change was needed for any of it to run.** Measured 2026-10-04:

    bash -n tools/lane.sh tools/lane-model.sh tools/lane-model-selftest.sh   # all three OK
    bash tools/lane-model-selftest.sh   # 19 passed, 0 failed
    bash tools/lane.sh selftest         # 18 passed, 0 failed (one earlier S8 flake - see §6)
    cat .ai/runs/acceptance-gate.log    # 8 lines: 7 fixture criteria + 1 bypass

Both counts §4 listed as EXPECTED are now **MEASURED** and correct.

### The gate was falsified in FOUR directions, not one

The gate has three refusal branches and one allow branch, and S9/S9b/S9c reach only two of them — so the
other two were exercised by hand. Both were exactly the kind that would otherwise have failed silently:

| direction | measured |
|---|---|
| no criterion at all (S9) | `rc=2`, refused; **no runner and no marker** created |
| criterion already passes (S9b) | `rc=2`, refused |
| criterion **hangs** (no selftest case) | `rc=2`, `elapsed=300s`, `REFUSING TO DISPATCH: … TIMED OUT (rc=124)`, markers `53->53` — nothing created |
| deliberate **override** (no selftest case) | **allowed** — it dispatches, and `BYPASSED <reason>` is written to the gate log |

The override direction was tested with `LANE_MODEL_CMD=false`, so proving the **allow** path cost no
tokens. Refusals are deliberately *not* logged — only dispatches and bypasses are — which is why the log
holds 8 lines rather than 11.

### §2's HARPP claims were re-measured rather than carried over

Not flagged as unverified, but re-run anyway instead of trusted: `node tools/harpp-ui-verify.js` reports
**11 pages, 11 × status 200, 0 console errors, 0 contrast pairs under 4.5**, the only radius above 3px
being the unread badge. Independently reproduced on 2026-10-04.

Also checked while reviewing: a .ai/runs marker named `inj$(touch lane-injected)….landed.json` exists from
an earlier injection test, and **no `lane-injected` file was ever created** — the lane name is used
literally and is never evaluated. That is the one failure mode where a harness this size would be
dangerous rather than merely unhelpful.

What changed, and the falsifiable claim attached to each:

| file | change | claim to falsify |
|---|---|---|
| `tools/model-chain.txt` (new) | the ONE ordered chain: Sol → DeepSeek Flash → Terra | removing the third line makes the chain check FAIL (the 2-model defect is the must-refuse case) |
| `tools/lane-model.sh` | `$LANE_MODEL_CHAIN` + `lane_model_chain_ok` + a warning when a caller passes fewer than 3 models; empty-chain warning at source time | the chain-fall-through test reaches Terra only while the third model is in the file |
| `tools/lane-model-selftest.sh` | 5 new cases: length, order, 2-model rejected, canonical accepted, third-model fall-through | deleting the rejection half turns a real guard into decoration |
| `tools/lane.sh` | pre-dispatch acceptance gate + recorded override log; S9/S9b/S9c; every fixture lane now dispatches through the gate | S9b passes only if the gate genuinely refuses an already-passing criterion |
| `tools/lane.sh` (comments) | removed two stale statements that contradicted the code: the header claimed an exit-0 log could be "unavailable", and `classify_log` carried two versions of its own output list | a comment that contradicts the code is how the next person reverts the fix |

### The two stated risks — both resolved, one of them by measurement

1. **The gate makes `--acceptance` + `--pass-looks-like` mandatory for `lane.sh run`.** Still true, and
   still intended: a brief that documents the old invocation is refused with a precise message, and the
   override exists and records its reason. Verified in the **allowing** direction as well as the refusing
   one — a gate that refuses everything is also a wrong guard.
2. **The chain's third model, `openai-codex/gpt-5.6-terra`, was asserted from the registry rather than
   proved.** It is now proved: `pi --print --model openai-codex/gpt-5.6-terra "..."` returns `TERRA_OK`.
   Worth recording **why** it looked unverifiable — it does not appear in `~/.pi/agent/models.json`, which
   holds no `gpt-5.6-*` entry at all, because Codex subscription models are resolved by the provider and
   not by the local store. The fallback is genuine; no lane wastes an attempt discovering otherwise.

### Review finding: the chain fix did not reach every consumer

`tools/model-chain.txt` is a single source only for the tools that read it. Three still hard-code their own
model list — the exact defect the file was created to remove:

| file | hard-coded list |
|---|---|
| `tools/pi-arch-review.sh` | `run_one "deepseek-v4-pro"` then `run_one "openai-codex/gpt-5.6-sol"` — still a **two**-model chain, so an exhausted pair stops the architecture review |
| `tools/pi-arch-debate.py` | default `MODEL_B = "deepseek/deepseek-v4-pro"` |
| `.github/AGENTS.md` | documents `deepseek-v4-pro` as the reasoning/architecture model and tells a reader to invoke `pi --model deepseek-v4-pro` |

**Measured rather than assumed — which is why this is a finding and not a defect:** `deepseek-v4-pro` was
retired on 2026-09-14, but both spellings still resolve. `pi --print --model deepseek-v4-pro` returned
`V4PRO_OK` and `deepseek/deepseek-v4-pro` returned `SLASH_OK`, so the legacy alias still maps to Flash and
these references are **stale documentation rather than a wasted attempt**. The only genuine residue is
that `pi-arch-review.sh` carries the two-instead-of-three defect this file exists to prevent.

**Deliberately not fixed here — and not because it is out of scope, but because it is not the one-line
swap it appears to be.** `run_one` writes `arch-<name>.jsonl` and then **parses that JSONL** to produce the
review text; `lane_model_run` writes a plain-text log and reports which model served. Pointing the script
at `$LANE_MODEL_CHAIN` therefore raises its own design question — does the architecture review keep its
JSONL trace, or adopt the shared log format? That is a small piece of work with its own verification, not
a drive-by edit during a verification pass.

## 6. Tooling and editor configuration — decided 2026-10-04

Neither item below is a product change. They are recorded because the *reasoning* is the reusable part,
and because both were settled by measurement rather than preference.

### Java language server — excluded from the Android trees

Measured: `android/` holds **71 Kotlin files and zero hand-written Java**. The only `.java` files are
generated Gradle dependency-accessors (`…/.gradle/8.9/dependencies-accessors/…`), and **no Kotlin
extension is installed**. So the Java language server was importing two Android Gradle projects to serve
nothing. `android/daily-ledger/.idea/` plus `sdk.dir=~/Android/Sdk` both show Android Studio already owns
that tree.

`.vscode/settings.json` now sets `java.import.exclusions` to redhat.java's **four defaults repeated
verbatim** plus `**/android/**`. The repetition is load-bearing: overriding that key **replaces** the
defaults rather than extending them, so a one-item list would have quietly un-excluded `node_modules`.

**Also true, and listed so nobody re-derives it:** `vscode-java-debug` cannot debug this app at all. It
attaches to JVM processes — a `main()`, a Gradle test JVM, a remote JDWP port — whereas an Android app runs
on a device or emulator and is launched through `adb`, which is Android Studio's job. Nothing was broken;
the capability simply does not apply here.

### Pylance MCP — a CHAIR-side tool, now structurally impossible to forget

Decision: **off by default, on for interactive Python work.** Every reason is a measured property of the
tool, not a preference:

| claim | measurement |
|---|---|
| Pylance does ship an MCP server | `contributes.mcpServerDefinitionProviders` = `[{id: "pylanceMcp", label: "pylance mcp server"}]` |
| …but there is nothing to spawn | registered from inside the extension at runtime; `package.json` declares **no CLI entry point** and `dist/bundled/` is data only (stubs, indices, wasm) |
| a **lane** cannot reach it anyway | `~/.pi/agent/settings.json` carries **no `mcp` key** — pi has no MCP at all |
| a lane has no type checker either | `pyright`, `mypy` and `ruff` are absent from PATH **and** from `.venv`; `npx pyright` fails |
| a lane's real oracle is already here | `.venv/bin/pytest` |

So "make it part of the harness" cannot mean *turning MCP on for a lane* — that is the guard-that-can-never
-fire this document already has a rule about. What the harness can do is say the true thing at the moment
the decision is made. `run` now takes an optional `--touches="<path,path>"` and, when the touched set is
**python-dominant**, prints:

    == tooling advisory: python-dominant (2/2 touched files are .py) ==
       Pylance MCP is CHAIR-side only: pi lanes cannot reach it, and no type checker is
       installed (pyright/mypy/ruff absent). Give this lane an EXECUTABLE criterion -
       pytest is at .venv/bin/pytest.

and records it in `.ai/runs/mcp-advice.log`. Three deliberate choices, each of which a lazier version
would have got wrong:

- **Advisory, never a refusal.** A wrong refusal is worse than no advice, and this repo's measured history
  is dominated by false reds. An advisory cannot manufacture one.
- **Python-*dominant*, not "any `.py`".** One `.py` among ten `.php` is not a Python lane; firing there would
  train the reader to ignore it.
- **Both directions asserted (S10/S10b).** A one-directional test passes just as well against an advisory
  that fires unconditionally, so the must-refuse case is a php/disyl touch list.

### S8 flaked once during this session — recorded, not papered over

The suite flaked once on 2026-10-04 — at 16 cases, before the notification gate was added — failing as
`S8 … rc=3 reason=<empty> journal=0`. That is the pre-existing race documented above: the runner correctly
returns 3, but the watchdog has not yet committed its `timeout` record when the assertion reads the journal.
It is **not** caused by any change made that day: the tooling advisory runs *before* dispatch and touches
nothing on the landing path, and zero stray watchdogs or runners were present. The suite then passed
**16/16** on both following runs, and passes **18/18** with the notification gate in place.

### Desktop pop-ups — off by default, because they were flooding the owner's screen

The owner's report: *"it's flooding my view and i have to manually turn them off."* The cause came from the
stub log rather than from reading the code:

    notify -u critical -a lane       lane st3: crash status: PASS     <- commit_landing (the runner)
    notify -u critical -a lane-watch lane st3: crash status: PASS     <- lane-watch.sh, the SAME landing

**Two different programs toasted the same landing**, so a landing meant two pop-ups — and this suite lands
~16 fixture lanes per run, from the commands that run most often. Both are now off by default:

| switch | effect |
|---|---|
| *(nothing)* | **silence** — the default; `LANDINGS.log` and the journal still record every landing |
| `LANE_NOTIFY=1` or `run --notify` | the runner toasts once per landing — the authoritative notifier |
| `LANE_WATCH_NOTIFY=1` or `lane-watch.sh --notify` | the watcher toasts too; only for watching without a dispatch |
| `LANE_NOTIFY_CMD=<cmd>` | replaces `notify-send` — how the selftest asserts the path with a stub |

The gate covers **only the pop-up**. `LANDINGS.log` and the journal are written unconditionally, so this
removes noise and no information. S11 asserts **exactly one** notification per landing (its first version
counted both programs and read 2 — my assertion was wrong, and the defect behind it was real), and S11b
asserts silence via `env -u LANE_NOTIFY`, i.e. it tests the **default** as a default rather than an explicit
zero.

---

# STATE OF PLAY — 2026-10-05

## HARPP is chat + deploy + workspaces. The decisions surface is gone.
Owner direction: *"let's discard the decisions lane. harpp becomes better without it. as an away tool,
linked to my workstation, all i want is a chat lane, aside from the deploy and workspaces"* — and
*"no email, it will flood my inbox. we stay in chat"*.

`36cfcfda` made escalations chat-only. They never needed a decision row: `harpp_notify()` **already sent
the chat message first** (`harpp_client.py:561-566`) and only then additionally called `submit_decision()`
for an actionable type (`:567-582`) — so the row was a duplicate on top of a message sent anyway, and
dropping it cannot silence the channel. The decision pages, nav and JS are removed. The decision API,
services, ADR registry, MCP/Pi tools and **all tables and rows are deliberately kept frozen**: they are
invisible to the owner, consumed by the wake/pi/workflow subsystems, anchored by
`harpp_adrs.decision_ref`, and irreversible to delete. Removing them is a separate owner decision.

A first lane correctly reported **BLOCKED** here — deleting the decisions API would have broken the wake
workflow (`harpp_wake.py` :2132 RELEASE_READY, :2177 BLOCKED, :2211 DECISION_REQUIRED). Its script
(`tools/lane-harpp-chat-first.sh`) is kept as the evidence that produced the narrower contract.

## Landing reports go into a HARPP conversation
Owner: *"on progress, it would be better if i get a report when the process delegated to a model or you
lands."* `tools/lane-notify-harpp.sh` (`bd6c2be4`) is notify-send compatible and posts the landing through
the hook that already existed — no harness change:

    LANE_NOTIFY=1 LANE_NOTIFY_CMD="$PWD/tools/lane-notify-harpp.sh" \
      bash tools/lane.sh run <name> <script> --acceptance=... --pass-looks-like=...

Target: `$LANE_NOTIFY_HARPP_CONVERSATION`, else the conversation titled `$LANE_NOTIFY_HARPP_TITLE`
(default **"Lane landings"**, created 2026-10-05 = conversation 185). If nothing resolves it SKIPS and
names the titles it checked — it never invents a target. Always exits 0, 30s timeout, refuses selftest
fixture names. **The harness cannot create a conversation**: `POST /api/v1/harpp/conversations` is
owner-authenticated and the bridge exposes only list + archive.

## File attachments: phone -> workspace
`a1876f7b`. The owner picks a file in the messenger; the harness pulls it into the workspace:

    tools/harpp-bridge/harpp attachment list --conversation-id <id>
    tools/harpp-bridge/harpp attachment pull <attachment-id>

Guards, because an upload endpoint is the most dangerous thing here: opaque storage names (the client
filename is metadata only), extension allowlist plus byte-derived MIME that must agree with it, a 10 MiB
cap enforced *before* the destination write, storage outside `public/`, downloads only through an
authenticated route that re-resolves under the storage root, and uploader + tenant enforced in SQL.
29 checks, each with a mutation that turns it red.

## Two traps worth not re-discovering
1. **`bridge/messages` returns OWNER messages only** (`pollMessages` -> `listOwnerMessagesForHarness`).
   It is *always* `[]` immediately after the harness posts, and that is not a failure. Verify a harness
   post from the `send_message()` response or the conversation's `unread` — never from `poll_messages`.
   This cost one false red on 2026-10-05, where a landing report that HAD arrived (`message_id 1941`,
   conversation 185 `unread=1`) looked like a silent failure.
2. **`bash modules/harpp/tests/run-all.sh` passes in its DEFAULT (non-mutating) mode only.** With
   `HARPP_ALLOW_MUTATING_TESTS=1` it REFUSES at the isolated-tenant guard — *"database 'harpp_tenant' is
   not explicitly isolated"*. That is the guard working as designed, but it means a green run-all does
   **not** cover the mutating half; those tests must be run explicitly against a genuinely isolated DB.

## Falsifying the criterion before dispatch kept paying
The attachments criterion is deliberately **name-tolerant**: it asserts the two *outcomes* — an owner POST
route and a bridge GET route containing "attachment" — not the route strings, because a criterion that
dictates the path is the false-red class this repo has already paid for repeatedly. Measured both ways
before dispatch: `attachment` -> exit 1 (0 owner / 0 bridge routes), `message` -> exit 0 (both sides
found). Migration `020` was checked free (019 was highest) before it was named in the contract.

---

# STATE OF PLAY — 2026-10-07

Owner brief: *"review our harness and find out areas we can optimize, enhance and make it better. i am
not keen on making it mechanical but more into an intuitive performance."* The chair consulted ChatGPT
through `tools/harpp-bridge/chair_consult.py` first (grounded by default), then planned, then
delegated to Sol lanes.

## 1. Five defects, each measured before it was touched

| # | defect | the probe that proves it |
|---|---|---|
| 1 | **No post-landing verification.** The gate proves the criterion FAILS before dispatch; nothing re-ran it after. The only post-landing evidence was the lane's own `Status:` line. | `harness-acceptance-verify-probe.sh` — 0/4 before |
| 2 | **`classify_log` read log CONTENT before the EXIT STATUS.** A log quoting `rate limit` with `rc=0` was classified `quota`, so `run` printed `VERDICT: CRASHED on quota` while returning 0. | `classify-log` on a fixture log |
| 3 | **A clean exit-0 with no `Status:` line was `unknown`**, with a verdict that read like an error. 9 of 25 real landings (2026-10-03). | same |
| 4 | **`changed_files` was the whole tree's dirty count.** A lane that lived 9 seconds reported `11`. The scope check cannot use it. | `harness-changed-files-probe.sh` — 1/4 before |
| 5 | **The chain handles unavailability, never a HANG.** Caps 4500s x 3 models against a 7200s lane budget, so model 3 could be unreachable. Cost a full 40-minute budget on 2026-10-04. | `harness-chain-liveness-probe.sh` — 1/4 before |

## 2. What landed

- **`classify_log` branches on the exit status first.** Content may only CLASSIFY a failure. A clean
  exit-0 with no `Status:` is `no_report`. Selftest `S13`/`S13b`.
- **Post-landing acceptance verification.** `record` takes the criterion as a 5th argument; the
  EXIT trap re-runs it bounded by `LANE_ACCEPTANCE_TIMEOUT` (300). The marker and journal gain
  `acceptance`, `acceptance_exit`, `acceptance_cmd`, `acceptance_log`, `verdict`. Rules: PASS ->
  `VERIFIED`; FAIL/TIMEOUT/ERROR -> `NOT_VERIFIED`; SKIPPED -> `EXECUTION_ONLY` (never VERIFIED).
  **`Status:` does not participate.** `run`'s exit code is unchanged — the verdict is REPORTED, not
  encoded (`S3`/`S4b`/`S7`/`S9c` depend on it). Selftest `S12`/`S12b`/`S12c`.
- **Two verdict axes, never one.** An early implementation replaced the execution warnings with the
  governed verdict, so a crash under a recorded override read only *"EXECUTION ONLY - nothing was
  checked"* and the reader lost *"partial edits may exist, check the tree"*. `S3`/`S4` stayed green
  throughout — they assert the marker and the return code, never the text a human reads. Fixed, and
  pinned by `S15`/`S15b`. **A guard that does not cover the artefact the reader uses is not a guard
  on it.**
- **Budget-aware model chain.** No attempt may consume enough of the remaining budget to starve the
  fallback: cap = remaining / attempts left, floored at `LANE_MODEL_TIMEOUT_MIN` (300) under
  `LANE_MODEL_BUDGET` (6600). An explicit `LANE_MODEL_TIMEOUT` still wins. A cap kill is named a
  timeout, not blamed on credentials.
- **Lane-scoped `changed_files`.** Delta of two sorted snapshots, plus `git diff --name-only` when
  HEAD moved. New marker fields `changed_paths`, `tree_dirty`, `dirty_before`,
  `changed_files_basis`. A path already dirty at dispatch is **never claimed** — the ambiguity is
  REPORTED (`dirty_before`) rather than guessed at. No worktrees, no hashing, no registries.

## 3. The three criterion defects, all found by the lanes, all the chair's

This is the headline, not a footnote. Each lane reported `BLOCKED`/`PARTIAL` with a precise reason
instead of working around a criterion it could see was wrong:

1. **L2 was not falsified by the named mutation** (chain-liveness lane). With budget 9 over 3
   attempts, handing every attempt the whole remaining budget still reaches model 3 because the MIN
   floor caps the later attempts. The criterion gained **L5** (the whole run finishes inside its
   budget), which IS falsified by that mutation — proven: forcing `attempts_left=1` makes L5 red at
   `elapsed=17s > 15s` while L2 stays green.
2. **D2 was UNSATISFIABLE** (changed-files lane): D1 left its own fixture dirty and D2 did not reset,
   so D2 saw two dirty paths while asserting one. The only way to satisfy it would be to hide prior
   dirty paths — which the contract forbids.
3. **D4 was state-dependent**: `commit.lock` is deliberately persistent, so `record` refuses on the
   second and every later run and writes no marker, and the JSON check then reads a file that does
   not exist. It passed on the first ever run and failed 60 seconds later on the gate's run.

Plus a fourth the chair found while thinking about the lane's report: the absolute `tree_dirty == 2`
assertion was meaningless on a shared tree — these are governed lanes sharing ONE working tree. Now
path-specific and basis-bound; proven on a genuinely dirty tree (`dirty_before=3`, `changed_files=1`).

**A probe whose directions are not independent is not four probes — it is one probe with three
aliases.** Sixth occurrence of "the criterion is the defect" in this repository, and the first time
the implementing lane caught all of it before the chair did.

## 4. Instruments you can run right now

    bash tools/harness-acceptance-verify-probe.sh   # 4 passed, 0 failed
    bash tools/harness-changed-files-probe.sh       # 4 passed, 0 failed
    bash tools/harness-chain-liveness-probe.sh      # 5 passed, 0 failed
    bash tools/harness-review-probe.sh              # completeness only, by design
    bash tools/lane.sh selftest                     # 28 passed, 0 failed
    bash tools/lane-model-selftest.sh               # 19 passed, 0 failed

## 5. The self-hosting hazard, measured — do not re-discover it

`bash` re-reads a script file as it executes, so **a lane that edits `tools/lane.sh` kills the
running monitor** (syntax error at a bogus line number, `rc=2`, no landing recorded). The generated
runner still records, so nothing is lost — but the dispatch looks broken. Second-order: the runner is
written BEFORE the edit and calls `record` with the **legacy 4-argument form**, so `record` must treat
a missing criterion as `SKIPPED`, never an error. Both contracts require it and the markers prove it.

Residual risk, not fixed here: the acceptance re-run happens inside the EXIT trap, so a lane whose
process ends within ~210s of its own `--timeout` can still be preceded by the deadline watchdog,
which commits `unverified` and (by design) makes `commit_landing` refuse the real record. Narrow, and
unfixed rather than papered over.

## 6. What was deliberately NOT built

A richer verdict state machine, NLP `Status:` parsing, a second verification framework, a per-model
watchdog service, Git worktrees or ownership registries for `changed_files`, or log-content heuristics
for successful processes. The whole architecture is four lines:

    process exit code   -> did the runner execute
    log classifier      -> only CLASSIFIES a failure
    acceptance command  -> is the requested criterion true NOW
    everything else     -> diagnostic metadata
