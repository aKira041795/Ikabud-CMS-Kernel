#!/usr/bin/env bash
#
# Lane: products-overview
#
# Owner direction (2026-10-11): "we can separate the product level in its own view. don't integrate
# with reports. this will give the business the focus needed. - a buisiness health overview -
# products overview - reports generation which can summarize the first two"
#
# ACCEPTANCE CRITERION - written and MEASURED RED by the chair BEFORE dispatch:
#   php tools/acceptance-products-overview.php   ->  4 RED, exit 1
#     R1 RED  the four product analytics have 0 call sites - nothing renders them
#     R2 RED  no /admin/products/performance route
#     R3 RED  not reachable by a viewer
#     R4 RED  no nav entry
#     R5 PASS Health must KEEP having no product blocks (its A8) - keep it passing
#
# DISPATCH NOTE: this script must be launched detached and WITHOUT a pipe.
#   nohup tools/lane.sh run products-overview tools/lane-products-overview.sh ... > /tmp/x.out 2>&1 &
# Piping a lane dispatch through tail makes the tool re-execute it in a second terminal; two lanes,
# one run name, one tree lock, one kills the other. Cost a full dispatch on 2026-10-11.

cd /var/www/html/applicationostest || exit 1
source tools/lane-model.sh

LANE_MODEL_CHAIN="deepseek-v4-flash,openai-codex/gpt-5.6-sol,openai-codex/gpt-5.6-terra"

PROMPT="$(cat <<'PROMPT_EOF'
You are building the PRODUCTS OVERVIEW for the Ikabud repo at /var/www/html/applicationostest.
PHP 8.5 local; production MySQL 5.7 (INSPECTION-ONLY claims). Local site http://baronledger.test
against a copy of the production DB. Read-only: render and resolve, never write business data.

# YOUR ACCEPTANCE CRITERION - run FIRST, confirm 4 RED, make it exit 0
    php tools/acceptance-products-overview.php
Owned by the chair. Do NOT weaken, rewrite or delete it. R5 passes today and MUST stay passing.
If you believe a check is wrong, say so with evidence and leave the file intact.

# THE POINT OF THIS TASK
The owner has split the business reporting surface into three focused views:
    1. Health overview    - how is the business doing      (already built)
    2. Products overview  - the product level, on its own  (THIS TASK)
    3. Reports            - generates and summarises 1 + 2  (already exists)
The product analytics currently render NOWHERE. They are orphaned with 0 call sites:
    dl_overviewTopProducts, dl_overviewPareto, dl_overviewBranchProductTotals, dl_overviewProductTotals
    (all in modules/daily-ledger/helpers/reporting.php)
They were deliberately removed from the Health view, whose acceptance A8 asserts it has no product
blocks, so that Health could focus. Your job is to give them the page they lost.

## THIS IS A THIN BUILD - REUSE, DO NOT REINVENT
The four queries above are written AND tested. WIRE THEM UP. Do not write new analytics SQL unless you
prove a genuine gap and say what it is. Prefer the existing reporting helpers over a new query.

## DO NOT TOUCH THE CATALOGUE PAGE
/daily-ledger/admin/products -> handleAdminProducts already exists and is a 1081-line CATALOGUE
screen: product list, Add Product, Edit Product, Import Products Data (CSV), consignee assignment.
That is product CONFIGURATION, a different job. Leave it, its route and its template alone. It does
NOT satisfy this requirement and the criterion does not accept it.

## THE PAGE
- route: /daily-ledger/admin/products/performance  (adjacent to the catalogue, unambiguous, and it
  does not collide with the existing /admin/products/export)
- template: a new modules/daily-ledger/admin/products-performance.disyl
- must render product PERFORMANCE for the selected period: what sells, what does not, and where -
  i.e. the top-products / per-branch / Pareto content the queries already provide.
- honour the AREA SCOPE like every other view: use dl_adminViewBranchIds() / dl_reportFilters() so
  the existing area + branch + date-range controls narrow it. A viewer must see ONLY their area.
  This is the third view in a set where area scoping was just made consistent across all of them -
  do not make it the exception.
- the owner reviews as the `viewer` role, so R3 requires a viewer to render it without an
  authorisation refusal. Gate it the same way the other viewer-visible admin views are gated.
- nav: add an entry in templates/modules/daily-ledger/layouts/app.disyl, visible to the roles that
  may open it. Current viewer nav is Overview + Reports; it becomes Health + Products + Reports.

## CONSTRAINTS
- Do NOT add product blocks back into the Health view. R5 enforces this. The whole point is that the
  two views are separate.
- MySQL 5.7: no window functions, no CTEs, no JSON_TABLE, no CHECK.
- Reuse the existing filter/scope helpers rather than reading $_GET directly for scope.
- If you change a .disyl template: run `php _lint_disyl.php` (must stay 701/701 valid). A previous
  change broke this gate by spelling a DiSyL tag in PROSE inside a comment - the linter counts it.
- Do NOT weaken, disable, reword or delete an existing assertion to reach PASS. If an oracle
  disagrees with correct code, identify which side is stale (git log -S, then git log -1) and report
  it instead of editing the test.
- Every guard you add must be FALSIFIABLE, and the mutation must be shown EFFECTIVE in that scenario
  (print the changed value or assert the branch was reached). A mutation that does not execute the
  changed branch proves nothing.
- After committing: verify with `git show HEAD:<file> | grep <the-change>` that the fix is in the
  COMMITTED BLOB. A previous commit omitted its template and the fix silently vanished from history
  while the worktree looked correct.

# REPORT
status: PASS|FAIL|PARTIAL|BLOCKED
acceptance_before / acceptance_after: the RED then GREEN output
page: route, template, which existing query feeds which section
scope: exactly how area/branch/date narrow the page, and what a viewer sees vs an admin
reuse: which existing queries/functions you wired, and any new SQL with the reason it was needed
nav: the entry, and which roles see it
falsification: mutation per new guard, with evidence it executed
changed: file:line each
verification: the acceptance criterion, the overview suite, and _lint_disyl.php
unresolved:
PROMPT_EOF
)"

lane_model_run "$LANE_MODEL_CHAIN" "$PROMPT" /tmp/lane-products-overview
