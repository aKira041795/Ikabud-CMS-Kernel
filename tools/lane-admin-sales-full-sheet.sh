#!/usr/bin/env bash
#
# Lane: admin-sales-full-sheet
#
# OWNER REQUIREMENT (2026-10-05, verbatim): "i agree. as long as admin can see all rows, no problem
# there."
#
# MEASURED BY THE CHAIR:
#   - The CASHIER sheet (dl_fetchCashierLedgerRows) is PRODUCT-DRIVEN:
#       FROM dl_products p INNER JOIN dl_branch_products bp ... LEFT JOIN dl_daily_ledger dl ...
#       ... COALESCE(dl.beg_bal, 0) AS beg_bal
#     so every active product appears, and a missing row displays as 0. 178 rows on Oct 5 PM.
#   - The ADMIN Sales view (dl_reportSalesData) is ROW-DRIVEN:
#       FROM dl_daily_ledger dl JOIN dl_branches b ... JOIN dl_products p ON p.id = dl.product_id
#     so it shows ONLY products that have a ledger row. 88 rows on Oct 5 PM.
#   - The admin view applies NO provisional filter and no other exclusion - its WHERE is branch +
#     ledger_date (+ optional branch/product/shift filters). The 88 is simply how many rows exist.
#   - The gap RECURS BY DESIGN: dl_autoCarryBeginnings refuses a zero source
#     ("A recorded 0 is not a reference to carry from"), so any product that ended a shift at zero
#     has no row for the next shift and is therefore absent from the admin view of it.
#
# So the admin and the cashier see DIFFERENT sheets for the SAME shift, and the admin cannot tell
# "this product had no movement" from "this product was never recorded".

cd /var/www/html/applicationostest || exit 1
source tools/lane-model.sh

LANE_MODEL_CHAIN="deepseek-v4-flash,openai-codex/gpt-5.6-terra,openai-codex/gpt-5.6-sol"

PROMPT="$(cat <<'PROMPT_EOF'
You are making the Daily Ledger ADMIN Sales view show the whole sheet, in the Ikabud repo at
/var/www/html/applicationostest.

## The problem (measured, not theory)
`dl_reportSalesData()` in `modules/daily-ledger/helpers/reporting.php` is ROW-DRIVEN:
    FROM dl_daily_ledger dl
    JOIN dl_branches b ON b.id = dl.branch_id
    JOIN dl_products p ON p.id = dl.product_id
    LEFT JOIN dl_ledger_shift_status ss ...
    WHERE dl.branch_id IN ({$marks}) AND dl.ledger_date BETWEEN ? AND ?

So it lists only products that HAVE a ledger row. Measured on 2026-10-05 PM for branch 8: **88 rows**,
while the cashier sheet (`dl_fetchCashierLedgerRows`, PRODUCT-DRIVEN from `dl_products`) shows **178**
products for the same shift.

The gap recurs by design: `dl_autoCarryBeginnings()` refuses a zero carry source, so a product that
ended the previous shift at zero has no row on the next shift and is absent from the admin view.

Owner's requirement: **the admin must be able to see all rows.**

## Your task
Make the admin Sales view PRODUCT-DRIVEN, the same way the cashier sheet is: every active product on
the branch appears, whether or not a ledger row exists.

## HARD CONSTRAINTS (violating any of these fails the task)
- **Do NOT invent data.** A product with no ledger row has NO recorded values. It must not be given a
  fabricated ending, and it must NOT be counted as official or provisional money. Its contributions
  are zero.
- **Do NOT falsely flag a product as pending.** A product with no record is NOT necessarily a gap -
  it may simply have had no movement (this is the D3 principle already in the codebase:
  `dl_shiftMissingEndings` counts only ACTIVITY-BEARING rows). A "no record" row must be VISIBLY
  DISTINCT from a genuine pending row (a row that exists, bears activity, and has a NULL ending).
  Getting this wrong would replace a blind spot with false noise.
- **Do NOT change the official/provisional TOTALS for any branch or date.** Every existing row must
  still be bucketed and totalled exactly as it is today; the added rows contribute zero. Prove the
  totals are identical before and after for a date with real data.
- **Do NOT weaken the existing row status/label logic** (`dl_rowIsProvisional`,
  `dl_salesRowStatusLabel`). The derived ending stays computed-for-report-only and is never written
  back (`bal_end` stays NULL).
- **Keep the existing optional filters working** (branch, product, shift).
- **MySQL 5.7 safe:** no window functions, no CTEs, no `JSON_TABLE`, no `LIMIT` inside an
  `IN (subquery)`, InnoDB, FK types matched. The query gains a products-driven FROM - check it.
- **Do NOT touch `public/daily-ledger/assets/` or `sw.js`** - the service worker precaches those
  cache-first from a hard-coded cache version, so changes there silently never reach devices.
- Files: `modules/daily-ledger/helpers/reporting.php`, `modules/daily-ledger/handlers.php` (only if
  the caller needs a change), the admin Sales template if a display rule is needed, and `tests/**`.

## METHOD
1. BEFORE changing anything, capture the RED state on the UNCHANGED tree: show that the admin Sales
   view returns 88 rows for a shift where the cashier sheet has 178 products.
2. Make the smallest change that satisfies it.
3. Re-run and show GREEN.

## ACCEPTANCE (RED pre-change; show actual output)
A. The admin Sales view returns ALL active products for the shift (matching the cashier sheet's
   product count), including those with no ledger row. Pre-change: it returns only the 88 rows.
B. A product with **no ledger row** is visibly DISTINCT from a product that has a row with activity
   and a NULL ending (a genuine pending cell). Show both cases rendered.
C. **Totals are unchanged**: for a date/branch with real data, every official and provisional total is
   byte-identical before and after. The extra rows contribute zero.
D. A product with no ledger row contributes NO official and NO provisional money, and does not
   inflate or deflate any unit count.
E. The existing filters (branch / product / shift) still constrain the result correctly.
F. Nothing writes back: `bal_end` for a no-record product is still NULL in the DB after the view is
   rendered (the view is read-only).

## VERIFICATION YOU MUST RUN AND REPORT
- `php -l` on every changed PHP file.
- The daily-ledger PHP suites with pass counts (note: `dispatch_enforcement` and
  `preserve_cashier_variance` fail PRE-EXISTINGLY on notification-count pollution - confirm they fail
  the same way and are not made worse).
- `APP_URL=http://baronledger.test npx playwright test tests/browser/modules/daily-ledger/` and
  report counts, baseline-diffing the failing test NAMES rather than just the totals.
- Check BOTH `storage/logs/app.log` AND `storage/logs/error.log`.
- Re-run each acceptance item 3x to show it is DETERMINISTIC - a flaky oracle is worse than none.
- MySQL 5.7 reasoning for the changed query, stated explicitly.

## REPORT
status (PASS / FAIL / PARTIAL / BLOCKED), files changed, RED/GREEN evidence for A-F, the before/after
row counts and totals, suite counts, determinism runs, MySQL 5.7 reasoning, anything you could NOT
verify, and any ambiguity.
If you cannot add the rows without changing the totals or without falsely flagging no-movement
products, report BLOCKED with the precise reason. Do NOT weaken a criterion to reach PASS.
PROMPT_EOF
)"

lane_model_run "$LANE_MODEL_CHAIN" "$PROMPT" /tmp/admin-sales-full-sheet
rc=$?
echo "lane: admin-sales-full-sheet — completed by: ${LANE_MODEL_USED:-<none>} (rc=$rc)"
exit "$rc"
