#!/usr/bin/env bash
#
# Lane: close-day-and-notify
#
# OWNER DIRECTIVE (2026-10-05, verbatim): "it can close and notify. better design than affect the
# next day."
#
# This REVERSES part of contract 2026-10-05 D1, shipped today in 531106f8. That change made
# dl_maybeAutoCloseBranchDay() REFUSE to close a fully-manual day whose PM shift is still open:
# it flagged the gap, notified the admin, and returned false, so the day stayed open. The owner has
# now decided the day SHOULD close (a day left open propagates into the next day), with the
# notification KEPT so the admin knows to investigate and reopen.
#
# The owner's own framing, for context:
#   - "there's a reason why a shift must be closed. it's a guard so admin knows and can ask a
#      cashier or production user the reason they did not finish encoding"
#   - "if a shift is not closed, it compounds the effect the next day"
#   - the encoder then works backwards from the paper report to complete the pending endings
#
# MEASURED SUPPORT for the directive (chair, live branch 8, tenant 207, 2026-10-05):
#   2026-10-01 is ALREADY in the "day closed, PM shift still open" state: day status=closed
#   (closed 15:09:04) with PM status=open, and all 174 PM endings present. So the state the owner is
#   choosing already occurs in production and is survivable.
#
# WHAT MUST NOT REGRESS — the actual defect this whole effort fixed:
#   The October incident was NOT that the day closed. It was that the day closed while endings were
#   missing, NOBODY was told, and the cashier was locked out with a silent failure. The notification
#   (D4) is what was missing. It MUST survive this change. A close that is silent is the bug.

cd /var/www/html/applicationostest || exit 1
source tools/lane-model.sh

LANE_MODEL_CHAIN="deepseek-v4-flash,openai-codex/gpt-5.6-terra,openai-codex/gpt-5.6-sol"

PROMPT="$(cat <<'PROMPT_EOF'
You are IMPLEMENTING an owner-directed reversal of behaviour in the Ikabud repo at
/var/www/html/applicationostest. Read the current code first:

    git --no-pager log --oneline -6
    sed -n '1554,1710p' modules/daily-ledger/handlers.php

## The change
`dl_maybeAutoCloseBranchDay()` currently REFUSES to close a fully-manual day whose PM shift is not
finalized: when `dl_isFullyManualDay()` is true and the PM shift status is not 'finalized'
(currently around handlers.php:1621-1668), it sets `pending_notified_at`, writes ONE audit row with
status 'closed_without_pm_finalize', raises `dl_raiseIntegrityNotification(...)`, commits, and
`return false` — so the day never closes.

The owner has decided the day SHOULD close in that situation, and the notification must remain.

## Required behaviour
When a fully-manual day reaches the close-of-day cutoff with its PM shift still open:
1. The day MUST still be CLOSED — fall through to the existing close INSERT
   (`INSERT INTO dl_ledger_day_status ... status = 'closed' ...`) at the end of the function. The
   day must NOT be left open, and must NOT be left half-closed.
2. The admin notification MUST still be raised, exactly once per day (not once per request — this
   path runs on every page load). Keep the existing aggregate key shape
   `closed_without_pm_finalize-day-<branch>-<date>`.
3. The single audit row MUST still be written, status 'closed_without_pm_finalize'. It stays
   accurate: the day closed without its PM shift finalized.
4. `pending_notified_at` MUST still be stamped.
5. The variance recompute + variance-flag freeze (`dl_recomputeVariancesForDay` /
   `dl_freezeVarianceFlags`) MUST NOT run for a day whose PM shift was not finalized. Those belong
   to a properly-finalized day; do not run them on this path.

## Copy
The notification text currently says "The day was NOT closed so pending endings can still be
completed." That is now false. Rewrite it to state the truth plainly: the day WAS closed while its
PM shift was still open, so pending endings still need completing, and an admin must REOPEN the day
to complete them. Keep the existing title style. Do not overstate: say what happened and what the
admin must do.

Leave the cashier-facing refusal message (`dl_closedDayRefusalMessage`) and
`dl_cashierMayEdit()` logic UNCHANGED. Locking the cashier out of a closed day IS the intended
guard; the admin reopen path is the remedy. Do not add a cashier exemption.

## Explicit consequences to state in your report (do NOT try to "fix" these)
- After this change, a closed day with an unfinalized PM shift can only be completed by an admin
  reopening the day. The cashier loses the self-service late-count window that D1 provided. That is
  the deliberate trade the owner accepted.
- Because `dl_maybeAutoCloseBranchDay()` does not re-close a day that an admin reopened
  (`reopened_at` is set), the flow is: auto-close + notify -> admin reopens -> encoder completes
  the pending endings -> PM finalizes -> admin closes manually.

## Tests — UPDATE, do not weaken
Existing tests were written for the REFUSAL behaviour and will now fail. The KNOWN affected oracle
is `tests/daily-ledger/daily_ledger_close_order_recovery_test.php` (the D1 oracle written today);
find any others yourself. Update them to specify the NEW behaviour deliberately and say so in your
report. Do NOT delete an assertion to reach green, and do NOT relax an unrelated one. Specifically:
  - Any test asserting the day STAYS OPEN with an unfinalized PM shift must now assert the day
    becomes 'closed' AND the notification exists.
  - Keep asserting the notification fires exactly once per day across repeated passes.
  - Keep asserting `pending_notified_at` is stamped.
  - Keep asserting no variance freeze ran for the unfinalized day.
Name the tests you changed and why, in one line each.

## Oracle — add these, and state which FAIL on the base tree
On a private fixture branch (the daily-ledger suite uses 99230/99231 with cleanup — follow that
pattern; do not touch real branch 8):
  N1. fully-manual day, PM shift open, past cutoff -> day status becomes 'closed'.
      (FAILS on base: the base refuses and leaves it open. This is the discriminating case.)
  N2. the same -> a `closed_without_pm_finalize` notification exists for that branch+date.
  N3. the same -> exactly one audit row and one notification across TWO consecutive passes.
  N4. the same -> `pending_notified_at` is stamped.
  N5. after the auto-close, an ADMIN REOPEN still works and the cashier can then edit and finalize
      the PM shift (prove the remedy path end to end — this is what makes the trade acceptable).
  N6. a fully-manual day whose PM shift IS finalized still closes, still recomputes variances, and
      still freezes variance flags (do not regress the normal path).
  N7. `dl_cashierMayEdit()` behaviour on a closed day is UNCHANGED.

## Acceptance
Report the exact output of:
    php tests/daily-ledger/daily_ledger_admin_sales_full_sheet_test.php
    php -l modules/daily-ledger/handlers.php
    git --no-pager diff --stat
Then run the daily-ledger PHP suites and report the pass counts, distinguishing any PRE-EXISTING
failures (the known log/notification-pollution ones) from new ones. Do not claim green by skipping.
Then re-run the chair's live probes and report them verbatim:
    php /tmp/chair-verify-guard-live.php
Check BOTH logs: storage/logs/app.log and storage/logs/error.log (error.log must be 0 bytes).

## Rules
- Smallest correct change. No refactor, no new dependency, no scope creep.
- Do NOT touch the admin Sales view, `handleAdminSales`, or anything shipped in 7eda56e3/9475ab17.
- Never weaken, skip, or delete an assertion to reach green.
- If closing the day here would strand data in a way you cannot satisfy without an architectural
  change, STOP and report BLOCKED with the reason rather than improvising.
- Report status PASS | PARTIAL | BLOCKED, files changed, tests changed, and measured evidence.
PROMPT_EOF
)"

lane_model_run "$LANE_MODEL_CHAIN" "$PROMPT" /tmp/close-day-and-notify
rc=$?
echo "lane: close-day-and-notify — completed by: ${LANE_MODEL_USED:-<none>} (rc=$rc)"
exit "$rc"
