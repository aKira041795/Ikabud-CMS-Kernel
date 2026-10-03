# Chair Ledger

**This file is the chair's presence.** It exists so that an agent resuming this work
from a different client, hours later, continues with the same *judgement* - not merely
the same task list.

Read this FIRST when woken with owner input. Nothing here is prose for encouragement:
every line is a state that changes what you should do next.

## How to read this

| section | meaning | what you must do |
|---|---|---|
| `owner_direction` | the owner's last direction, verbatim | treat as authoritative; do not reinterpret |
| `in_flight` | work started, not yet finished | check the artifact exists before trusting it |
| `proven` | verified, with the exact command | re-run the command if it matters; do not re-verify by reading |
| `suspected` | believed but NOT proven | **do not act on it**; run the named probe first |
| `blocked` | needs the owner | ask in chat; only use a decision record for gated/irreversible actions |
| `verification_lessons` | mistakes that cost real time | these are the checks that must not be skipped |

## The rule that makes this file worth having

**A claim is not `proven` until its command is written next to it.** An agent that
"reports PASS" without a runnable check is the failure mode this ledger exists to
prevent. Measured 2026-10-03: two lanes reported success while carrying a real defect,
and one helper rejected correct work. In all three cases the fix was a runnable probe,
never a better description.

Corollary: when a check is added, it belongs in a self-test that runs without spending
tokens. `/memories/repo/false-reds-and-harness-2026-10-03.md` records why.

---

## Reporting contract — presence is the same SUBSTANCE, not the same words

The owner's requirement: *"presence means same reporting, output updates, flagging
concerns"*. Phrasing may differ between agent instances; the following may not.

**Every finish reports, in this order:**
1. `status` — PASS / BLOCKED / PARTIAL / CHANGES_REQUIRED
2. what changed, as files or commits
3. **the command that proves it** — not a description of the result
4. what remains suspected or unproven
5. what is next

**While work runs:** say so. A lane dispatched, an interim finding, a verdict reached —
report at those points rather than going silent until the end. Silence is indistinguish-
able from a stall, and the owner cannot tell the difference when away.

**Finish means the work is done AND verified.** Not "tests were run" — the output shown.

### Flag these without being asked

Prose does not hold a behaviour; a trigger does. Flag at each of these:

- **My own instrument produced a wrong result.** (2026-10-03: a helper rejected correct
  work; a parser compared the wrong field; a stash/restore A/B was confounded. All three
  were found by me, and all three were reported rather than quietly fixed.)
- **A lane reported PASS and I found something it missed.** Say both the PASS and the gap.
- **A check failed for the wrong reason** — including a test that is flaky rather than broken.
- **Evidence contradicts something I said earlier.** Say so explicitly and correct the
  earlier claim. (2026-10-03: I claimed `harpp_wake.py` was bloated; measurement showed
  16 lines of dead code, and I reported that instead of finding a cleanup to justify the claim.)
- **I am about to weaken validation, security, error handling, or a test** to make a number
  go green. Stop and report instead.
- **A finding is real but has no exposure.** Distinguish "latent, contained" from
  "live, affecting users" rather than reporting both as defects.
- **I am blocked on product direction**, as opposed to on technique. Technique is mine to
  decide; direction is the owner's.

### What presence does NOT require

Identical wording, identical cadence, or the same model. Per this repo's stability
principle: optimise for stable contractual outcomes, not identical output.

And keep it lean — verbose reporting is the failure mode the owner already paid for.
Report the substance, skip the ceremony.

---

## owner_direction

> "harpp as a tool when i am away, still feels and behaves like i am at my workstation.
> get things done. pi effectively used, models properly delegated, you as chair has the
> presence in both workstation and away like you've never left. same autonomous,
> dependable senior dev handling a team of models"
>
> Earlier the same day: decisions are becoming obsolete now that the assistant is
> autonomous — "you can just chat with me thru harpp if you're done and i can answer";
> desktop settings "need not be different with the app"; VS Code tooling to be measured
> and leveraged; objective is "a handy app i can access my desktop workstation and never
> lose updates because it uses one process in both".

## in_flight

- **DiSyL `{extends}` root fix** — stages 1/2/2b landed and pushed (`814c88d6`, `8fb6e358`,
  `bead7377`). Flag `DISYL_EXTENDS_COMPILED` ships **OFF by default**; the guard was NOT
  flipped. Reason: the flip is global (364 templates) and only daily-ledger has browser
  coverage. Do not flip it as a tidy-up.
- **HARPP UI redesign** — DONE, committed `2035bec5`. Palette (Slate & Signal), 3px radius
  ceiling, the flow fixes and three proven-only removals, all verified in a real browser.
  Commands are in `proven` below.
- **HARPP presence work** — the ledger exists; the completion push is still unproven end-to-end
  (see `suspected`). HARPP now runs locally against tenant 1232 on `harpp.test`, so that probe is
  finally possible.

## proven

Each line names the command that proves it. Re-run rather than re-read.

- DiSyL parity is 178/178 with the flag off *and* on:
  `php tests/disyl_parity_test.php` and `DISYL_EXTENDS_COMPILED=1 php tests/disyl_parity_test.php`
- Daily-ledger E2E passes with the flag off and on (~2.0-2.1m each):
  `APP_URL=http://baronledger.test npx playwright test tests/browser/daily-ledger-production-journey.spec.js`
  - `APP_URL` is **mandatory** — unset, the config falls back to `palsystem.test` and every
    journey 404s. This cost real time; it looks like a product regression and is not.
- The 4 corpus failures are PRE-EXISTING, not regressions: `php /tmp/corpus_on.php` against
  stashed (pre-change) code reported **6**; with the change, **4**.
- Model fallback works, 14 cases, no tokens spent: `bash tools/lane-model-selftest.sh`
- Harness selftest: `bash tools/lane.sh selftest`
- HARPP suite (pytest is NOT installed; use unittest):
  `python3 -m unittest discover -s tools/harpp-bridge/tests`
- One signature source, zero literal duplicates:
  `grep -cE 'rate\.\?limit|too many requests|model is not supported' tools/lane.sh tools/lane-model.sh tools/harpp-bridge/harpp_wake.py`
- HARPP UI acceptance, 11/11 pages in a real browser: `node tools/harpp-ui-verify.js`
  Expect 200 on every page, 0 console errors, 0 contrast pairs under 4.5, and the ONLY
  border-radius above 3px is `span#harpp-unread.badge` (a deliberate shape, allowed).
  It resolves playwright from the repo root, so it runs from any cwd without `NODE_PATH`.
- No JS selector was broken by the UI rewrite:
  `python3 tools/harpp-selector-audit.py harpp` — expect `missing=0`, rc=0 (88 checked).
  `js_created=1` is `.save-user`, a class the JS builds itself — correct, not a finding.
- The palette is on screen, measured in PIXELS not prose: dominant pixels are `#0d1014` /
  `#15191f`, the old navy+cyan appear ZERO times, and `#7aa2f7` is 0.08% of pixels (that
  scarcity is what makes the accent readable as "interactive").
- `login.disyl` is standalone and must NOT use `var()`: `grep -c 'var(--' templates/modules/harpp/login.disyl`
  Expect 0. It carries the palette as literals because no token is defined on that page.

## suspected

**Do not act on these. Run the probe first.**

- *Suspected:* S8 in `lane.sh selftest` is still ~1-in-3 flaky. *Probe:* run the selftest
  3x with a clean process table (`pkill -f 'lane.sh watchdog'` first) and 3x without; a
  single run proves nothing here — a dirty table already fooled me once into blaming a lane.
  Known cause: after its wait the watchdog does `pgrep -f "$name.runner.sh"` and declines to
  commit if `timeout(1)` left an orphaned inner bash alive.
- *Suspected:* flipping `DISYL_EXTENDS_COMPILED` is safe for modules **other than**
  daily-ledger. *Probe:* render their real pages both ways and byte-compare. Parity covers
  constructs, not pages; the include leak survived 170/170 for exactly this reason.
- *Suspected:* the completion push reaches the owner end-to-end. *Probe:* run the bridge against
  tenant 1232 on `harpp.test`, complete one bounded task, and confirm a COMPLETED notification
  arrives without opening the workstation. `COMPLETED` was added to `OWNER_MESSAGE_TYPES` and
  `IMPORTANT_MESSAGE_TYPES`, but "the type is routed" is not "the push arrives".
- *Suspected:* `php ikabud migrate` masks failures. It reported a "stale migration history" skip
  while `MigrationRunner::migrate("harpp")` succeeded (19 migrations). *Probe:* run the runner
  directly and compare with what the CLI claimed. A `! skip` is not evidence.
- *Suspected:* `tenant:provision` cannot seed an admin for an email-only auth module — the generic
  insert hardcodes `username`/`full_name` while the block already loads `$authSpec` and ignores
  `email_column`. *Probe:* provision a throwaway tenant whose auth module is email-only; expect a
  loud failure, not silent mis-seeding. This is a kernel defect, not a HARPP one.

## blocked

- Does HARPP keep its own wake/session plumbing, or does the workstation become an AHP
  agent host with HARPP shrinking to governance + memory? Recommended: the latter. This is
  product direction, so it needs the owner.
- Bluehost deploy steps remain the owner's: back up the DB (migration `062` rewrites ~186
  rows), `php scripts/generate-release-manifest.php`, deploy + `db/tenant-upgrade.sql`
  (applies `062`-`073`), **then** `modules/daily-ledger/database/repair_login_names_20260918.sql`.

## verification_lessons

Mistakes that already cost this project real time. Not advice — rules.

1. **A lane's PASS is not evidence.** Verify against an instrument you own. Two lanes passed
   their own tests while carrying a real defect.
2. **Success is decided by exit status; content only classifies a failure.** Matching an
   unavailability word against a *successful* run's log rejected correct work, because the
   lane was implementing the unavailability detector and therefore wrote the word.
3. **A false red costs as much as a false green.** Both make the instrument untrustworthy;
   the false red is the one that destroys correct work.
4. **Before blaming a change for a failing test, check the process table and re-run N times
   both ways.** A single A/B on a stateful harness proved nothing.
5. **Verify a check before believing its finding.** A broken instrument is worse than none.
6. **Bound every bare number in a pattern.** `429` alone matched `20261003T164429` and
   `14290`, misclassifying a real timeout as a quota.
7. **Measure the real object, not a proxy.** Timing a `COUNT(*)` instead of the page query,
   and grepping a limit that a later `LIMIT` clause made irrelevant, both produced wrong
   conclusions.
8. **Do not measure while the thing is being written.** Auditing the UI while a lane was editing
   made one page fail with `ERR_ABORTED`; it was clean on a quiet tree. I nearly reported a real
   regression that did not exist. Wait for the writers to stop or the result is about timing.
9. **A clever pre-filter can create the false negative it was meant to prevent.** Asking "is this
   class built dynamically?" with a pattern for `'status-' +` missed a class literal sitting inside
   a ternary, and nearly had me delete a LIVE class. The plain repo-wide token count I ran second
   was already correct. When a direct search answers the question, do not pre-filter it.
10. **Two patterns for one concept will drift.** `status_line()` and `classify_log()` each carried
   their own status regex; the stricter one announced a lane as having no status line while its own
   landing marker contained one. The fix was one calling the other, so they cannot diverge again.
