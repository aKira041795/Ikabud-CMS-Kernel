#!/usr/bin/env bash
#
# Lane: surface-gaps-g1-g3
#
# Two decided, small fixes from .ai/dl-surface-gaps-decisions.md (owner: "you decide on gaps surfaced and
# align with the working processes"). Both make a surface follow the principle it already belongs to; neither
# adds a new concept, and both are special cases in code that are INVISIBLE in the UI — which the owner's
# corrected principle explicitly permits ("nothing wrong with special cases, as long as the UI is kept simple
# and background processes are not emitted for user to decide").
#
# G1 — the report product filter hides a retired product.
#   `dl_reportFilterProducts()` (helpers/reporting.php ~106) lists products via
#   `JOIN dl_branch_products bp ON bp.product_id = p.id AND bp.is_active = 1 WHERE p.is_active = 1`.
#   So a product hidden from EVERY branch (exactly what the new picker is for — retiring a line) drops out of
#   the filter dropdown, even though its rows are still IN the report it filters.
#   CHAIR ADJUSTMENT — additive, NOT a replacement: keep every product the dropdown offers today AND add the
#   products that have rows in the selected scope/range. Replacing the source outright would remove a product
#   that is assigned but has no rows in range, and this dropdown may be shared by several report panels, so a
#   replacement risks losing an option some panel needs. Additive closes the gap with zero regression surface.
#
# G3 — CSV import silently undoes per-branch work (a bug WE introduced).
#   Import does `INSERT IGNORE INTO dl_branch_products` and always attempts all branches. INSERT IGNORE will
#   NOT reactivate an existing inactive pair, so an unassignment survives — BUT for a product whose
#   `assignment_mode = 'specific'` and which has NO row for a branch, it CREATES the row and therefore
#   re-assigns a product the admin deliberately scoped.

cd /var/www/html/applicationostest || exit 1
source tools/lane-model.sh

LANE_MODEL_CHAIN="deepseek-v4-flash,openai-codex/gpt-5.6-terra,openai-codex/gpt-5.6-sol"

PROMPT="$(cat <<'PROMPT_EOF'
You are implementing two small, decided fixes in the Ikabud repo at /var/www/html/applicationostest.
PHP 8.5 local; production is MySQL 5.7 and no 5.7 server exists locally — label any 5.7 claim INSPECTION-ONLY.

READ FIRST:
  modules/daily-ledger/helpers/reporting.php   dl_reportFilterProducts()  (~106)
                                               dl_reportSalesData()       (~228)  <-- the RECORD the filter
                                               must agree with; it is ROW-DRIVEN
  modules/daily-ledger/handlers.php            the CSV import path: grep for `INSERT IGNORE INTO
                                               dl_branch_products` (two sites, ~16133 and ~16183), plus
                                               apiProductsImportCsv and the `assignment_mode` column added in
                                               migration 077
  .ai/dl-branch-product-visibility.contract.md (context: record vs view)

## G1 — make the report product filter additive (rows OR assignment)
`dl_reportFilterProducts()` must offer BOTH:
  (a) every product it offers TODAY (an active assignment in the accessible branches) — unchanged behaviour, and
  (b) any product that HAS rows in the selected scope/range (accessible branches, date_from..date_to).
Keep the function's signature and its return shape (`id`, `sku`, `name`, de-duplicated, ordered by name) so
every caller is unaffected. Do NOT add an `is_active` filter that would drop a product whose rows are still in
the report — the report itself does not exclude them.
Use the filters the function already receives; if `date_from`/`date_to` are absent from `$filters`, derive the
same defaults the report uses rather than inventing new ones, and say what you chose.
MySQL 5.7-safe: no CTE, no window function, no JSON_TABLE, no LIMIT inside IN (subquery).

## G3 — stop the import re-assigning 'specific' products
In the import's branch-assignment step, assign branches ONLY for products whose `assignment_mode =
'all_active'` (today's behaviour for them). Products with `assignment_mode = 'specific'` must be left exactly
as they are — do not create, reactivate, or delete any of their rows. Keep everything else about the import
unchanged (same file format, same INSERT IGNORE style, no new columns, no new UI, and NO full assignment
support in CSV — that is explicitly not wanted).
Check BOTH import sites, and any other place the import assigns branches.

## ORACLE — required, and it must DISCRIMINATE on the base tree
Extend or add tests under tests/daily-ledger/ (TestHarness pattern; private fixture branches/products — never
real branch 8, and clean up every synthetic row you create):
  G1a (**discriminating**): a product with rows in range but NO active assignment anywhere MUST be offered by
       `dl_reportFilterProducts()`. On the base tree it is absent — that is the gap.
  G1b: a product with an active assignment and no rows in range is STILL offered (proves the fix is additive).
  G1c: a product with neither is not offered.
  G1d: the existing behaviour for a normal assigned+recorded product is unchanged, and the return shape
       (ids/sku/name, de-duplicated, name-ordered) is unchanged.
  G3a (**discriminating**): after an import touching a `specific`-mode product that has no row for branch B,
       that product STILL has no active row for B. On the base tree the import creates it.
  G3b: an `all_active`-mode product is still assigned to all active branches by the import (unchanged).
  G3c: an existing INACTIVE pair for a 'specific' product is still inactive after an import (not reactivated).
State which cases FAIL on the base tree and which pass — do not claim discrimination you did not observe.

## HARD CONSTRAINTS
- Do NOT change the removal guard, its Option A window, the row predicates, the write-side validation, the two
  sheet list queries, handleAdminSales, dl_reportSalesData's own query, the carry, the auto-close path, or
  migration 077.
- No UI change of any kind.
- Keep storage/logs/error.log empty.
- Note: the daily-ledger suite exercises the REAL close path, which now has a live side effect (it closes stale
  days on the tenant it runs against). That is already drained; just be aware a suite run is not inert.

## ACCEPTANCE — run and report verbatim
    php tests/daily-ledger/<your new/extended test>.php
    php tests/daily-ledger/daily_ledger_stale_day_sweep_test.php
    php tests/daily-ledger/daily_ledger_close_day_and_notify_test.php
    php tests/daily-ledger/daily_ledger_branch_product_visibility_test.php
    php tests/daily-ledger/daily_ledger_branch_product_picker_test.php
    php tests/daily-ledger/daily_ledger_admin_sales_full_sheet_test.php
    php -l <each changed php file>
    git --no-pager diff --stat
Then php scripts/run-tests.php --dir=tests/daily-ledger, separating PRE-EXISTING failures (known log/
notification pollution: daily_ledger_dispatch_enforcement_test, daily_ledger_preserve_cashier_variance_test,
flaky daily_ledger_delivery_record_authz_test) from new ones.
Also report the LIVE effect on the report filter list for branch 8: the count of products offered BEFORE vs
AFTER (read-only). If it is unchanged today, say so plainly — that is the expected no-op, since tenant 207 has
zero hidden pairs.

## Rules
- Smallest correct change. No refactor, no new dependency, no scope creep.
- Never weaken, skip, or delete an assertion to reach green.
- Report status PASS | PARTIAL | BLOCKED; files changed; tests added/changed; base-tree discrimination;
  before/after live counts; anything you could not verify.
PROMPT_EOF
)"

lane_model_run "$LANE_MODEL_CHAIN" "$PROMPT" /tmp/surface-gaps-g1-g3
rc=$?
echo "lane: surface-gaps-g1-g3 — completed by: ${LANE_MODEL_USED:-<none>} (rc=$rc)"
exit "$rc"
