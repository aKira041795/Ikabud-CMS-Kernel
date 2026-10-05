#!/usr/bin/env bash
#
# Lane: review-admin-sales-full-sheet
#
# OWNER REQUEST (2026-10-05, verbatim): "i think it's okay, but you can ask Sol
# to check, just to be sure."
#
# This is a REVIEW lane. Its job is to try to FALSIFY the change, not to confirm it.
# Sol is the architect/review role; the chair has already verified and will weigh this opinion.
#
# REWRITTEN 2026-10-05 before dispatch. The first draft of this brief described a design the
# lane did NOT build: it asserted that `dl_reportSalesData()` had been made product-driven,
# and listed "other consumers of dl_reportSalesData()" as the top risk. The lane instead made
# the ADMIN VIEW product-driven (`handleAdminSales`) and deliberately left `dl_reportSalesData()`
# row-driven and documented. Dispatching the old brief would have rejected correct work on a
# premise the implementation never held. The brief now describes the COMMITTED change.

cd /var/www/html/applicationostest || exit 1
source tools/lane-model.sh

# Sol first (the owner asked for it explicitly); terra is the strong fallback because Sol has
# crashed at least once in this session with a 75-byte log and exit=1.
LANE_MODEL_CHAIN="openai-codex/gpt-5.6-sol,openai-codex/gpt-5.6-terra,deepseek-v4-flash"

PROMPT="$(cat <<'PROMPT_EOF'
You are REVIEWING a COMMITTED change to an accounting-facing view in the Ikabud repo at
/var/www/html/applicationostest (PHP 8.5 local, MySQL 8.0.46 local, but production is MySQL 5.7
on shared hosting). Your job is to try to FALSIFY it, not to agree with it.

## Read the committed artifact first
    git --no-pager show --stat 32f2e431
    git --no-pager show 32f2e431 -- modules/daily-ledger/handlers.php \
        modules/daily-ledger/helpers/reporting.php \
        templates/modules/daily-ledger/admin/sales.disyl

## What the change actually does
The admin Sales page (`handleAdminSales`) was ROW-DRIVEN:

    FROM dl_daily_ledger dl
    INNER JOIN dl_products p ON p.id = dl.product_id
    INNER JOIN dl_branches b ON b.id = dl.branch_id
    LEFT JOIN dl_ledger_shift_status ss ...
    WHERE dl.branch_id IN (...) AND dl.ledger_date BETWEEN ? AND ?

It is now PRODUCT-DRIVEN, matching the cashier sheet (`dl_fetchCashierLedgerRows`):
every ACTIVE product assigned to an in-scope branch appears, whether or not a ledger row exists.
The date range and shift moved into the ledger LEFT JOIN's ON clause.

Two things it deliberately does NOT do — judge whether these are right:
  (a) `dl_reportSalesData()` in helpers/reporting.php STAYS ROW-DRIVEN, with a comment saying so.
      It is the ledger RECORD behind Daily Sales / Branch / Month-End / Category reports and the
      scheduled exports, where product-driving it "would turn a three-row exceptions export into
      thousands of synthetic rows."
  (b) A fourth status label 'no record' was added, gated on a NEW `has_ledger_row` key that the
      ledger-driven path does not set, so that path is unchanged.

Why: measured on branch 8 / 2026-10-03, the admin saw 73 rows while 109 active products had no
record at all. The gap recurs BY DESIGN because `dl_autoCarryBeginnings()` refuses a zero carry
source, so a product that ended a shift at zero has no row on the next shift.
Owner's requirement: "as long as admin can see all rows, no problem there."

## What the chair ALREADY MEASURED — attack these, do not redo them
Independent SQL probe, live branch 8, 7 windows (single days + 2026-10-01..05 + 2026-09-01..10-05):
  - official and provisional units AND amounts are byte-identical between the old row-driven
    shape and the new product-driven shape, to the cent, in all 7 windows. Widest:
    11,080 -> 11,084 rows, official 64692 / PHP 571330.61, provisional 14558 / PHP 145739.34.
  - no ledger row is written: COUNT and MAX(id) of dl_daily_ledger unchanged.
  - 2026-10-03: 73 -> 182 rows, 109 no-record.
Browser probe (Playwright, real page, admin login, read-only), same date/branch:
  - header "182 rows"; 109 "No record" badges; 71 amber "Pending count" badges; 0 provisional
  - footer "Official Total: 1 PHP 400" — matches the SQL probe exactly
  - the pending-dates banner names 2026-10-03, correctly, because 71 real rows there ARE pending
The lane's own fixture oracle passes 36/36 with a 3-render determinism assertion.

Your value is in what those measurements do NOT cover. Find it.

## Where to attack, in priority order

1. **The view/record split.** Is it defensible that the admin Sales VIEW now shows 182 rows
   (109 of them "No record") while the Daily Sales REPORT and the scheduled exports of the
   SAME business date still contain only the 73 recorded rows? An admin comparing the two sees
   different row counts for the same date. Is that a real contradiction, a documentation gap,
   or correct? Check the report/export paths and say plainly which.

2. **Other consumers of THIS view's output.** Anything that reads `sales_total_matching`,
   `sales_rows`, `sales_shown`, `grand_units`, `grand_amount`, `provisional_units`,
   `provisional_amount`, or the print view of /admin/sales. Note that `row_count` — and therefore
   `sales_total_matching` — now counts PRODUCTS, not ledger rows (73 -> 182). Find any place where
   that value's MEANING shifted: pagination, "N matching rows" arithmetic, a CSV/print sheet, a
   governed report, a test assertion, a dashboard.

3. **The truncation edge.** `DL_SALES_PAGE_ROW_LIMIT = 400` and the list ends in LIMIT, ordered
   `ORDER BY (dl.ledger_date IS NULL), dl.ledger_date DESC, ...` so no-record rows sort LAST and
   are the first to be cut. A single branch-day is at most products x 2 shifts (182 x 2 = 364,
   under the limit). Construct the case where an admin IS truncated and judge whether the page's
   disclosure ("Showing the newest X of N matching rows") is honest and sufficient, or whether
   the rows that matter silently vanish.

4. **The additive label.** `dl_salesRowStatusLabel()` returns 'no record' only when the key
   `has_ledger_row` exists AND is falsy. Find every caller and every row-producing path. Can any
   path pass a row that HAS a ledger row but carries a falsy/absent-valued `has_ledger_row`, and
   thereby mislabel a real row as 'no record'? Does any other caller now see a fourth label it
   does not handle?

5. **Filters.** Optional branch / search / shift. The shift predicate moved into the LEFT JOIN ON
   clause. With a shift filter set, is the result still correct? Try to construct a filter
   combination (branch + q + shift together; q matching a BRANCH name; a branch with no active
   products) that returns something wrong, ambiguous, or empty where it should not be.

6. **Read-only.** Confirm the view writes nothing: `bal_end` for a no-record product stays NULL,
   and no derived ending is persisted.

7. **MySQL 5.7.** No window functions, no CTEs, no JSON_TABLE, no `LIMIT` inside `IN (subquery)`.
   There is no 5.7 server locally, so this is an INSPECTION check — say so rather than implying
   you ran it.

8. **Anything this brief did not think about.** That is the most valuable thing you can return.

## How to report
Return a verdict: PASS | CHANGES_REQUIRED | BLOCKED.

For each finding: what is wrong, how it manifests, how you would verify it, and the smallest
correct fix. Label every finding as (a) MEASURED, (b) REASONED, or (c) COULD NOT CHECK.
Do not pad. If the change is sound, say so plainly and stop.

Do NOT modify any file. This is a read-only review. Do not weaken anything to reach agreement.
PROMPT_EOF
)"

lane_model_run "$LANE_MODEL_CHAIN" "$PROMPT" /tmp/review-admin-sales-full-sheet
rc=$?
echo "lane: review-admin-sales-full-sheet — completed by: ${LANE_MODEL_USED:-<none>} (rc=$rc)"
exit "$rc"
