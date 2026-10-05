#!/usr/bin/env bash
#
# Lane: fix-admin-sales-scope-and-copy
#
# Follows the Sol review of 32f2e431 (verdict CHANGES_REQUIRED). The chair MEASURED every
# finding before accepting it. This lane fixes the findings that are unambiguous and do NOT
# depend on the still-open grain question (whether the sheet should become a full
# date x shift x product grid — that is a product decision and is deliberately NOT in scope here).
#
# MEASURED BASE FACTS (chair, live branch 8, tenant 207, 2026-10-05):
#   1. 0 of 12,277 ledger rows are dropped today by the is_active requirements — so the
#      identical totals in 32f2e431 are explained, not lucky. The hazard is LATENT:
#      deactivating a product would silently erase its recorded history AND its money
#      from the admin view and its totals, while dl_reportSalesData() keeps showing it.
#   2. 4 branch-8 products were created AFTER 2026-10-03 and still render as "No record"
#      rows on 2026-10-03 — the view invents rows for dates before the product existed.
#   3. On 2026-10-03, 73 products have an AM row and NO PM row; those missing PM cells
#      render as no row at all (109 no-record + 73 AM = the 182 the page claims is complete).
#   4. dl_reportSalesData() is row-driven, so the page's remediation text
#      "Reports -> Daily Sales ... to view and export the full set" is FALSE for the rows
#      the page omits.

cd /var/www/html/applicationostest || exit 1
source tools/lane-model.sh

LANE_MODEL_CHAIN="deepseek-v4-flash,openai-codex/gpt-5.6-terra,openai-codex/gpt-5.6-sol"

PROMPT="$(cat <<'PROMPT_EOF'
You are IMPLEMENTING a bounded fix in the Ikabud repo at /var/www/html/applicationostest.
Read the committed prior work first:

    git --no-pager show 32f2e431 -- modules/daily-ledger/handlers.php \
        modules/daily-ledger/helpers/reporting.php \
        templates/modules/daily-ledger/admin/sales.disyl

That commit made the admin Sales VIEW (`handleAdminSales`) product-driven so the admin can see
products that have no ledger row, instead of only recorded rows. It is correct on money
(official/provisional totals byte-identical across 7 measured windows) and no ledger row is
written. Three refinements are required. Do NOT redesign the view; keep the change minimal.

## FIX A (required) — a deactivation must never erase recorded history
The new query requires `p.is_active = 1` AND an active `dl_branch_products` row. The PREVIOUS
query had no such filter. Today zero recorded rows are affected, but retiring a product is a
normal admin operation, and doing so would silently remove its sales from the admin view AND
from the page totals for historical dates — while `dl_reportSalesData()` still reports them.

Requirement: the driving set must be the UNION of
  (1) currently-active branch/product assignments, and
  (2) any (branch, product) pair that actually HAS a ledger row in the selected date range.
Active-product / active-assignment restrictions may apply only to SYNTHETIC no-record rows.
A row that exists in `dl_daily_ledger` must NEVER be filtered out by is_active, and must never
disappear from the totals.

Keep it MySQL 5.7-safe: no CTEs, no window functions, no JSON_TABLE, no LIMIT inside IN (subquery).

## FIX B (required) — do not project today's products backwards in time
4 branch-8 products created after 2026-10-03 are rendered as "No record" on 2026-10-03. A
synthetic no-record row may only be produced for a product/assignment that already existed on
the viewed date. `dl_products.created_at` and `dl_branch_products.created_at` both exist.
Bound the synthetic rows by the range end (and be explicit in a comment about the timezone
assumption you make). Existing ledger rows are never subject to this bound.

## FIX C (required) — stop the page making claims that are not true
1. When the row list is truncated by DL_SALES_PAGE_ROW_LIMIT, the warning links to
   "Reports -> Daily Sales" as if it were the full version of this view. It is not: that report
   is row-driven and contains only recorded ledger entries, never no-record rows. Correct the
   wording so it states plainly that the report exports RECORDED entries only, and disclose how
   many no-record rows were omitted (you have `sales_total_matching` and `sales_shown`; the
   omitted no-record count must be computed, not guessed).
2. The "No record" tooltip in templates/modules/daily-ledger/admin/sales.disyl says "no ledger
   row exists for this product on this shift". When the Shift filter is All, the row means "no
   ledger row anywhere in the selected range", not per shift. Make the wording accurate for the
   filter state actually selected.

Do NOT add pagination. Do NOT change the money semantics. Do NOT touch dl_reportSalesData()'s
row-driven shape — keeping governed reports row-driven is deliberate and correct.

## Provenance
The lane that made 32f2e431 left the tree mid-flight: attempt 1 (deepseek-v4-flash) died on
timeout (exit=124) after editing, and terra inherited its work. Two models wrote this code, so
treat the existing implementation as untrusted and re-read it rather than assuming intent.

## Deliverable — an ORACLE, not just a fix
Extend `tests/daily-ledger/daily_ledger_admin_sales_full_sheet_test.php` (it passes 36/36 now;
keep it passing) with fixtures on its PRIVATE branch (99230) proving, by MEASUREMENT:
  A1. a product is deactivated AFTER its ledger row exists -> that row is still rendered AND
      still counted in the official/provisional totals for that date. (This FAILS on the base
      tree — it is the discriminating case.)
  A2. an assignment made inactive behaves the same way.
  B1. a product created AFTER the viewed date produces NO synthetic row on that date.
      (Also FAILS on the base tree.)
  C1. when the row list is truncated, the rendered warning does NOT tell the admin that the
      governed report contains the no-record rows.
Also confirm the totals invariant still holds after A and B (money unchanged by either fix).

## Acceptance
Run, and report the exact output of:
    php tests/daily-ledger/daily_ledger_admin_sales_full_sheet_test.php
    php -l modules/daily-ledger/handlers.php && php -l modules/daily-ledger/helpers/reporting.php
    git --no-pager diff --stat
Then re-run the chair's independent probes and report them verbatim:
    php /tmp/chair-verify-full-sheet.php
(7 windows; official+provisional money must stay identical to the cent, and MAX(id) of
dl_daily_ledger must not move).

Also check BOTH logs after every run: storage/logs/app.log and storage/logs/error.log
(error.log must stay empty).

## Rules
- Smallest correct change. No refactor of unrelated code, no new dependencies, no scope creep.
- A no-record row contributes ZERO money and must never be labelled pending.
- Never weaken, skip, or delete an existing assertion to reach green.
- If you cannot satisfy FIX A without an architectural change, STOP and report
  BLOCKED with the reason rather than inventing a design.
- Report status PASS | PARTIAL | BLOCKED, the files changed, and the measured evidence.
PROMPT_EOF
)"

lane_model_run "$LANE_MODEL_CHAIN" "$PROMPT" /tmp/fix-admin-sales-scope-and-copy
rc=$?
echo "lane: fix-admin-sales-scope-and-copy — completed by: ${LANE_MODEL_USED:-<none>} (rc=$rc)"
exit "$rc"
