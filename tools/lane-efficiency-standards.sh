#!/usr/bin/env bash
#
# Lane: efficiency-standards
#
# Owner: "use an industry standard formula to accurately compute data at products efficiency.
#         also, forecast must be there too"
#
# ACCEPTANCE CRITERION, measured RED by the chair BEFORE dispatch:
#   php tools/acceptance-efficiency-standards.php  ->  3 RED, exit 1
#     F1 RED  yield is absent, though the repo already has its formula and columns
#     F2 RED  sold/produced is labelled "efficiency" - that is SELL-THROUGH, not production efficiency
#     F3 RED  waste rate is present but shrinkage / unexplained loss is not
#     F4 PASS mass balance already stated - keep it
#     F5 PASS OEE must STAY unclaimed - no downtime/rate data exists
#     F6 PASS the forecast is already reachable - keep it
#     R  PASS official/provisional + pieces basis must survive
#
# NO PIPE on dispatch (a piped dispatch was re-executed in a second terminal: two lanes, one tree
# lock, one session killed). Model output lands at <log-prefix>-<model>.log, not .ai/runs/<name>.log.

cd /var/www/html/applicationostest || exit 1
source tools/lane-model.sh

LANE_MODEL_CHAIN="deepseek-v4-flash,openai-codex/gpt-5.6-sol,openai-codex/gpt-5.6-terra"

PROMPT="$(cat <<'PROMPT_EOF'
You are making the production-efficiency view use INDUSTRY-STANDARD formulas, in the Ikabud repo at
/var/www/html/applicationostest. PHP 8.5 local; production MySQL 5.7 - no window functions, no CTEs,
no JSON_TABLE, no CHECK. Local site http://baronledger.test, tenant 207. READ-ONLY on business data.

# YOUR CRITERION - run first, confirm 3 RED, make it exit 0
    php tools/acceptance-efficiency-standards.php
The chair owns it. Do NOT weaken, reword or delete it. F4, F5, F6 and R pass today and MUST stay
passing - F5 in particular forbids something, so "make it green" cannot mean adding it.

# WHAT IS WRONG WITH THE CURRENT FIGURES
reporting.php computes efficiency_pct = sold / produced * 100 (see :2175 and :2257) and the page
presents that as "Efficiency". sold/produced is a real and standard measure - but it is SELL-THROUGH
(demand conversion), NOT production efficiency. Production efficiency is YIELD: how much saleable
output came out of the input.

# THE STANDARD SET TO IMPLEMENT, WITH THIS REPO'S OWN BASIS
This schema already carries everything the standard formulas need - do not invent new data:
    migration 016 spells out the yield basis itself:
        "Theoretical yield = FLOOR(input_qty / batch_input_qty) * output_pieces_per_batch"
    dl_products.batch_input_qty, .output_pieces_per_batch, .output_unit_label
    input_qty is recorded per batch (migration 011, commissary production tables)
    dl_commissary_product_ledger: produced_qty, wastage_qty (037), dispatched_qty, and a GENERATED
        remaining_qty (064)
    sold units come from dl_daily_ledger, the same integer quantity the ranking sums

    Yield %         output / input * 100                     -> the production-efficiency measure (F1)
    Sell-through %  sold / produced * 100                   -> rename today's figure (F2)
    Waste rate %    wasted / produced * 100
    Shrinkage %     (produced - sold - wasted - remaining) / produced * 100   -> unexplained loss (F3)
    Mass balance    produced = sold + wasted + remaining + unaccounted        -> already present (F4)

State each formula on the page, next to its figure, with the unit basis. An owner cannot trust a
percentage whose derivation they cannot see, and that is the whole point of this request.

# WHAT YOU MUST NOT DO
- Do NOT claim OEE (Overall Equipment Effectiveness). It is the other industry-standard measure and it
  is NOT computable here: there is no downtime, run-rate or availability data in this schema. F5
  asserts its absence. If you think OEE belongs, say why in your report - do not add it.
- Do NOT present sell-through as efficiency anywhere in the UI. Both are useful; conflating them is
  what the owner asked you to fix.
- Do NOT remove the mass balance, the official/provisional basis, the pieces unit, the
  production-vs-pullout filter, or the coverage/honesty states the view already has.
- Do NOT touch /daily-ledger/admin/forecast or its template - it is a sibling view and F6 only
  requires it stays reachable.

# HONESTY REQUIREMENTS, the same ones this view already meets - keep meeting them
- A ratio that cannot be computed for a row must show an explicit state, never a zero that reads as
  failure. Sales exceeding production already has a coverage note; do not regress it.
- Where yield cannot be computed because a product has no batch_input_qty recorded, say so explicitly
  rather than defaulting to 100% or excluding the row silently. Report how many products that affects
  on tenant 207 - a yield that quietly covers half the catalogue is worse than a stated gap.
- State the period the figures cover.

# CONSTRAINTS
- If you change a .disyl template: `php _lint_disyl.php` must stay 703/703. A bare `{` in CSS/JS is a
  DiSyL tag boundary and will swallow a closing block tag - wrap such blocks in a literal wrapper, and
  never write the wrapper's own tag name inside a comment, the engine counts it as an opening.
- Do NOT weaken, disable, reword or delete an existing assertion to reach PASS.
- Every guard must be FALSIFIABLE, and show the mutation EFFECTIVE in that scenario.
- After committing: verify with `git show HEAD:<file> | grep <the-change>` that the change is in the
  COMMITTED BLOB.
- Run `php tests/daily-ledger/daily_ledger_overview_test.php` (expect 105/105),
  `php tests/daily-ledger/business_health_view_test.php` (expect 15/15) and
  `php tools/acceptance-production-efficiency.php` (expect 0 RED) and report all three.

# REPORT
status: PASS|FAIL|PARTIAL|BLOCKED
acceptance_before / acceptance_after: the RED then GREEN output
formulas: each measure, its exact formula, the columns it reads, and where it is stated on the page
yield_coverage: how many products on tenant 207 can and cannot produce a yield, and what the page
  says for those that cannot
renaming: how sell-through is now distinguished from yield in the UI
falsification: per guard, the mutation and the evidence it executed
regressions: the three commands above, read from their own output
changed: file:line each
unresolved:
PROMPT_EOF
)"

lane_model_run "$LANE_MODEL_CHAIN" "$PROMPT" /tmp/lane-efficiency-standards
