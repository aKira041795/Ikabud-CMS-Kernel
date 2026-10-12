#!/usr/bin/env bash
#
# Lane: viewer-forecast-sort-control
#
# Source: docs/daily-ledger/VIEWER-SURFACE-ASSESSMENT-PLAN-2026-10-12.md — work items W1 + W14,
# findings F1/F2/F3, chair rulings D1/D2 (§11). The design is settled: ONE sort control inside the
# filter form, delete the two hand-written links, one apply model per page.
#
# Criterion (proven 7 RED on the base by the dispatch gate):
#   tools/acceptance-forecast-sort-control.php
#
# Owner directive driving this dispatch (2026-10-12): "delegate to sol some code edits until rate
# is exhausted." The chain below keeps the standard fallbacks so a mid-lane quota death cannot leave
# a half-edited tree; the run reports which model completed.

cd /var/www/html/applicationostest || exit 1
source tools/lane-model.sh

LANE_MODEL_CHAIN="openai-codex/gpt-5.6-sol,deepseek-v4-flash,openai-codex/gpt-5.6-terra"

PROMPT="$(cat <<'PROMPT_EOF'
You are working in the Ikabud repo at /var/www/html/applicationostest. PHP 8.5 local; production is
MySQL 5.7 (label any 5.7 claim INSPECTION-ONLY). Local site http://baronledger.test runs tenant 207
with a copy of production data; the surface under review is the daily-ledger module seen as
role=viewer (admin_view / viewer123).

# CONTEXT (read these first)

- docs/daily-ledger/VIEWER-SURFACE-ASSESSMENT-PLAN-2026-10-12.md - §4 findings F1-F3, §6 work items
  W1/W14, §11 chair rulings D1/D2. The design is SETTLED; do not re-litigate it.
- tools/acceptance-forecast-sort-control.php - YOUR ORACLE. It is 7 RED on this tree (the dispatch
  gate proved it). Make it green WITHOUT weakening a single assertion; editing that file means
  rejection of the lane.

# THE DEFECT (measured 2026-10-12 on the rendered page)

On the Production Forecast page (templates/modules/daily-ledger/admin/forecast.disyl):
- the "Sort by suggested qty" group is TWO hand-written <a> links (sort_dir=desc / sort_dir=asc).
  They omit product_id, so clicking a direction CLEARS a selected product filter (F1).
- the filter <form> itself has NO sort_dir control, so pressing the form's submit silently resets
  the sort to desc (F2).
- the inactive link renders class="btn btn-sm " with NO background: it looks disabled (F3).

Handler: handleAdminForecast() at modules/daily-ledger/handlers.php:13572. It already normalizes
sort_dir through dl_overviewNormalizeSortDir (helpers/reporting.php:1977) and passes {sort_dir} to
the template. dl_forecastSortByQty (helpers/reporting.php:2285) already tie-breaks equal quantities
on branch/product/shift - KEEP that behaviour; the oracle asserts it as a regression.

# REQUIRED OUTCOME (the ruling; Sol and the chair agree - doc §11 D1/D2)

1. Replace the two links with ONE <select name="sort_dir"> INSIDE the existing filter form: options
   desc ("Highest first") and asc ("Lowest first"), echoing the in-force value via {sort_dir}; desc
   stays the default on an untouched page load.
2. The form then carries: scope (partial), target_date, window, branch_id, product_id, empty_rows,
   sort_dir - and ONE submit. No control may auto-submit (no this.form.submit(), no auto-submitting
   onchange anywhere in the form).
3. Rename the submit button text "Recalculate" -> "Apply filters" (chair ruling D5: it applies
   filters; it does not recompute or persist anything).
4. The handler comment at handlers.php ~13620-13624 ("Carried by the sort links...") becomes false -
   update that comment. Remove now-unused handler context keys ONLY if you first prove the template
   no longer reads them AND nothing else does; when in doubt, leave them.
5. Keep the change MINIMAL: the forecast template + that comment. Do not touch the layout, other
   templates, or other pages. The layout's write-failure banner anchors (Retry/Dismiss) are filled
   (btn-primary/btn-accent) and stay; they are not covered by the defect.

# CONSTRAINTS

- Do NOT modify tools/acceptance-forecast-sort-control.php.
- Do NOT weaken, disable or reword any existing test/acceptance to reach PASS. If an oracle
  disagrees, report it instead of moving the goalposts.
- MySQL 5.7 production: no window functions, CTEs, JSON_TABLE (not expected - this is a template
  change).
- Do NOT git add / git commit; leave the tree for review.
- DiSyL lint must stay at its baseline: `php _lint_disyl.php` -> "703 file(s) valid".

# VERIFY (report the actual output lines)

1. php tools/acceptance-forecast-sort-control.php        -> must end "0 RED"
2. php tools/acceptance-forecast-activity-filter.php     -> must stay "0 RED" (regression)
3. php _lint_disyl.php                                   -> must stay 703/703

# REPORT (exactly this shape)

status: PASS|FAIL|PARTIAL|BLOCKED
changed: file:line each
verification: the three commands' result lines, verbatim
unresolved:
PROMPT_EOF
)"

lane_model_run "$LANE_MODEL_CHAIN" "$PROMPT" /tmp/lane-viewer-forecast-sort-control
