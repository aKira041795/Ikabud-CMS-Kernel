#!/usr/bin/env bash
#
# Lane: production-efficiency-view
#
# Owner: "for forecast, separate this too in its own view".
# Chair decision: a new peer view at /daily-ledger/admin/production-efficiency, not a block inside
# the forecast page. Basis: the owner's own pattern - Health, Products each got their own surface
# ("this will give the business the focus needed"), and "too" means like those.
#
# ACCEPTANCE CRITERION, measured RED by the chair BEFORE dispatch:
#   php tools/acceptance-production-efficiency.php   ->  9 RED, exit 1
#     N1 no route   N2 no nav entry   N3 not reachable by a viewer
#     E1..E5 no produced/sold/wasted content, no filter, no rate, no basis, no efficiency ratio
#     E6 the forecast route must SURVIVE - this is a sibling, not a replacement
#
# DISPATCH NOTE: no pipe (a piped dispatch was re-executed in a second terminal, two lanes on one
# tree lock, one session killed). The model's output lands at <log-prefix>-<model>.log, NOT in
# .ai/runs/<name>.log.

cd /var/www/html/applicationostest || exit 1
source tools/lane-model.sh

LANE_MODEL_CHAIN="deepseek-v4-flash,openai-codex/gpt-5.6-sol,openai-codex/gpt-5.6-terra"

PROMPT="$(cat <<'PROMPT_EOF'
Build a new view in the Ikabud repo at /var/www/html/applicationostest. PHP 8.5 local; production
MySQL 5.7 - no window functions, no CTEs, no JSON_TABLE, no CHECK. Local site http://baronledger.test,
tenant 207 holds a copy of the production DB. READ-ONLY on business data: render and resolve, never
write.

# YOUR CRITERION - run first, confirm 9 RED, make it exit 0
    php tools/acceptance-production-efficiency.php
The chair owns it. Do NOT weaken, reword or delete it. E6 must stay passing.

# WHAT TO BUILD
A PRODUCTION EFFICIENCY view - its own page, a peer of Health / Products / Forecast / Reports:
    route     /daily-ledger/admin/production-efficiency  -> a new handler
    template  a new modules/daily-ledger/admin/production-efficiency.disyl
    nav       an entry in templates/modules/daily-ledger/layouts/app.disyl, visible to the roles
              that may open it
It answers, aggregated for the selected period and scope: what was PRODUCED, what was SOLD, what was
WASTED (spoilage/unsellable), and what that means as an efficiency and a wastage rate.

# THE DATA IS ALREADY THERE - MEASURE IT, DO NOT INVENT IT
  dl_commissary_product_ledger.produced_qty, .wastage_qty   (migration 037)
  .remaining_qty is a GENERATED column - do NOT treat it as waste, see below
  .beg_qty, .actual_end_qty                                 (migration 064)
  the production invariant is already written in handlers.php:2896 as
      beg_qty + produced_qty - dispatched_qty - wastage_qty
  pullout / return reasons already exist as vocabulary (PulloutReturnReason): 'expired', 'spoilage'
  sales units come from dl_daily_ledger - the same integer quantity the product ranking already sums
Confirm the exact table and column names against the migrations before writing SQL, and prefer an
existing helper over a new query if one fits.

# THE THREE WAYS THIS VIEW COULD MISLEAD - the criterion enforces all three
1. WASTAGE RATE MUST DIVIDE BY PRODUCED. remaining_qty is generated and ALREADY subtracts wastage, so
   a rate taken against it overstates waste by everything still on the shelf. E3 reads the rendered
   percentage and checks it equals wasted/produced.
2. STATE THE MONEY BASIS. Money in this module is OFFICIAL ONLY - a provisional row contributes units
   but never an amount. Say so, or this view will disagree with every other figure in the module.
3. NAME THE UNIT. "Efficiency 82%" means nothing unless the reader knows both sides are pieces. All
   the quantities above are integers in one unit, so the ratio is real - say which unit.

# THE PRODUCTION-VS-PULLOUT FILTER (the owner asked for this explicitly)
Offer a filter that switches between production and pullout/spoilage/unsellable movements, so the
owner can see where the loss sits. Use the existing pullout reason vocabulary rather than inventing
categories. Keep it a GET control on the same page and carry the existing period/area/branch scope
through it.

# SCOPE AND ACCESS - do not make this the exception
- Inherit area/branch/date scoping the way the other views do: use dl_reportFilters() /
  dl_adminViewBranchIds(). The products view initially excluded the viewer role and had to be fixed;
  N3 requires a VIEWER to render this view without an authorisation refusal, because the owner
  reviews as a viewer. Gate it like the sibling views.
- Money and quantities stay official-only for amounts.

# HONESTY ABOUT THE RATIO
Report how you handle: produced but not yet sold (in stock, not waste), products produced with no
sales, and units sold that were produced in an earlier period. If a ratio is not meaningfully
computable for a row, show the explicit state rather than a zero that reads as failure. State the
period the figures cover.

# CONSTRAINTS
- Do NOT modify or remove /daily-ledger/admin/forecast or its template - this is a sibling. E6 checks.
- Do NOT add product or health content to this view, and do not add efficiency content to them.
- If you change a .disyl template: `php _lint_disyl.php` must stay 702/702. A bare `{` in CSS/JS is a
  DiSyL tag boundary and will swallow a closing block tag - wrap such blocks in a literal wrapper, and
  never write the wrapper's own tag name inside a comment, because the engine counts it as an opening.
  A chart must be clipped circular if it is a pie; a conic-gradient on a square renders as a fan.
- Do NOT weaken, disable, reword or delete an existing assertion to reach PASS.
- Every guard you add must be FALSIFIABLE, and show the mutation EFFECTIVE in that scenario.
- After committing: verify with `git show HEAD:<file> | grep <the-change>` that the change is in the
  COMMITTED BLOB - a previous commit omitted its template while the worktree looked correct.
- Run `php tests/daily-ledger/daily_ledger_overview_test.php` (expect 105/105) and
  `php tests/daily-ledger/business_health_view_test.php` (expect 15/15) and report both.

# REPORT
status: PASS|FAIL|PARTIAL|BLOCKED
acceptance_before / acceptance_after: the RED then GREEN output
view: route, handler, template, nav entry and which roles see it
data: the exact tables/columns you read, and any new SQL with the reason it was needed
ratios: how efficiency and wastage rate are computed, and what is shown when a row cannot support one
filter: the production-vs-pullout control and how it maps to the reason vocabulary
scope: how area/branch/date narrow it, and what a viewer sees
falsification: per guard, the mutation and the evidence it executed
regressions: both suites, read from their own output
changed: file:line each
unresolved:
PROMPT_EOF
)"

lane_model_run "$LANE_MODEL_CHAIN" "$PROMPT" /tmp/lane-production-efficiency
