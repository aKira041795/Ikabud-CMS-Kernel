#!/usr/bin/env bash
#
# Lane: cashier-truth-3-states
#
# REMAINING OPEN ITEMS after the close-order/guard fix (531106f8).
#
# MEASURED BY THE CHAIR:
#   - The reconcile refusal is SWALLOWED. handlers-offline.php answers the whole batch with
#     ['ok' => false, 'reason' => 'expired'|'revoked'|'account-mismatch'|'schema-mismatch'|'inactive']
#     BEFORE it processes any op. The client discards it at ledger.disyl line ~914:
#         if (!res.ok) { return; }        <-- no message, no removal, no re-enrol, no signal
#     This silence is why a five-week data loss went unnoticed.
#   - updateGlobalStatus() has exactly TWO outcomes: 'All saved', else 'Saving...'. So a slow
#     connection, held data, an unsaved edit, an expired grant and a refusal ALL look identical.
#     The code itself records the cost: "pinned the status bar at 'pending', which read as the
#     cloud being slow."
#
# OWNER REQUIREMENTS (verbatim):
#   "visually guiding the user what to do and clearly state if internet connection is slow. so we
#    don't scratch our heads where the issue is primarily started"
#   "a good UI offers few clicks to the user. the backend takes care of the complexity"
#   "my point with an offline data present, user can retry or discard and re-encode."
#   "rekeying is approved"  (the paper report is the source, so a discard + re-type is legitimate)
#
# GUARDS THAT MUST NOT BE WEAKENED (owner):
#   "the closed day is a process set and only admin can re-open. that's a fact and a guard."

cd /var/www/html/applicationostest || exit 1
source tools/lane-model.sh

LANE_MODEL_CHAIN="openai-codex/gpt-5.6-sol,deepseek-v4-flash,openai-codex/gpt-5.6-terra"

PROMPT="$(cat <<'PROMPT_EOF'
You are implementing the CASHIER-FACING TRUTH layer for the Daily Ledger in the Ikabud repo at
/var/www/html/applicationostest. Work from `.ai/dl-held-data-retry-or-discard.contract.md` — READ IT
FIRST; it is authoritative and contains acceptance criteria A-M plus the simplicity constraints.

## The three defects (all measured, not theory)

1. **The refusal is swallowed silently.** The reconcile endpoint refuses the WHOLE batch up-front with
   `['ok' => false, 'reason' => 'expired'|'revoked'|'account-mismatch'|'schema-mismatch'|'inactive']`
   before it processes any operation. The client throws that away at `ledger.disyl` line ~914:
       if (!res.ok) { return; }
   No message, no removal, no re-enrol, no admin signal. A Miputak tablet's grant expired 2026-08-29
   and the cashier was never told; five weeks of encoding was lost in silence.

2. **One indicator renders every cause identically.** `updateGlobalStatus()` has two outcomes:
   `'All saved'`, else `'Saving...'`. A slow connection, held data, an unsaved edit, an expired grant
   and a refusal are indistinguishable to the cashier.

3. **Held data has no honest, actionable surface** — no cause, no retry outcome, no way to discard and
   re-key.

## Deliverables

**T1 — Never swallow a refusal.** Read the response's `reason` and surface it to the cashier in plain
language with the next action. Silence is the specific defect.

**T2 — THREE cashier states, rendered by ONE element whose TEXT changes:**
    1. `All saved` — normal case, nothing held, nothing to do.
    2. `Not saved — <plain cause>. Retry.` — the value did NOT save. The inline cause is in the
       cashier's own words (e.g. "the connection is slow", "offline access has expired"). Retry is one tap.
    3. `N still on this device — Retry / Discard` — held data exists.
   The precise diagnostic (expired / revoked / mismatch / storage failure, device id, dates, payloads)
   is ADMIN information: it must still be recorded and visible on the admin side, but must NOT be
   rendered on the cashier's sheet.

**T3 — Derive the slow-connection state from ACTUAL evidence** already present in the client (the cloud
probe's failure count, request network errors, timeouts). Never guess it, and NEVER blame the connection
when the real cause is a refusal — naming the wrong cause sends everyone looking in the wrong place.

**T4 — Held data: visible, retryable, discardable.** Show the count AND which branch/date/shift/product
set they belong to. Retry attempts the send and reports the OUTCOME in plain language including the
refusal reason. Discard removes them only after an explicit confirmation that names how many and which
sheet, and states the cashier must re-encode. Keep Retry and Discard visually distinct and non-adjacent.

**T5 — Minimise the hold window.** Re-attempt automatically with no cashier action: at minimum on the
next user interaction, on the browser's `online` event, and on the page becoming visible again.

**T6 — Handover.** At shift end the sheet states, per shift, whether every entry is uploaded or N remain
— and when some remain, that the office can see them, so the cashier can go home without waiting on the
network and without doubt about what is recorded.

## HARD CONSTRAINTS (violating any of these fails the task)
- **Do NOT weaken the closed-day guard.** A closed day stays closed to cashiers; only an admin reopens
  it. The refusal must still REFUSE. (The honest refusal message from 531106f8 already exists — do not
  undo it.)
- **Nothing may be destroyed automatically.** No load, sync, login, logout or account change may remove
  or re-scope a held/quarantined entry. Destruction is ONLY ever an explicit, confirmed cashier action.
- **Do NOT restore any destructive "purge" affordance** without the confirmation requirement in T4.
- **No new page, route, tab, wizard or dashboard, and no modal except the discard confirmation.**
- **The per-cell flow must not change**: edit a cell -> it saves -> move on. No extra tap, dialog or
  confirmation on a normal save.
- **Plain language only.** The cashier must never need the words queue, grant, reconcile or enrollment.
- **DEFERRED — do NOT implement:** offline grant renewal / TTL / auto-extend (that is a separate
  offline-sync session).
- **Do NOT touch `public/daily-ledger/assets/`.** The service worker precaches those cache-first from a
  hard-coded cache version and `sw.js` has not changed since Aug 15, so a change there would silently
  never reach any device. Template work only.
- Files: `templates/modules/daily-ledger/cashier/ledger.disyl`, `templates/modules/daily-ledger/layouts/app.disyl`
  (only if the single status element lives in the shell), and `tests/**`.

## METHOD
1. BEFORE changing anything, capture the RED state for each acceptance item against the UNCHANGED tree.
   If a criterion does not fail on the current tree, SAY SO rather than weakening it.
2. Smallest change that satisfies it.
3. Re-run and show GREEN.

## ACCEPTANCE (each RED pre-change; show actual output)
A. A reconcile refused with `reason` `'expired'` produces a VISIBLE, PERSISTENT notice naming the cause
   and the next action, and every held entry is still present afterwards.
B. Causes are not conflated: a forced NETWORK failure states the connection is slow AND that the value
   was not saved; a forced REFUSAL names the refusal and does NOT blame the connection.
C. Held entries are shown with their count and their sheet/date/shift.
D. Discard requires explicit confirmation; cancelling leaves every held entry byte-identical.
E. No automatic destruction: when a sync CANNOT succeed, stored entries are byte-identical across a
   page load and a sync attempt. (NB: a server-CONFIRMED replay may legitimately remove its own entry —
   that is the success path, not damage — so the test must force failure to isolate the property.)
F. Retry reports the outcome in plain language, including the refusal reason, and keeps the entries when
   it cannot succeed.
G. The three states render through ONE status element, and the retry/discard actions are ABSENT unless
   held data exists.
H. The per-cell flow is unchanged: a normal save on a healthy connection involves no new dialog,
   confirmation or tap.
I. No new page/route/tab/wizard is introduced.

## VERIFICATION YOU MUST RUN AND REPORT
- Playwright with `APP_URL=http://baronledger.test` (REQUIRED — without it every spec fails at the login
  selector with a misleading "We could not find that page").
- The daily-ledger browser suite in `tests/browser/modules/daily-ledger/ledger/` (18 specs) with counts.
- Check BOTH `storage/logs/app.log` AND `storage/logs/error.log`.
- Re-run each acceptance item 3x to show it is DETERMINISTIC — a flaky oracle is worse than none.

## REPORT
status (PASS / FAIL / PARTIAL / BLOCKED), files changed, RED/GREEN evidence for A-I, the determinism
runs, suite counts, anything you could NOT verify, and any ambiguity in the directive.
If a criterion cannot be satisfied without violating a constraint, report BLOCKED with the precise
reason — do NOT weaken a criterion, a guard, or the data-preservation behaviour.
PROMPT_EOF
)"

lane_model_run "$LANE_MODEL_CHAIN" "$PROMPT" /tmp/cashier-truth-3-states
rc=$?
echo "lane: cashier-truth-3-states — completed by: ${LANE_MODEL_USED:-<none>} (rc=$rc)"
exit "$rc"
