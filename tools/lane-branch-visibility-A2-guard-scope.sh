#!/usr/bin/env bash
#
# Lane: branch-visibility-A2-guard-scope
#
# Owner approved OPTION A (2026-10-06) for the removal guard's date scope. Contract:
# .ai/dl-branch-product-visibility.contract.md (see "SCOPE AMENDMENT").
#
# WHY: the shipped guard (commit 2cbbd9bc) blocks on ANY open day <= today. MEASURED on live branch 8 that
# blocks 144 of 182 products, dominated by STALE OPEN DAYS 08-17..08-31 that were never closed and never
# will be. That makes the picker (Slice B) refuse most checkboxes for reasons unrelated to the product.
#
# OPTION A: block ONLY where an operator can still act without an admin reopen —
#   current business date  -> blocks (always)
#   previous business date -> blocks while not closed
#   anything older         -> does NOT block; surfaced as an INFORMATIONAL WARNING instead
#
# This is a SCOPE change only. The row predicate (activity-bearing + missing ending) does NOT change, and the
# cashier vs commissary predicates do NOT change.

cd /var/www/html/applicationostest || exit 1
source tools/lane-model.sh

LANE_MODEL_CHAIN="deepseek-v4-flash,openai-codex/gpt-5.6-terra,openai-codex/gpt-5.6-sol"

PROMPT="$(cat <<'PROMPT_EOF'
You are implementing a SCOPE REFINEMENT (Slice A2) in the Ikabud repo at /var/www/html/applicationostest.
PHP 8.5 local; PRODUCTION IS MYSQL 5.7 and no 5.7 server exists locally — label any 5.7 claim as
INSPECTION-ONLY.

READ FIRST:
  .ai/dl-branch-product-visibility.contract.md   (the "SCOPE AMENDMENT" section is your spec)
  modules/daily-ledger/handlers.php  dl_productUnfinishedEndingBlockers()  (~line 5046)
                                     dl_branchProductUnassignmentBlockers()
                                     dl_productDeactivationBlockers()
                                     dl_setBranchProductActive() / dl_setProductActive()  (audited primitives)
  tests/daily-ledger/daily_ledger_branch_product_visibility_test.php  (the G1-G11 oracle, currently 31/31)

## 1. Narrow the blocking date scope (the actual change)
A blocker date now qualifies ONLY if:
    ledger_date = :businessDate                                  (the current business date)
    OR (ledger_date = :previousBusinessDate AND day status is not 'closed')
Anything older must NOT produce a blocker. Keep the existing row predicates EXACTLY as they are:
    cashier:    bal_end IS NULL AND (beg_bal <> 0 OR addtl <> 0 OR withdraw <> 0)
    commissary: actual_end_qty IS NULL AND (beg_qty <> 0 OR produced_qty <> 0 OR dispatched_qty <> 0 OR wastage_qty <> 0)
Do NOT relax the predicate, only the date window. A missing day-status row counts as not-closed (open).
Use `dl_businessDate()` for today; derive the previous business date the same way the module already does
elsewhere (do not invent a new notion of "business day").

## 2. Add the informational warning (so Option A does not hide the problem)
New read-only function, e.g.
    dl_productOlderOpenDayWarnings($db, int $productId, ?int $branchId = null): array
Returning the OLDER-than-yesterday dates+shifts that still carry an activity-bearing, missing-ending row on a
NOT-closed day, across both ledgers, with the same shape as the blockers (branch_id, ledger, date, shift,
product_id) plus a human-readable summary. This is INFORMATION, not a refusal: it must never be used to block.
Expose it to the picker/bulk endpoint so Slice B can render it — but do NOT build any UI here.

## 3. Keep the existing guards and primitives working
dl_setBranchProductActive() / dl_setProductActive() must now REFUSE based on the narrowed scope, and their
audit rows must record the refusal plus the (narrowed) blockers. Keep the existing audit action names.
Bulk all-or-nothing semantics are unchanged.

## 4. Oracle — update the EXISTING G1-G11 cases, and add the new ones
The existing oracle asserts the OLD scope; update those assertions deliberately and say which you changed.
Required final behaviour:
  N1. an activity-bearing, ending-less row on the CURRENT business date -> BLOCKS (refused).
  N2. the same row shape on the PREVIOUS business date while that day is NOT closed -> BLOCKS.
  N3. the same row shape on the PREVIOUS business date while that day IS closed -> does NOT block.
  N4. the same row shape on an OLD open day (e.g. 3+ days back, never closed) -> does NOT block, AND the
      warning function REPORTS it (prove both halves: not blocked, still visible).
  N5. the warning function returns [] when there is nothing older.
  N6. G2/G3/G4 still hold: closed day, zero-activity row, completed row -> never block.
  N7. G1-G11 from the existing oracle still pass, with the date-scope assertions updated.
State explicitly which cases FAIL on the base tree (commit 2cbbd9bc) — N4 must, because the base blocks
where the new tree permits. Do not claim discrimination you did not observe.

## HARD CONSTRAINTS
- SCOPE change only. Do NOT touch the row predicates, the write-side validation, apiCreateBranch(), the sheet
  list queries, handleAdminSales, dl_reportSalesData, the carry, or the close-order path (67255d4b).
- No UI. No picker. No endpoint that an admin can reach. That is Slice B.
- Do NOT touch public/daily-ledger/assets/ or sw.js.
- Do NOT change the migration or assignment_mode.
- MySQL 5.7-safe: no CTE, no window function, no JSON_TABLE, no LIMIT inside IN (subquery).
- Keep storage/logs/error.log empty.

## ACCEPTANCE — run and report verbatim
    php tests/daily-ledger/daily_ledger_branch_product_visibility_test.php
    php tests/daily-ledger/daily_ledger_close_day_and_notify_test.php
    php tests/daily-ledger/daily_ledger_admin_sales_full_sheet_test.php
    php -l modules/daily-ledger/handlers.php
    git --no-pager diff --stat
    php /tmp/chair-verify-slice-a.php
Then the suite: php scripts/run-tests.php --dir=tests/daily-ledger, reporting pass counts and separating
PRE-EXISTING failures (known notification/log pollution: daily_ledger_dispatch_enforcement_test,
daily_ledger_preserve_cashier_variance_test, and the flaky daily_ledger_delivery_record_authz_test) from new
ones. Never reach green by weakening an assertion.

## MEASURED EXPECTATION to confirm (chair, live branch 8)
Before: guard blocks 144 of 182 products. After Option A it must block ~0, and /tmp/chair-verify-slice-a.php
section 3 must show the narrowed count. Report the number you observe. Note: the chair's probe uses the OLD
un-narrowed aggregate, so it will still print 144 as "mine" while the shipped guard prints the new number —
that DISAGREEMENT IS EXPECTED for this slice and is not a defect; report both numbers plainly, and mention
that the probe needs its aggregate narrowed to match (do not edit /tmp files).

## Rules
- Smallest correct change. No refactor, no new dependency.
- Never weaken, skip, or delete an assertion to reach green.
- If narrowing the scope would let a genuinely in-flight row be stranded, STOP and report BLOCKED with the
  exact case rather than widening silently.
- Report status PASS | PARTIAL | BLOCKED, files changed, tests changed (with why), and measured evidence.
PROMPT_EOF
)"

lane_model_run "$LANE_MODEL_CHAIN" "$PROMPT" /tmp/branch-visibility-A2-guard-scope
rc=$?
echo "lane: branch-visibility-A2-guard-scope — completed by: ${LANE_MODEL_USED:-<none>} (rc=$rc)"
exit "$rc"
