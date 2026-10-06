#!/usr/bin/env bash
#
# Lane: stale-day-sweep-set-selection
#
# SUPERSEDES the walk-based sweep (HARPP decision #145, options A/B/C).
#
# MEASURED DEFECT IN THE SHIPPED SWEEP (chair, tenant 207, business date 2026-10-06):
#   OPEN older than yesterday: 29 (all branch 8; 16 carry reopened_at), sweepable 13, oldest 2026-08-15.
#   Live delta = ZERO. Branch 8's sequence is:
#       10-05 closed (yesterday) -> 10-04 open+reopened (skip, continue) -> 10-03 open+reopened (skip,
#       continue) -> 10-02 CLOSED = WALL, stop.
#   The abandoned days 08-15..09-02 sit behind further closed walls, so the walk never reaches them and the
#   picker's "144 older unfinished days" note never shrinks. The sweep's whole purpose was to clear that
#   backlog, so stop-at-closed achieved the opposite of its goal.
#
# WHY NOT OPTION A/B/C: all three keep the backward WALK and argue about its stop condition, which is why the
# tradeoff looks unavoidable (A = backlog stays; B = ~40 probes per page load; C = ~31 probes per pass).
# The walk is the wrong mechanism. Selecting the qualifying days DIRECTLY has no walls, no skip-and-continue,
# and a constant query cost — so the tradeoff dissolves.
#
# THE FIX: replace the walk with a bounded SET SELECTION of stale open days, then close them with the SAME
# per-day path (already extracted as dl_autoCloseBranchDayAt(...) by the previous lane — reuse it verbatim).
#
#   candidates (ONE indexed query, no walk, no lookback floor needed):
#     SELECT ledger_date FROM dl_ledger_day_status
#      WHERE branch_id = :bid AND status = 'open' AND ledger_date < :yesterday
#        AND reopened_at IS NULL
#      ORDER BY ledger_date ASC      -- OLDEST FIRST, so a capped remainder drains on the next pass
#      LIMIT :cap
#
# Behaviour this preserves by construction:
#   * NO CLOSED WALLS — a closed day is simply not a candidate; it can neither block nor be re-closed.
#   * reopened_at days are EXCLUDED BY THE QUERY, so the encoder's live work (10-03/10-04) is protected with
#     no skip-and-continue logic at all.
#   * NO FABRICATED ROWS — only days that already have an explicit `status='open'` row can be closed.
#   * steady state = ONE query, ZERO writes, when nothing is stale.
#   * cost is constant regardless of how far back the backlog goes.
#
# ALSO IN THIS LANE — REMOVE THE SUMMARY NOTIFICATION (owner principle, 2026-10-06):
# "nothing wrong with special cases, as long as the UI is kept simple and background processes are not emitted
# for user to decide." Swept-day housekeeping is internal; "N older days were closed" asks the user to decide
# nothing. So: swept days are closed QUIETLY and AUDITED. The per-day notification stays ONLY for yesterday
# (the actionable "an admin must reopen" case the owner explicitly approved).
# This makes oracle case S7 an INTENTIONAL SPEC CHANGE: it currently asserts "exactly one summary notification"
# and must become "NO notification raised, audit row present". That is a deliberate spec change, NOT a
# weakening — state it plainly in your report and do not touch any unrelated assertion.

cd /var/www/html/applicationostest || exit 1
source tools/lane-model.sh

LANE_MODEL_CHAIN="deepseek-v4-flash,openai-codex/gpt-5.6-terra,openai-codex/gpt-5.6-sol"

PROMPT="$(cat <<'PROMPT_EOF'
You are replacing the mechanism of a just-shipped sweep in the Ikabud repo at /var/www/html/applicationostest.
PHP 8.5 local; production is MySQL 5.7 and no 5.7 server exists locally — label any 5.7 claim INSPECTION-ONLY.

READ FIRST:
  git --no-pager show --stat HEAD
  modules/daily-ledger/handlers.php   dl_maybeAutoCloseBranchDay()  (~1554)  <-- the only function to change
                                      dl_autoCloseBranchDayAt(...)  <-- the per-day body the previous lane
                                                                       extracted; REUSE IT VERBATIM
  tests/daily-ledger/daily_ledger_stale_day_sweep_test.php   (S1-S8, currently 15/15)

## CHANGE 1 — replace the backward WALK with a bounded SET SELECTION
Delete the walk (and with it the stop-at-closed rule and the skip-and-continue handling). Instead select the
candidates directly, then close them with `dl_autoCloseBranchDayAt(...)` exactly as the walk did:

    SELECT ledger_date FROM dl_ledger_day_status
     WHERE branch_id = :bid AND status = 'open' AND ledger_date < :yesterday AND reopened_at IS NULL
     ORDER BY ledger_date ASC
     LIMIT :cap

  - Keep the cap of 7 closes per pass. OLDEST FIRST is required: a capped remainder must drain on the next
    pass, which newest-first cannot do.
  - `reopened_at IS NULL` in the query IS the protection for the encoder's live work. No skip logic in PHP.
  - Do NOT scan the ledger and do NOT close a day that has no explicit `status='open'` row: only days that
    already have that row may be closed, so no row is ever fabricated. (A day that has ledger activity but no
    status row AT ALL is deliberately OUT of scope — state it as a known limitation; it is 1 day on live data.)
  - Yesterday's behaviour is UNCHANGED, including its per-day notification.
  - Keep every other property: `dl_lockDayStatusRow` per day, `dl_isFullyManualDay` + PM rules, variance
    recompute/freeze ONLY for a finalized day, the per-day audit row, `pending_notified_at`, own-transaction
    handling, `auto_close_enabled`, the operating timezone and close-of-day settings.
  - MySQL 5.7-safe: no CTE, no window function, no JSON_TABLE, no LIMIT inside IN (subquery).

## CHANGE 2 — REMOVE the summary notification
Swept older days are now closed QUIETLY: keep the per-day AUDIT row and `pending_notified_at`, but raise NO
notification for them. Remove the `auto_close_sweep-...` raise entirely. Do not delete the
`dl_raiseIntegrityNotification` helper or affect yesterday's per-day notification.

## ORACLE — update deliberately
Existing S1-S8 must pass. Specifically:
  - S7 changes meaning: it must now assert NO notification was raised for swept days AND that the per-day audit
    row exists. Say plainly in your report that this is an intentional spec change driven by the owner's
    principle, not a weakening.
  - S3 (stops at a closed day) is OBSOLETE — a closed day is simply not a candidate. Replace it with the case
    that proves the NEW mechanism is the point of this lane:
      S3-NEW (**the discriminating case**): a CLOSED day sits between yesterday and a stale OPEN day further
      back. The stale open day MUST still be selected and closed. On the base tree (HEAD) this fails, because
      the walk stops at the closed wall — that is exactly the live defect this lane fixes.
  - Keep S2 (a reopened day is not closed) — but note it is now enforced by the query's `reopened_at IS NULL`
    rather than by skip-and-continue, and a reopened day must NOT block days older than it.
  - Keep S4 (no fabricated row), S5 (cap respected, remainder drains on a later pass), S6 (unfinalized PM
    closes, `pending_notified_at` stamped, variance NOT frozen), S8 (steady state: exactly 1 query, 0 writes).
Report the base-tree pass/fail per case and state which cases FAIL on base.

## MEASURE THE LIVE DELTA — this lane's whole justification
Before and after, on tenant 207 (read-only probes), report:
  - count of OPEN days older than yesterday, per branch, fleet-wide;
  - of those, how many carry `reopened_at` (MUST remain open — they are the encoder's live work);
  - the oldest remaining open day.
EXPECTED after the fix: the 13 sweepable branch-8 days begin draining 7 per pass, the 16 reopened days stay
open, and the picker's "older unfinished days" warning list shrinks as days close. If the delta is still 0,
STOP and report BLOCKED with the reason — do not "fix" it by widening the selector.

## HARD CONSTRAINTS
- Change ONLY the auto-close path. Do NOT touch the removal guard or its Option A window, the picker, write
  validation, the sheet list queries, handleAdminSales, dl_reportSalesData, the carry, or migration 077.
- Do NOT clear or reinterpret `reopened_at` anywhere.
- No UI change of any kind.
- Keep storage/logs/error.log empty.
- If the selection cannot be bounded safely, STOP and report BLOCKED rather than improvising.

## ACCEPTANCE — run and report verbatim
    php tests/daily-ledger/daily_ledger_stale_day_sweep_test.php
    php tests/daily-ledger/daily_ledger_close_day_and_notify_test.php
    php tests/daily-ledger/daily_ledger_branch_product_visibility_test.php
    php tests/daily-ledger/daily_ledger_branch_product_picker_test.php
    php tests/daily-ledger/daily_ledger_admin_sales_full_sheet_test.php
    php -l modules/daily-ledger/handlers.php
    git --no-pager diff --stat
Then php scripts/run-tests.php --dir=tests/daily-ledger, separating PRE-EXISTING failures (known log/
notification pollution: daily_ledger_dispatch_enforcement_test, daily_ledger_preserve_cashier_variance_test,
flaky daily_ledger_delivery_record_authz_test) from new ones.
Then time ONE sweep pass so the page-load cost is known, and confirm steady state is 1 query / 0 writes.

## Rules
- Smallest correct change: this is a mechanism swap, not a refactor.
- Never weaken, skip, or delete an assertion to reach green (S7's change is an authorised spec change — explain
  it, do not hide it).
- Report status PASS | PARTIAL | BLOCKED; files changed; before/after live measurements; per-case base-tree
  discrimination; timing; anything you could not verify.
PROMPT_EOF
)"

lane_model_run "$LANE_MODEL_CHAIN" "$PROMPT" /tmp/stale-day-sweep-set-selection
rc=$?
echo "lane: stale-day-sweep-set-selection — completed by: ${LANE_MODEL_USED:-<none>} (rc=$rc)"
exit "$rc"
