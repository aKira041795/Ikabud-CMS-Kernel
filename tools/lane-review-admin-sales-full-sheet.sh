#!/usr/bin/env bash
#
# Lane: review-admin-sales-full-sheet
#
# OWNER REQUEST (2026-10-05, verbatim): "i think it's okay, but you can ask Sol to check, just to be
# sure."
#
# This is a REVIEW lane. Its job is to try to FALSIFY the change, not to confirm it. Sol is the
# architect/review role; the chair has already read the diff and will weigh this opinion.
#
# The change under review (lane `admin-sales-full-sheet`) makes `dl_reportSalesData()` in
# modules/daily-ledger/helpers/reporting.php PRODUCT-DRIVEN so the admin Sales view shows every active
# product, matching the cashier sheet, instead of only the products that have a ledger row.
#
# Measured before the change: 88 ledger rows vs 178 active products on 2026-10-05 PM, branch 8.

cd /var/www/html/applicationostest || exit 1
source tools/lane-model.sh

# Sol first (the owner asked for it explicitly); terra is the strong fallback because Sol has crashed
# at least once in this session with a 75-byte log and exit=1.
LANE_MODEL_CHAIN="openai-codex/gpt-5.6-sol,openai-codex/gpt-5.6-terra,deepseek-v4-flash"

PROMPT="$(cat <<'PROMPT_EOF'
You are REVIEWING a change to an accounting-facing view in the Ikabud repo at
/var/www/html/applicationostest. Your job is to try to FALSIFY it, not to agree with it.

## The change under review
`dl_reportSalesData()` in `modules/daily-ledger/helpers/reporting.php` was ROW-DRIVEN:

    FROM dl_daily_ledger dl
    JOIN dl_branches b ON b.id = dl.branch_id
    JOIN dl_products p ON p.id = dl.product_id
    LEFT JOIN dl_ledger_shift_status ss ...
    WHERE dl.branch_id IN ({$marks}) AND dl.ledger_date BETWEEN ? AND ?

It is being made PRODUCT-DRIVEN (like `dl_fetchCashierLedgerRows`) so the admin Sales view shows every
active product for each shift, not just the products that have a ledger row.

Why: measured on 2026-10-05 PM branch 8, the admin view returned **88 rows** while the cashier sheet
showed **178 products** for the same shift. The gap recurs BY DESIGN, because
`dl_autoCarryBeginnings()` refuses a zero carry source, so any product that ended a shift at zero has
no row on the next shift.

Owner's requirement: "as long as admin can see all rows, no problem there."

## Run the diff first
    git --no-pager diff -- modules/daily-ledger/helpers/reporting.php modules/daily-ledger/handlers.php
    git status --porcelain
    git --no-pager log --oneline -3

## What to attack, in priority order

1. **Other consumers of the result.** Find EVERY caller of `dl_reportSalesData()` and every consumer
   of the rows it returns (admin Sales view, exports, PDF/Excel, the governed report, dashboards,
   variance recompute, anything that paginates or counts). Does adding ~90 zero-valued rows per shift
   change an EXPORT, a file, a row count, a total, or a downstream assertion? This is the biggest risk
   in the change and the one most likely to have been missed.

2. **Performance.** The query takes a DATE RANGE (`BETWEEN ? AND ?`), so a month-long range multiplies
   products x shifts x days. With the added LEFT JOINs (am / prev_pm / prev_am), what is the new cost
   versus the old? Is there an index that still applies? Would a realistic range become a problem on
   shared hosting?

3. **Totals must be unchanged.** Verify BY MEASUREMENT, not by reading: pick a date/branch with real
   data and show the official and provisional unit and money totals are identical before and after.
   If they moved by even one unit, that is a FAIL.

4. **No false pending.** A product with no ledger row must NOT be reported as a genuine pending cell.
   The codebase already establishes (D3, `dl_shiftMissingEndings`) that only ACTIVITY-BEARING rows
   count as gaps. Show both cases rendered and confirm they are visibly distinct. A blind spot
   replaced by false noise is worse than the blind spot.

5. **Filters.** The query supports optional branch / product / shift filters. With a products-driven
   FROM, do all three still constrain correctly? Try to construct a filter combination that now
   returns something wrong or ambiguous.

6. **Read-only.** Confirm the view writes nothing back: `bal_end` for a no-record product is still NULL
   in the DB after rendering, and the report-only derived ending is still never persisted.

7. **MySQL 5.7.** The changed query must stay 5.7-safe: no window functions, no CTEs, no JSON_TABLE,
   no `LIMIT` inside an `IN (subquery)`. There is no 5.7 server locally (this one is 8.0.46), so this
   is an inspection check - say so.

8. **Anything the brief did not think about.** That is the most valuable thing you can return.

## How to report
Return a verdict:
    PASS                   - the change is sound as-is
    CHANGES_REQUIRED       - with PRECISE, actionable findings, each with file:line and a reason
    BLOCKED                - if you cannot assess it without something you lack

Then, for each finding: what is wrong, how it manifests, how you would verify it, and the smallest
correct fix. Distinguish clearly between (a) a defect you MEASURED, (b) a risk you REASONED about, and
(c) something you could not check. Do not pad. If the change is sound, say so plainly and stop.

Do NOT modify any file. This is a read-only review. Do not weaken anything to reach agreement.
PROMPT_EOF
)"

lane_model_run "$LANE_MODEL_CHAIN" "$PROMPT" /tmp/review-admin-sales-full-sheet
rc=$?
echo "lane: review-admin-sales-full-sheet — completed by: ${LANE_MODEL_USED:-<none>} (rc=$rc)"
exit "$rc"
