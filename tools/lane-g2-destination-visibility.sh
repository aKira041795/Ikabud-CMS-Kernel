#!/usr/bin/env bash
#
# Lane: g2-destination-visibility
#
# OWNER AGREEMENT (2026-10-06, verbatim): "i agree with it so user, when encoding will not accidentally set data
# on a product cell that's hidden/deactivated"
#
# G2 of .ai/dl-surface-gaps-decisions.md. The enhancement is COHERENCE: the admin's per-branch visibility
# setting becomes true on the operational surfaces, not only on the two sheet lists.
#
# MEASURED PREMISE (chair, verified in code):
#   * The production sheet builds `branch_cells` with `foreach ($sheetBranches as $sheetBranch)`
#     (handlers.php ~17898) — EVERY active branch, no destination check. 19 branches x 182 products =
#     3458 destination cells, none filtered.
#   * The sheet is a `<table>` whose COLUMNS are the branches: header
#     `<th ... data-branch-id="{br.id}">` (commissary.disyl ~1417), cell
#     `<td class="text-right production-branch-cell" data-branch-id="{cell.branch_id}">` (~1466). The existing
#     conditional at ~1465 (`{if cell.branch_id != sheet_source_branch_id}`) is safe only because it is
#     COLUMN-UNIFORM (same branch skipped for every row).
#   * The JS finds cells by selector `.production-branch-cell[data-branch-id="X"] button[data-product="P"]`
#     (~2037).
#   * `dl_fetchProductionSheetProducts()` requires `p.is_active = 1` AND an active source-branch assignment,
#     so a DEACTIVATED product has NO ROW AT ALL — it is not a disabled cell. Do not conflate the two.
#
# THE RULE (one sentence an admin can predict):
#   "Can't enter a destination the store doesn't carry — unless something's already there."
#
# QUEUED NOTE: the owner was building a deploy package. This lane edits production files, so a package built
# while it runs would capture a half-applied change. It has been flagged to the owner.

cd /var/www/html/applicationostest || exit 1
source tools/lane-model.sh

LANE_MODEL_CHAIN="deepseek-v4-flash,openai-codex/gpt-5.6-terra,openai-codex/gpt-5.6-sol"

PROMPT="$(cat <<'PROMPT_EOF'
You are implementing G2 (destination visibility) in the Ikabud repo at /var/www/html/applicationostest.
PHP 8.5 local; production is MySQL 5.7 and no 5.7 server exists locally — label any 5.7 claim INSPECTION-ONLY.

READ FIRST:
  .ai/dl-surface-gaps-decisions.md            (G2, and the record/view principle)
  modules/daily-ledger/handlers.php           the `branch_cells` build (~17896-17960) and the render payload
                                              (~18123); dl_processProductionMovement (~4371);
                                              handlers-deliveries.php apiCreateDelivery (~931) and its
                                              product picker (~2830); dl_buildUsagePageData (~16233 onwards)
  templates/modules/daily-ledger/admin/commissary.disyl   the cell loop (~1464-1480) and the cell JS (~2037)
  templates/modules/daily-ledger/admin/usage.disyl
  modules/daily-ledger/handlers.php           dl_branchProductUnassignmentBlockers() and the write-side
                                              validation pattern (PRODUCT_NOT_ASSIGNED) added by Slice A —
                                              REUSE the error/message style, do not invent a new one

## C1 — the destination cell becomes INACTIVE IN PLACE (never removed)
For each product row, a destination branch's cell must render DISABLED (not enterable, greyed, with a short
reason available to the user) when that destination does NOT carry the product, i.e. no active
`dl_branch_products` row for (destination_branch_id, product_id).

HARD REQUIREMENTS:
  - DO NOT remove the cell or the column. The header defines the columns; dropping a cell from one row makes the
    table ragged and breaks the `.production-branch-cell[data-branch-id]` lookups the JS relies on. The cell
    stays, disabled.
  - KEEP THE EXISTING ENTRY VISIBLE. If the cell already holds a dispatch quantity or a delivery/receiving row,
    it must render normally (visible, with its data). G2 must NEVER make existing stock invisible — a store may
    have received a product before it was hidden. This is the single most important requirement in this lane.
    (If you judge that an existing entry should also be non-enterable, keep it VISIBLE and say so in the report;
    do not hide it.)
  - A deactivated product is OUT OF SCOPE here: it has no row at all. Do not add a deactivated-product path.
  - Reversible: re-assigning the product makes the cell enterable again. Nothing permanent.
  - Keep the cell's existing hooks (`data-branch-id`, the `production-branch-value` span with
    `data-product`/`data-branch-id`) so the page's JS keeps working for every OTHER cell.
  - The reason must be discoverable but not noisy: a `title`/`aria-disabled` style affordance is enough; do not
    add a new banner or a modal.

## C2 — the WRITE path refuses an unassigned destination (the safety net)
A dispatch (production movement) or a delivery must be REFUSED when the destination branch does not carry the
product, with a machine-readable code and a message naming the product and the branch. Reuse Slice A's
`PRODUCT_NOT_ASSIGNED` style rather than inventing a new error vocabulary. This is what makes a stale tab or an
offline device harmless even if the UI is bypassed.
  - Validate the DESTINATION. (Slice A already validates the source/sheet assignment.)
  - Do NOT silently drop the write and do NOT create any row.
  - For a delivery, the rule is the destination store must carry the product.

## C3 — the Usage page stops offering products the branch does not carry
`dl_buildUsagePageData()` renders every globally active product for the selected branch. Filter it to the
branch's assignments so the "less scrolling" win is not undone in a second place.
  DELIBERATELY NOT CHANGING the generic `/api/v1/production/products` endpoint: it has no branch parameter and
  a mobile/Android client may consume it, so changing its payload risks breaking a working client for a
  read-only surface with no user-visible harm. State this explicitly in your report, and if you find that
  endpoint DOES feed a surface covered here (or a visible screen), say so and propose the smallest fix instead
  of changing it silently.

## ORACLE — required, and both directions of the C1 rule
Fixtures via the TestHarness pattern (private fixture branches/products — never real branch 8; clean up):
  D1 (**discriminating**): destination does NOT carry the product and the cell is EMPTY -> the cell renders
      disabled and is not enterable. On the base tree it renders normally.
  D2 (the safety case): destination does NOT carry the product but the cell ALREADY holds a dispatch/delivery
      -> the quantity is STILL VISIBLE on the sheet (never hidden). On base this also passes — it exists to
      pin that G2 does not break it.
  D3 reversibility: after re-assigning the product to the destination, the same cell renders enterable again.
  D4 the column set is UNCHANGED: the render payload still contains one cell per (product x active non-source
     branch), so no row becomes shorter than the header.
  D5 (**discriminating**): the dispatch write to an unassigned destination is REFUSED with
     PRODUCT_NOT_ASSIGNED and creates NO row.
  D6: a delivery to an unassigned destination is likewise refused.
  D7: a NORMAL dispatch to an assigned destination still succeeds unchanged (no-op proof on today's data,
     where all 3458 pairs are active).
  D8: the Usage page no longer lists a product the branch does not carry, and still lists every one it does.
State which cases FAIL on the base tree. Do not claim discrimination you did not observe.

## HARD CONSTRAINTS
- Do NOT change the removal guard or its Option A window, the row predicates, the picker/assignment tab, the
  report filter, the import, the close/auto-close paths, dl_reportSalesData, or the admin Sales view's UNION.
- Do NOT change `dl_fetchProductionSheetProducts()`'s product universe (p.is_active + source assignment) —
  this lane governs DESTINATION cells, not row existence.
- The sheet's table structure and column count must be untouched.
- MySQL 5.7-safe: no CTE, no window function, no JSON_TABLE, no LIMIT inside IN (subquery).
- No UI change beyond the destination cell's disabled presentation.
- Keep storage/logs/error.log empty.
- NOTE: the daily-ledger test suite exercises the REAL close path and therefore has a live side effect; it is
  currently drained, so a run closes nothing, but a suite run is not inert.

## BROWSER VERIFICATION — READ-ONLY
Extend tests/browser/daily-ledger-branch-product-visibility.spec.js ONLY if you can assert the cell rule without
mutating real data. Tenant 207 has ZERO hidden pairs, so live cells are all enabled today and a live assertion
of the disabled state is not possible without changing real assignments — in that case say so plainly and rely
on the oracle. DO NOT change real assignments and DO NOT dispatch real stock. Run only that spec:
    APP_URL=http://baronledger.test npx playwright test tests/browser/daily-ledger-branch-product-visibility.spec.js --reporter=line --retries=0
and confirm the production sheet still renders (200, its rows and its branch columns intact).

## ACCEPTANCE — run and report verbatim
    php tests/daily-ledger/<your new/extended test>.php
    php tests/daily-ledger/daily_ledger_g1_g3_report_filter_and_import_test.php
    php tests/daily-ledger/daily_ledger_branch_product_visibility_test.php
    php tests/daily-ledger/daily_ledger_branch_product_picker_test.php
    php tests/daily-ledger/daily_ledger_stale_day_sweep_test.php
    php tests/daily-ledger/daily_ledger_close_day_and_notify_test.php
    php tests/daily-ledger/daily_ledger_admin_sales_full_sheet_test.php
    php -l <each changed php file>
    git --no-pager diff --stat
Then php scripts/run-tests.php --dir=tests/daily-ledger. NOTE the chair's corrected fact: the three tests
previously described as "known pre-existing failures" (dispatch_enforcement, preserve_cashier_variance,
delivery_record_authz) PASS in every run; the real transient noise is `kernel_state_cache: module_registry
rebuilt` from the runner deleting storage/modules.json per test. Do not excuse a failure using the old list.

## Rules
- Smallest correct change. No refactor, no new dependency.
- Never weaken, skip, or delete an assertion to reach green.
- If C1 cannot be done without hiding an existing entry, STOP and report BLOCKED with the case.
- Report status PASS | PARTIAL | BLOCKED; files changed; tests added/changed; base-tree discrimination; the
  exact rule you implemented for cells that already hold an entry; C3's API decision; anything unverified.
PROMPT_EOF
)"

lane_model_run "$LANE_MODEL_CHAIN" "$PROMPT" /tmp/g2-destination-visibility
rc=$?
echo "lane: g2-destination-visibility — completed by: ${LANE_MODEL_USED:-<none>} (rc=$rc)"
exit "$rc"
