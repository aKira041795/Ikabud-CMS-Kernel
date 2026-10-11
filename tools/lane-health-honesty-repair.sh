#!/usr/bin/env bash
#
# Lane: health-honesty-repair
#
# Repairs the four high findings from Sol's adversarial review.
# Brief material, both committed:
#   docs/reviews/sol-health-review-2026-10-11.md   Sol's report - the evidence and the fixes
#   tools/acceptance-health-honesty-repair.php     the criterion - 4 RED on the tree this lane starts from
#
# ACCEPTANCE CRITERION, measured RED by the chair BEFORE dispatch:
#   php tools/acceptance-health-honesty-repair.php  ->  4 RED, exit 1
#     S1 RED  trend arrows against an empty/pending baseline (all three reported cases)
#     S2 RED  an empty day renders "PHP 0.00 official sales" instead of a no-sales state
#     S3 RED  the net-sales assertion body uses $branchSummaryHtml; the 0%-hint body never
#             touches $zeroNetHtml
#     S4 RED  ascending mode still says "biggest contributor down to least"
#     R  PASS both views still render - must stay passing
#
# DISPATCH: no pipe. Piping a lane dispatch makes the tool re-execute it in a second terminal, which
# cost a full dispatch on 2026-10-11 (two lanes, one run name, one tree lock, one session killed).
# The model's own output lands at <log-prefix>-<model>.log, NOT in .ai/runs/<name>.log.

cd /var/www/html/applicationostest || exit 1
source tools/lane-model.sh

LANE_MODEL_CHAIN="deepseek-v4-flash,openai-codex/gpt-5.6-sol,openai-codex/gpt-5.6-terra"

PROMPT="$(cat <<'PROMPT_EOF'
You are repairing four high-severity findings from an adversarial review, in the Ikabud repo at
/var/www/html/applicationostest. PHP 8.5 local; production MySQL 5.7 (no window functions, no CTEs,
no JSON_TABLE, no CHECK). Local site http://baronledger.test, tenant 207 holds a production DB copy.
READ-ONLY on business data - render and resolve, never write.

# READ BOTH OF THESE FIRST
    docs/reviews/sol-health-review-2026-10-11.md    the review: evidence, impact and a fix per finding
    tools/acceptance-health-honesty-repair.php      your criterion, 4 RED

# YOUR CRITERION - run first, confirm 4 RED, make it exit 0
    php tools/acceptance-health-honesty-repair.php
The chair owns it. Do NOT weaken, reword or delete it. R passes today and must stay passing.

# THE FOUR FINDINGS

## S1 - TREND ARROWS ARE FABRICATED (the most serious)
handlers.php: $trendComparable = !$reportingPending tests only whether the CURRENT period is
entirely pending. Consequences, all reproduced by the criterion:
  - 2026-08-15 against an EMPTY 2026-08-14 renders an up arrow with PHP 0.00 as the previous period
  - a no-row day against a finalized day renders -100.0%, on a page that elsewhere calls such a day
    "silence, not underperformance"
  - 2026-10-10 has 28 pending rows and still renders a percentage as real performance
Fix per the review: require MEANINGFUL FINALIZED data AND zero pending rows in BOTH the current and
the prior period before any arrow or percentage is shown. Otherwise render an explicit state - "No
baseline", "No sales recorded", "Pending finalization". Never compute a percentage against a zero or
unfinished baseline. Note the page can be internally inconsistent today: it must not claim a
performance trend on a day it also describes as silence.

## S2 - THE THREE STATES ARE NOT DISTINCT
2026-10-11 says "Awaiting finalization" yet its pending branch row still renders PHP 0.00; an empty
2026-08-16 renders "0 units, PHP 0.00 official sales" rather than a no-sales state. Derive an explicit
three-way state from the total/completed/pending counts: no rows at all -> "No sales recorded";
rows but none finalized -> "Awaiting finalization" and do NOT print a peso figure as if it were sales;
finalized present -> the numeric figure. The pending row must not show PHP 0.00 as though it were a
measured zero.

## S3 - ASSERTIONS EXAMINE THE WRONG SURFACE
In tests/daily-ledger/daily_ledger_overview_test.php the assertion named "viewer page shows the
configured net sales..." inspects $branchSummaryHtml rather than the viewer/Health surface it names,
and the "0% net renders the configuration hint" assertion never inspects $zeroNetHtml - its own
comment admits the hint was removed. Fix the assertions so each examines the HTML it claims to
examine. If net-sales guidance genuinely lives only in Branch Consolidated Summary, then assert THAT
report renders the label/value and the 0% state, and make Health say where that number lives. A test
whose name overstates what it checks is the defect; do not rename it to match the weaker check.

## S4 - ASCENDING MODE MISDESCRIBES ITSELF
Reversing the cumulative direction recomputes correctly (first row the least, last reaching 100%), but
the card heading still reads "biggest contributor down to least" and the explanation still calls the
first row crossing 80% the Pareto driver line. In ascending order that crossing is the accumulated
long tail - nearly the whole catalogue - not the products driving 80% of sales. Make the heading and
the threshold explanation direction-dependent: in ascending mode describe it as long-tail
accumulation and do not label that crossing as the driver line. Keep the descending wording as it is.

# CONSTRAINTS
- Smallest correct change per finding. Do not redesign the views.
- Do NOT weaken, disable, reword or delete an existing assertion to reach PASS. If an oracle
  disagrees with correct code, identify which side is stale (git log -S, then git log -1) and report.
- Every guard must be FALSIFIABLE, and show the mutation EFFECTIVE in that scenario. A mutation that
  does not execute the changed branch proves nothing - this has bitten twice in this repo.
- If you change a .disyl template: `php _lint_disyl.php` must stay 702/702. A bare `{` in CSS/JS is a
  DiSyL tag boundary and will swallow a closing block tag; wrap such blocks in a literal wrapper, and
  do not write the wrapper's own tag name inside a comment - the engine counts it as a real opening.
- After committing: verify with `git show HEAD:<file> | grep <the-change>` that the fix is in the
  COMMITTED BLOB. A previous commit omitted its template while the worktree looked correct.
- Run `php tests/daily-ledger/daily_ledger_overview_test.php` and
  `php tests/daily-ledger/business_health_view_test.php` and report both counts.

# REPORT
status: PASS|FAIL|PARTIAL|BLOCKED
acceptance_before / acceptance_after: the RED then GREEN output
per finding: S1..S4 - what changed, file:line, and the render that proves the new behaviour
falsification: per guard, the mutation and the evidence it executed
regressions: both test suites, read from their own output
changed: file:line each
unresolved:
PROMPT_EOF
)"

lane_model_run "$LANE_MODEL_CHAIN" "$PROMPT" /tmp/lane-health-honesty-repair
