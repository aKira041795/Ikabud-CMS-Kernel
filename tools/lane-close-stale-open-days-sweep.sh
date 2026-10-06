#!/usr/bin/env bash
#
# Lane: close-stale-open-days-sweep
#
# THE OPEN ISSUE (chair, measured 2026-10-06): `dl_maybeAutoCloseBranchDay()` targets exactly one date —
# `$currentBusinessDate - 1 day` (handlers.php:1569). A day that is missed on the single day it is "yesterday"
# is NEVER revisited, so it stays open forever. Measured on branch 8: 15 stale open days from 08-17..08-31,
# which fed 144 products into the guard's "older unfinished days" warning list and made the guard look broken
# before Option A narrowed it.
#
# WHY THIS FIX AND NOT ANOTHER — it reuses what is already working and effective:
#   * `dl_maybeAutoCloseBranchDay()` is the SINGLE choke point: ~10 call sites plus `dl_maybeAutoCloseBranches()`.
#     Fixing there fixes every entry point at once, with no new trigger machinery.
#   * Its close semantics are already proven and must be reused verbatim: `dl_lockDayStatusRow()` row lock,
#     `dl_isFullyManualDay()` + PM checks, the close-and-notify policy approved today (67255d4b), variance
#     recompute/freeze ONLY for a finalized day, the audit row, and the transaction handling.
#   * THE `reopened_at` EXEMPTION IS THE KEY PROPERTY. A day an admin deliberately reopened must stay open
#     (handlers.php:1594-1604). Measured on live data, the days the encoder is ACTIVELY backfilling
#     (2026-10-03 and 2026-10-04) both carry `reopened_at` from 2026-10-05 03:0x. So a backward sweep using the
#     EXISTING exemption protects live work automatically and closes only genuinely abandoned days. No new
#     concept, no new column, no new flag.
#
# THE FIX: a BOUNDED BACKWARD SWEEP inside that one function.

cd /var/www/html/applicationostest || exit 1
source tools/lane-model.sh

LANE_MODEL_CHAIN="deepseek-v4-flash,openai-codex/gpt-5.6-terra,openai-codex/gpt-5.6-sol"

PROMPT="$(cat <<'PROMPT_EOF'
You are fixing a specifically-scoped defect in the Ikabud repo at /var/www/html/applicationostest.
PHP 8.5 local; production is MySQL 5.7 and no 5.7 server exists locally — label any 5.7 claim INSPECTION-ONLY.

READ FIRST:
  modules/daily-ledger/handlers.php  dl_maybeAutoCloseBranchDay()   ~line 1554  <-- the one function to change
                                     dl_maybeAutoCloseBranches()    ~line 1700
                                     its callers (grep for it: ~10 sites)
                                     dl_getDayStatus(), dl_lockDayStatusRow(), dl_isFullyManualDay(),
                                     dl_shiftIsFinalized(), dl_raiseIntegrityNotification(), dl_auditLog()
  .ai/dl-branch-product-visibility.contract.md   (the Option A guard + the current close policy context)

## STEP 1 — MEASURE FIRST, BEFORE CHANGING ANYTHING
Write a read-only probe and REPORT ITS OUTPUT in your report. Fleet-wide (all active branches):
  - how many days are OPEN (explicit `dl_ledger_day_status` row with status 'open') and older than yesterday;
  - of those, how many carry `reopened_at` (these MUST NOT be closed — they are deliberate admin work);
  - how many have ledger activity but NO day-status row at all;
  - how far back the oldest open day goes, per branch.
This sizes the change and proves the sweep's effect before it exists. Do not skip it.

## STEP 2 — THE SWEEP (the only behaviour change)
`dl_maybeAutoCloseBranchDay()` keeps doing exactly what it does today for yesterday, then walks BACKWARD one
day at a time and applies the SAME close treatment to each older day that qualifies, until it stops.

A day qualifies for the sweep only if it is NOT already closed AND it is a day that was really in use:
  - it has a `dl_ledger_day_status` row with status 'open', OR
  - it has ledger activity but no 'closed' status row.
NEVER fabricate a day-status row for a day that has neither (an idle day that was never started must not
gain a 'closed' row). This is the one way this change could corrupt data — do not get it wrong.

MUST PRESERVE, exactly as the current code does (reuse the existing code path, do not re-implement it):
  - the `reopened_at` exemption: a day that was deliberately reopened STAYS OPEN and is skipped. IMPORTANT:
    skip it and CONTINUE backward — do not stop there, or one reopened day would block the whole sweep.
  - the row lock (`dl_lockDayStatusRow`) per day;
  - `dl_isFullyManualDay()` + PM-finalized rules, including the approved close-and-notify policy;
  - variance recompute/freeze ONLY for a finalized day;
  - the audit row per closed day;
  - `auto_close_enabled` and the operating timezone / close-of-day settings.

BOUNDS (both required):
  - a MAXIMUM number of older days closed per pass (pick a defensible small cap and justify it in a comment);
  - the loop stops at the first day that is closed or does not qualify, so in steady state (nothing stale)
    the extra cost is ONE cheap query per call. Prove that steady-state claim.

NOTIFICATION NOISE: for YESTERDAY keep the existing per-day notification untouched. For SWEPT OLDER days do
NOT emit one notification per day — a 15-day backlog would flood the admin. Emit ONE summary notification per
branch per pass, naming the number of older days closed and the oldest/newest dates, through the existing
`dl_raiseIntegrityNotification()` channel and aggregate-key style. Say what you chose and why.

## STEP 3 — oracle
Extend the existing suite (tests/daily-ledger/, TestHarness pattern, private fixture branches — never branch 8):
  S1. an older open day IS closed by the sweep; yesterday's behaviour is unchanged.
  S2. a day with `reopened_at` set is NOT closed, and the sweep CONTINUES past it to close older qualifying days.
  S3. the sweep STOPS at a closed day.
  S4. an idle day (no status row, no ledger activity) gains NO day-status row.
  S5. the cap is respected: with more stale days than the cap, only the cap's worth close on one pass and the
      remainder close on a later pass.
  S6. a day whose PM was never finalized is closed WITH the approved notify-once behaviour, and its variance
      flags are NOT frozen (finalized-only rule).
  S7. exactly ONE summary notification for swept days, not one per day.
  S8. steady state: with nothing stale, the call closes nothing and issues no notification.
State which cases FAIL on the base tree (commit 39cc6445). Do not claim discrimination you did not observe.

## HARD CONSTRAINTS
- Change ONLY the auto-close path. Do NOT touch the removal guard or its Option A window, the picker, the
  write-side validation, the two sheet list queries, handleAdminSales, dl_reportSalesData, the carry, or
  migration 077.
- Do NOT change the meaning of `reopened_at`, and do NOT clear it anywhere.
- MySQL 5.7-safe: no CTE, no window function, no JSON_TABLE, no LIMIT inside IN (subquery).
- Keep storage/logs/error.log empty.
- If the sweep cannot be bounded safely without an architectural change, STOP and report BLOCKED with the reason.

## ACCEPTANCE — run and report verbatim
    php tests/daily-ledger/daily_ledger_close_day_and_notify_test.php
    php tests/daily-ledger/daily_ledger_branch_product_visibility_test.php
    php tests/daily-ledger/daily_ledger_branch_product_picker_test.php
    php tests/daily-ledger/daily_ledger_admin_sales_full_sheet_test.php
    php tests/daily-ledger/<your new/extended test>.php
    php -l modules/daily-ledger/handlers.php
    git --no-pager diff --stat
    php /tmp/chair-verify-slice-a2.php
Then php scripts/run-tests.php --dir=tests/daily-ledger, separating PRE-EXISTING failures (known log/
notification pollution: daily_ledger_dispatch_enforcement_test, daily_ledger_preserve_cashier_variance_test,
flaky daily_ledger_delivery_record_authz_test) from new ones.
Finally re-run YOUR Step 1 probe and report the delta, and time a single sweep pass so its cost is known.

Note: `dl_maybeAutoCloseBranchDay()` runs on page loads, so closing stale days will also SHRINK the picker's
"older unfinished days" warning list (a closed day drops out of it). Mention that as the expected side effect.

## Rules
- Smallest correct change. No refactor, no new dependency.
- Never weaken, skip, or delete an assertion to reach green.
- Report status PASS | PARTIAL | BLOCKED; files changed; the Step 1 measurements BEFORE and AFTER; the cap you
  chose and why; tests added/changed; anything you could not verify.
PROMPT_EOF
)"

lane_model_run "$LANE_MODEL_CHAIN" "$PROMPT" /tmp/close-stale-open-days-sweep
rc=$?
echo "lane: close-stale-open-days-sweep — completed by: ${LANE_MODEL_USED:-<none>} (rc=$rc)"
exit "$rc"
