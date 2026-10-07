# Doctrine — why the rules are what they are

Every rule here costs something and was bought with a specific failure. Nothing is stylistic. If you
change a rule, you are overriding a measurement — so the measurements are listed.

## Rule 1 — A model's own "PASS" is not evidence

A lane returned `status: PASS` with its own test suite **7/7 passing**, and contained a real, silent
behaviour regression. What found it was an independent differential corpus, not the lane and not the
lane's tests.

**Therefore:** the harness never derives a verdict from the model's report. Your acceptance command
is re-run after the lane lands, and *it* decides. The report is stored beside it as advisory text.

## Rule 2 — The exit status decides success; log content only CLASSIFIES a failure

A lane implementing an unavailability detector writes about "quota" and "rate limit" **by
definition**. A content-first check classified its successful, exit-0 run as `quota`, and the harness
announced `VERDICT: CRASHED on quota` from a call that returned `0`. A correct lane was nearly
rejected, and a second model was spent for nothing.

**Therefore:** branch on the exit status first. Content may subdivide a failure (a timeout is not a
quota failure) but may never overrule a success. In the code, `classify_log` orders its branches
accordingly, and `lane-model.sh` says it out loud: *success is decided by the exit status alone*.

## Rule 3 — A guard that cannot fail is worse than no guard, because it is trusted

Three guards written in one batch all passed on unfixed code. Two were found **only by an independent
review**, not by re-reading the work. One of them had certified the very leak it was written to catch.

A later example, from this harness's own suite: `D4` claimed to pin that a landing record with no
baseline says `basis=tree`. It *printed* the basis and never *asserted* it. So a record that lied
about its basis stayed green across all four directions.

**Therefore:**

- every check has a **must-allow and a must-refuse** direction, and the refusals are demonstrated;
- **a field that is printed but not asserted is documentation, not a guard**;
- and you **run the mutation**. In the review of this harness, 21 guards were attacked with 21
  mutations and **20 turned red**. The one that did not was a real defect. A described mutation is
  not a measurement.

## Rule 4 — The criterion is usually the defect

Six separate times, a "failing test" turned out to be a broken *acceptance criterion*, not broken
code. The shapes, all of which cost a dispatch:

| shape | example |
|---|---|
| Already true without the feature | `stripos($html,'pending') && str_contains($html,$date)` — the footer said "pending" and the date was in a cell, so it passed on unfixed code |
| Unsatisfiable by construction | `($row['x'] ?? 'x') === null` — `??` yields `'x'` when the value is null, so it is false for **every** input |
| Impossible to be empty | `git diff | grep "^-.*supervisor"` — the fix legitimately changes a line that *keeps* the word, so the grep can never be empty |
| The denominator grows | `grep '14/14 passed'` on a suite whose total becomes 20 once the feature exists |
| The exit code comes from a filter | `... | grep -E '[0-9]+/[0-9]+ passed'` — the pipeline's status is grep's, and the pattern matches `20/21 passed` too |
| Asserts a MECHANISM the change replaces | clicking `#new-conversation` once, when the new design requires filling a dialog |

**Therefore:**

- **measure the criterion against the unchanged tree before you dispatch.** A criterion that already
  passes cannot discriminate your change, so "green afterwards" proves nothing — which is why
  `lane.sh run` refuses it.
- assert **OUTCOMES** (a row exists, a message persists), never **MECHANISMS** (one click suffices),
  whenever the design changes the mechanism.
- when the criterion's exit code comes from a filter, **the filter's pattern is the criterion** — write
  it so "matched" and "passed" cannot come apart.
- **a lane that reports `BLOCKED` with a precise reason is doing its job.** Three lanes in one batch
  reported `BLOCKED`/`PARTIAL`, and every one of them was right about the criterion.

## Rule 5 — Two verdicts, because there are two questions

An early version replaced the execution-outcome warning with the acceptance verdict, so a lane that
crashed under a recorded override printed only `VERDICT: EXECUTION ONLY - nothing was checked` and the
reader lost `partial edits may exist, check the tree`. The self-test stayed green throughout: it
asserted the record and the return code, never the text a human reads.

```
VERDICT:   VERIFIED | NOT VERIFIED | EXECUTION ONLY      <- did the objective become true?
EXECUTION: landed / CRASHED / killed by timeout / quota  <- how did the run behave?
```

**A guard that does not cover the artefact the reader uses is not a guard on it.**

## Rule 6 — Bound everything, and never let one attempt starve the rest

A model ran a full 40-minute budget, never exited, and the chain never advanced to its second model —
because fallback triggered on **exit**, and a hang produces none. The whole budget was gone with
nothing recorded.

**Therefore:** a per-attempt cap derived from the remaining budget divided by the attempts left, so no
attempt can make the rest unreachable; an explicit override still wins; and a cap kill is *named* as a
timeout rather than blamed on credentials.

## Rule 7 — Consumption is recorded, never inferred

The watcher used to skip landings that were "already on disk when it armed", treating file existence as
"someone was told". Since it is disarmed most of the time, that made losing a landing the *normal*
path. It now reads an append-only journal through a cursor that records what was actually reported,
and the failure direction is over-reporting, never under-reporting.

## What is deliberately NOT here

No verdict state machine, no NLP parsing of the model's prose, no second verification framework, no
per-model watchdog service, no git worktrees, no ownership registries. The architecture is four lines:

```
process exit code   -> did the runner execute
log classifier      -> only CLASSIFIES a failure
acceptance command  -> is the requested criterion true NOW
everything else     -> diagnostic metadata
```

Every one of those was considered and rejected in favour of naming the problem instead of adding
machinery to hide it. If a case cannot be attributed, the record **says so** (`basis=unavailable`,
`dirty_before=N`) rather than guessing.

## The house style, in five sentences

1. Measure before you claim; the criterion first, against the unchanged tree.
2. Run the mutation that should make your guard fail. A guard you have not seen fail is a hope.
3. Prefer naming a limitation to hiding it behind a heuristic.
4. When a verdict contradicts its own evidence, the verdict is the defect.
5. Never weaken a test to reach green — report `BLOCKED` instead. That is a valued outcome.
