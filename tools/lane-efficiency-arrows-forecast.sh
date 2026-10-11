#!/usr/bin/env bash
#
# Lane: efficiency-arrows-and-spoilage-forecast
#
# Owner:
#   "arrow color, if up, green, down red" then "thet's at efficiency"
#   "if ineeficiency/wastage goes up, it's red, it it's reduced, green"
#   "set the forecast per product based on efficiency (spoilage rate). forecast is for the business to
#    have an idea how much to produce with spoilage rate considered against sales"
#
# ACCEPTANCE CRITERION, measured RED on the STABLE tree (standards lane finished):
#   php tools/acceptance-efficiency-arrow-and-spoilage-forecast.php  ->  4 RED, exit 1
#     T3  RED  no per-metric statement of which direction is an improvement
#     T3b RED  no evidence a rising wastage rate renders red
#     T5  RED  the forecast states no per-product spoilage rate
#     T6  RED  no gross-up: a flat safety rate is not a spoilage rate
#     T1/T2/T4/T7/R PASS - must stay passing
#
# NO PIPE on dispatch. Model output lands at <log-prefix>-<model>.log, not .ai/runs/<name>.log.

cd /var/www/html/applicationostest || exit 1
source tools/lane-model.sh

LANE_MODEL_CHAIN="deepseek-v4-flash,openai-codex/gpt-5.6-sol,openai-codex/gpt-5.6-terra"

PROMPT="$(cat <<'PROMPT_EOF'
Two related changes in the Ikabud repo at /var/www/html/applicationostest. PHP 8.5 local; production
MySQL 5.7 - no window functions, no CTEs, no JSON_TABLE, no CHECK. Local site http://baronledger.test,
tenant 207. READ-ONLY on business data.

# YOUR CRITERION - run first, confirm 4 RED, make it exit 0
    php tools/acceptance-efficiency-arrow-and-spoilage-forecast.php
The chair owns it. Do NOT weaken, reword or delete it. T1, T2, T4, T7 and R pass today and MUST stay
passing. Also keep `php tools/acceptance-efficiency-standards.php` at 0 RED.

# PART 1 - THE DIRECTION COLOUR, PER METRIC (T3, T3b)
The owner's two rules are OPPOSITE, and both are right, because on this one page the direction of
"good" differs by metric:

    gain metrics   yield %, sell-through %   up is good   -> green up,  red down
    loss metrics   wastage rate, residual %  up is BAD    -> red up,    green down

So do BOTH of these:
  a) State, on the page, which direction is an improvement for each side. Not one shared sentence -
     the criterion checks that the two statements exist and are different.
  b) Colour the direction indicators accordingly, so a RISING wastage rate renders red and a FALLING
     one renders green, while rising yield renders green and falling yield renders red.

The failure this must not have: a naive "up = green" paints the worst news on the page (rising waste)
green. Update the arrow/direction markers per metric; do not apply one colour rule globally.

The view also carries red "exceeds" WARNINGS (yield_exceeds, sell_through_exceeds). Those are not
direction indicators - they mean "check this". Keep them, and keep them distinguishable (T4).

# PART 2 - THE FORECAST GROSSES UP FOR SPOILAGE (T5, T6)
Measured defect: reporting.php dl_forecastDemand(..., $safetyRate = 0.10) applies ONE flat 10% rate to
every product. That is a margin, not spoilage. A product spoiling 20% must be produced at sales/0.8,
so a flat 10% systematically UNDER-PRODUCES every product that spoils worse than 10% - which is the
failure this request exists to fix.

Use the standard gross-up for expected loss, per product:
    target production = expected sales / (1 - spoilage_rate)
where spoilage_rate is that product's own wastage/produced, already computed for the efficiency view.

REUSE the existing spoilage rate; do not introduce a second definition of spoilage. Show the rate and
the resulting target per product, so an owner can see why the number is what it is - an unstated
gross-up is indistinguishable from a guess.

Products with no measurable spoilage rate must be STATED, not silently defaulted to 0% or 10%. A rate
invented per product is worse than a stated gap. Say how many products on tenant 207 that affects.

The forecast page already says "Forecasts are suggestions only. This page never writes." Keep that.

# CONSTRAINTS
- If you change a .disyl template: `php _lint_disyl.php` must stay valid. A bare `{` in CSS/JS is a
  DiSyL tag boundary and will swallow a closing block tag - wrap such blocks in a literal wrapper, and
  never write the wrapper's own tag name inside a comment, the engine counts it as an opening.
- Do NOT weaken, disable, reword or delete an existing assertion to reach PASS.
- Every guard must be FALSIFIABLE, and show the mutation EFFECTIVE in that scenario.
- After committing: verify with `git show HEAD:<file> | grep <the-change>` that the change is in the
  COMMITTED BLOB.
- Run and report: `php tests/daily-ledger/daily_ledger_overview_test.php` (expect 105/105),
  `php tests/daily-ledger/business_health_view_test.php` (expect 15/15),
  `php tools/acceptance-production-efficiency.php` and `php tools/acceptance-efficiency-standards.php`
  (both expect 0 RED).

# REPORT
status: PASS|FAIL|PARTIAL|BLOCKED
acceptance_before / acceptance_after: the RED then GREEN output
direction: which metrics got which colour rule, and where each is stated on the page
grossup: the exact per-product formula, how the spoilage rate is sourced, and where it is shown
coverage: how many products have and lack a rate, and what the page says for those that lack one
falsification: per guard, the mutation and the evidence it executed
regressions: the four commands above, read from their own output
changed: file:line each
unresolved:
PROMPT_EOF
)"

lane_model_run "$LANE_MODEL_CHAIN" "$PROMPT" /tmp/lane-efficiency-arrows-forecast
