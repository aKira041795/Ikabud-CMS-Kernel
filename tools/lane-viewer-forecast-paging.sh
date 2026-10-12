#!/usr/bin/env bash
#
# Lane: viewer-forecast-paging
#
# Source: docs/daily-ledger/VIEWER-SURFACE-ASSESSMENT-PLAN-2026-10-12.md — §4 F17 (426 KB, 454 rows,
# no paging, no "showing X of Y") and §11 chair ruling D7:
#   "Add a result count and pagination or an explicit 'show more'. Apply filters and sort BEFORE
#    pagination (never paginate first and sort each page)... changing page preserves filters + sort."
# Work item W13.
#
# Criterion (5 RED on the base, proven by the dispatch gate):
#   tools/acceptance-forecast-paging.php
#
# Owner directive driving this dispatch (2026-10-12): delegate to Sol until rate is exhausted.

cd /var/www/html/applicationostest || exit 1
source tools/lane-model.sh

LANE_MODEL_CHAIN="openai-codex/gpt-5.6-sol,deepseek-v4-flash,openai-codex/gpt-5.6-terra"

PROMPT="$(cat <<'PROMPT_EOF'
You are working in the Ikabud repo at /var/www/html/applicationostest. PHP 8.5 local; production is
MySQL 5.7 (label any 5.7 claim INSPECTION-ONLY). Local site http://baronledger.test runs tenant 207
(a copy of production); the surface under review is the daily-ledger Production Forecast page
(/daily-ledger/admin/forecast) seen as role=viewer (admin_view / viewer123).

MEASURED (2026-10-12, tenant 207, params date_from=2026-09-28&date_to=2026-10-11&window=14&
target_date=2026-10-12): the page renders ALL rows - 453 with the default activity filter, 788 with
'empty_rows=include' - about 426 KB, no paging, and no statement that shows page vs total.

# CONTEXT (read first)

- docs/daily-ledger/VIEWER-SURFACE-ASSESSMENT-PLAN-2026-10-12.md - §4 F17, §6 W13, §11 D7.
  The design is SETTLED; do not re-litigate it.
- tools/acceptance-forecast-paging.php - YOUR ORACLE. 5 RED right now. Make it 0 RED WITHOUT
  weakening a single assertion. It computes the full ordered populations through dl_forecastRows()
  and compares them to the RENDERED pages, and it FOLLOWS a pager link and asserts the next slice -
  so a hand-built link that drops any state parameter fails.

# REQUIRED OUTCOME (chair D7)

1. The forecast table renders a BOUNDED page (choose a sensible constant <= 200, e.g. 50; name it).
2. The page STATES the total: a "showing X of Y" style statement where Y is the FULL result count
   (453 default / 788 with the activity filter off), plus the card header keeping a total-vs-page
   distinction.
3. Filters and sort apply BEFORE slicing - the visible page is the HEAD of the same ordered list.
4. Paging preserves the FULL state. Build the pager links from ONE generator (not hand-written per
   link - the two hand-written sort links were removed earlier today for exactly that defect); every
   link carries: scope, branch_id, product_id, empty_rows, target_date, window, date_from, date_to,
   sort_dir, page. The oracle follows a page link and re-renders it; any dropped parameter shows up
   as the wrong slice.
5. `page` is allowlisted: integer >= 1; page=abc falls back to page 1; page=999 either clamps to the
   last page or renders an empty page - never a fatal.
6. dl_forecastRows() KEEPS its full-list contract: the Overview's aggregate forecast calls it, and
   this oracle uses it as the expected population. Slice in handleAdminForecast() ONLY.
7. No new auto-submitting control (one apply model stays - the filter form's Apply filters button).

# CONSTRAINTS

- Files you may change: templates/modules/daily-ledger/admin/forecast.disyl and the
  handleAdminForecast() region of modules/daily-ledger/handlers.php (roughly lines 13572-13640).
  Nothing else. Do NOT slice inside dl_forecastRows().
- Do NOT modify tools/acceptance-*.php or tests/**.
- KNOWN EXPECTED COLLATERAL: tools/acceptance-forecast-activity-filter.php F7 counts rendered <tr>
  rows and requires the include view to render MORE rows than the default view; once pages are
  bounded it will go red even though the feature is correct. That is the same class of stale oracle
  as this morning's forecast-sort lane: DO NOT edit it, report the specific failure in 'unresolved',
  and the harness will re-anchor it after your lane.
- MySQL 5.7: no window functions / CTEs / JSON_TABLE. Do not read more rows than needed per page.
- Do NOT git add / git commit; leave the tree for review.
- DiSyL lint: `php _lint_disyl.php` must stay at 703/703.

# VERIFY (report the actual output lines)

1. php tools/acceptance-forecast-paging.php          -> must end '0 RED'
2. php tools/acceptance-forecast-sort-control.php    -> must stay '0 RED' (regression)
3. php tools/acceptance-forecast-availability.php    -> must stay '0 RED'
4. php tools/acceptance-forecast-per-branch.php      -> must stay exit 0
5. php tests/daily-ledger/daily_ledger_forecast_test.php -> must stay green
6. php _lint_disyl.php                               -> 703/703

# REPORT (exactly this shape)

status: PASS|FAIL|PARTIAL|BLOCKED
changed: file:line each
design_calls: page size; pager mechanism (how the links are generated); the total-statement wording
verification: the six commands' result lines, verbatim
unresolved: (the activity-filter F7 collateral is expected - name it here)
PROMPT_EOF
)"

lane_model_run "$LANE_MODEL_CHAIN" "$PROMPT" /tmp/lane-viewer-forecast-paging
