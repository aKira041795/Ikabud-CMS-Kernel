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

- **DiSyL `{extends}` compiled flag — DECIDED: do NOT flip.** A differential corpus of **368**
  templates (frozen under /tmp, deterministic fixture SHA-256, no normalisation) found **95
divergent** templates and 11/32 divergences in the extends+include subset. Cache-path was PROVEN
  distinct: compiled artifacts **0** with the flag off vs **349** with it on, and repeat runs showed
  0 differences, so the comparison was real and not a stale-cache artifact.
  **Qualification (mine, after reviewing the probe):** the divergences are about
  UNDEFINED-VARIABLE comparison semantics, not broken templates. The probe's own diagnostics showed
  undefined optional fixture variables in 277 interpreted / 40 compiled renders, and the one case I
  traced by hand (`daily-ledger/cashier/ledger.disyl`, an `{if incoming_count > 0}` guard) cannot
  occur in production because `incoming_count` is always set (`handlers.php:5986`,
  `helpers.php:1285`). So "95 broken templates" would be an over-claim and "it's fine" would be an
  under-claim. The flag stays OFF; the real open question is the undefined-variable semantic gap.
- **HARPP UI redesign** — DONE, committed `2035bec5`. See `proven`.
- **HARPP presence work** — see `proven`: the server side is fixed and tested; delivery is
  blocked by the origin, not by HARPP. Flagged as a product constraint below.

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
- **Completion notifications are correct server-side.** `IMPORTANT_MESSAGE_TYPES` includes
  `COMPLETED`, `isImportant()` returns true for it, `dispatch()` calls `dispatchToUser()`.
  `python3 -m unittest discover -s tools/harpp-bridge/tests` -> **310 tests OK**, and
  `test_harpp_wake.py` covers COMPLETED with a must-refuse half ("a failure must never be announced
  as COMPLETED"). So the earlier `PROGRESS`-vs-`COMPLETED` defect is closed.
- **Web Push is STRUCTURALLY IMPOSSIBLE on `http://harpp.test`**: `node tools/harpp-push-capability-probe.js`
  reports `isSecureContext=false`, `serviceWorker` undefined, `swRegistration=null`, 0 console
  errors. A push subscription needs a service worker; a service worker needs a secure context.
  HTTPS (or a localhost origin) is required - this is why "I have to check the workstation".
- **tenant:provision auth-column mapping fixed**: `php tests/tenant_provision_auth_mapping_test.php`
  -> PASS 5, FAIL 0 (email-only seeding, default mapping unchanged, unresolved identity fails loudly).
- **`php ikabud migrate` masking fixed**: `php tests/cli_tenant_migrate_sync_test.php` ->
  **PASS 23, FAIL 0, SKIP 0**, including "explicit CLI migrate truthfully explains the no-entry skip".
  The fix deletes the `cliModuleTenantTargets()` wrapper that implemented treat-base-failure-as-
  tenant-only and inlines `tenantSeparateDatabaseMigrationTargets()` at the call site.
- **The local HARPP has now carried a real message** (the first time): `node tools/harpp-message-path-probe.js`
  -> conversation created, message persisted, and a notification row created (channel=push,
  status=pending). The probe asserts the OUTCOME, not that a button was clicked - an earlier version
  asserted the click and proved nothing while the database stayed empty.
- **Messenger prompt wart fixed** (`9efbfac0`): two native `prompt()` dialogs replaced by one styled
  in-page dialog; Cancel now says "New conversation cancelled." instead of returning silently, and an
  invalid session id is rejected inline with the reason rather than producing an unexplained 422.
  Verified by me: `native dialogs: 0`, `cancel not silent: true`, radius 2px/3px, selector audit
  missing=0. KNOWN REMAINING: a native `confirm()` still guards the destructive delete action, and the
  probe's "native dialogs: 0" covers only the create/send paths it exercises.

## suspected

**Do not act on these. Run the probe first.**

- **S8 flakiness — DISPROVEN (the suspicion was stale).** `bash tools/lane.sh selftest` run
  **12 times** directly: **12/12 clean, S8=PASS every time, 11 passed / 0 failed each** (~48s per
  run). The suspicion described the state BEFORE the watchdog-poll leak fix (`5f7086c6`) landed the
  same day — and it named that exact mechanism ("a leftover watchdog committed a second landing, S8
  saw journal=2"). The leak half was measured too: watchdog process count was **0 after every run**
  (0 before, 0 after, 0 leaked), so the leak fix holds under repetition rather than merely in one
  sample. Lesson recorded: delegate the FIX, not a measurement that is only repetition, and check
  whether an earlier fix already addressed the mechanism a suspicion names.
  The two dispatches cost ~30 min and were killed by MY OWN brief: I told the lane to run
  `pkill -f 'lane.sh watchdog'`, and the lane's own process tree contains a `lane.sh` watchdog, so it
  SIGTERM'd its own supervisor (`exit=143 log=0b`). Never put a self-matching `pkill` in a brief.
- *CONFIRMED (was suspected):* `~/.config/harpp/config.json` duplicates concepts the control plane
  owns — it holds `cms.model`, `harpp_authority`, `tenant_id`, `advisor.backend`. Worse, it points
  at `tenant_id: 212` + `base_url: https://harpp.ikabudkernel.com` while the local test tenant is
  **1232** on `harpp.test`, so desktop and app genuinely can disagree. Probe (not yet run): change a
  value app-side and confirm the desktop adopts it without a local edit.
  SECURITY: that file holds a live bridge key, CMS token and Groq API key. Checked 2026-10-03 —
  none of the real values are in the working tree or in git history; the only `harpp_br_`/`gsk_`
  hits are the validator in `harpp_client.py` and untracked logs. Keep it that way.
- *Suspected:* interpreted and compiled DiSyL disagree on comparison of an UNDEFINED variable
  (`{if incoming_count > 0}` renders in one mode and not the other when the var is absent).
  *Probe:* render one template both ways with the variable deliberately absent, then present, and
  compare. This is the real finding behind the 95 divergences and is far narrower than "95 broken
  templates".
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
  agent host with HARPP shrinking to governance + memory? **RESOLVED — VS Code shipped the agent
  host.** 1.140 (2026-09-30) adds the Copilot harness on a dedicated **Agent Host Protocol (AHP)**
  process reachable from multiple windows, remote delegation (`list_agent_hosts`,
  `create_remote_session`, `get_remote_session`, `send_remote_message`), HydraFusion multi-model
  orchestration (draft/critique/revise/escalate) and multi-folder session isolation. Do NOT rebuild
  a scheduler VS Code now ships; HARPP's value is governance + evidence + presence. See
  `memories/repo/vscode-140-agent-tooling-2026-10-03.md`.
- **PRODUCT CONSTRAINT (needs the owner):** the push half of "presence" cannot work on an `http://`
  origin at all (no secure context -> no service worker -> no push subscription). Deciding whether
  HARPP is always served over HTTPS is a product/hosting decision. Until then the owner must open
  the workstation, which is the exact gap presence is meant to remove.
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
