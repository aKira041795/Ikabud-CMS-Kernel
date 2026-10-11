#!/usr/bin/env bash
#
# Lane: business-health-view
#
# Owner-approved 2026-10-11. Builds the Business HEALTH view for the business owner's own five
# questions. First piece of the approved three-view split (Health / Products / Reports); the Products
# view follows in a later lane, so this lane must NOT build it - it must only take the product blocks
# OFF this page and leave them reachable.
#
# ACCEPTANCE CRITERION, written and MEASURED RED BY THE CHAIR BEFORE THIS DISPATCH:
#
#   php tools/acceptance-business-health-view.php      ->  6 RED, exit 1
#
#   A1 verdict=NO           no health verdict, only numbers
#   A2 verdict absent       branch names appear but nothing is attributed TO a verdict
#   A3 no opportunity       nothing says where to improve
#   A4 no affordance        nothing navigates to the detail
#   A7 20 form controls     the owner's own URL carried 11 parameters
#   A8 3 product sections   product blocks still on this page
#   A5 PASS                 regression guard: pending must not read as "no sales"
#
# Sol, because this is design reasoning over business semantics rather than volume implementation.

cd /var/www/html/applicationostest || exit 1
source tools/lane-model.sh

LANE_MODEL_CHAIN="openai-codex/gpt-5.6-sol,deepseek-v4-flash,openai-codex/gpt-5.6-terra"

PROMPT="$(cat <<'PROMPT_EOF'
You are building the Business HEALTH view in the Ikabud repo at /var/www/html/applicationostest.
PHP 8.5 local; production is MySQL 5.7 (label any 5.7 claim INSPECTION-ONLY). Local site:
http://baronledger.test against a copy of the production DB; viewer account admin_view / viewer123.
Read-only: render and resolve, never write business data.

# YOUR ACCEPTANCE CRITERION - run it FIRST, confirm 6 RED, make it exit 0
    php tools/acceptance-business-health-view.php
Owned by the chair. Do NOT weaken it, rewrite it to pass, or delete it. If you believe a check is
wrong, say so with evidence in your report and leave the file intact. Note A5 passes today and must
STAY passing - it is the regression guard for work completed this morning.

# THE OWNER'S BRIEF - this is the page's job, verbatim
    "as a business owner, i am wanting to know how the business is doing. is it healthy, why?
     where can i maximize operations? how?"
and on presentation:
    "i want this as intuitive and as informative to a business owner but not necessarily complicated
     to use. filterable to granular data analysis"

Every block must earn its place by answering one of those five, in that order. Read
docs/kernel/api-reference.md:1082-1084 for the viewer role's intended remit.

# THE BASELINE PROBLEM - DO NOT FABRICATE HEALTH
There is NO target/budget/quota/plan anywhere in the module (checked; the "target" hits are all
target_branch_id). So "healthy" CANNOT mean "vs plan". The honest definition, using only what the
system already computes:
  - VARIANCE: dl_variance, dl_variance_flags, dl_delivery_variance_flags (already computed)
  - COMPLETENESS: pending vs finalized endings
  - DAY STATUS: open/closed per branch per date
  - TREND: period-over-period from the ledger history
State the basis ON THE PAGE. If a threshold is chosen, it is an assumption and must be visible as one.
A verdict with no stated basis fails A1.

# MEASURED FACTS you must handle correctly
- TODAY 2026-10-11: 103 ledger rows, 103 PENDING endings, 0 completed.
- The page currently renders "Units Sold 0 units" and "Gross Sales PHP 0.00" in its KPI cards while a
  line BELOW says "Reporting is pending for this period". The page contradicts itself: the headline
  reads as a business decline while the detail says reporting is unfinished. The KPIs must not present
  unfinished reporting as zero.
- 14-day window: ALL activity is in area_id = 2 (5355 rows). Areas 1, 3, 4, 5 have 0 rows across 12
  active branches. So 12 of 21 active branches recorded NOTHING. "Where can I maximise operations"
  must distinguish a branch that recorded nothing from a branch that recorded little - they are
  different facts and the page must not present silence as underperformance.
- A naive rank across all branches compares 9 working branches against 12 silent ones. State the
  comparison basis.

# DESIGN CONTRACT (owner-approved)
- NOT COMPLICATED: the primary filter bar holds exactly THREE things - Area (parent), Branch (child),
  period - plus the submit. Every other control RELOCATES into the section it affects (forecast window
  into Forecast; sort/limit into the list they order). Relocate, do NOT delete: the owner also asked
  for "filterable to granular data analysis", so removing capability fails the brief as surely as
  cluttering the bar.
- AREA IS THE PARENT: Branch options are only the selected area's branches, and an invalid Branch
  resets to All (already fixed and guarded - do not regress it).
- DRILL-DOWN ENDS AT BRANCH on this view: business -> area -> branch, filter context preserved.
  PRODUCT detail is a SEPARATE view that is NOT being built in this lane. Take the product blocks
  (top products, per-branch top products, Pareto) OFF this page and leave them reachable - by nav
  entry, a link, or the existing Reports surface - so nothing becomes unreachable. Do not delete the
  data path; do not build the Products view.
- The aggregate business forecast stays here; the per-product forecast belongs to Products.

# HOW TO WORK
- Reproduce before fixing. Prefer the smallest correct change and reuse existing helpers.
- MySQL 5.7: no window functions, no CTEs, no JSON_TABLE, no CHECK. InnoDB + utf8mb4.
- Do NOT weaken, disable, reword or delete an existing assertion to reach PASS. If an oracle
  disagrees, identify which side is stale (git log -S, then git log -1) and report it.
- Every guard you add must be shown FALSIFIABLE, and the mutation must be shown EFFECTIVE in that
  scenario - print the changed value or assert the code path was reached. A mutation that does not
  execute the changed branch proves nothing about the guard. This trap has bitten twice in this repo.
- `git add` the WHOLE change (templates included) and after committing verify with
  `git show HEAD:<file> | grep <the-change>` that the fix is in the COMMITTED BLOB, then diff the
  commit's file list against `git status`. A previous chair commit omitted the template and the fix
  silently vanished from history while the worktree looked correct.

# REPORT
status: PASS|FAIL|PARTIAL|BLOCKED
acceptance_before / acceptance_after: the RED and then GREEN output of the criterion
health_definition: exactly what signals produce the verdict, how they combine, and what is shown as an assumption
drilldown: how business -> area -> branch works and that context is preserved
relocated: every control moved and where it now lives
product_blocks: where each removed product block remains reachable
a5_still_passing: confirmation the pending-vs-no-sales guard still passes
falsification: the mutation per new guard, with evidence that it executed
changed: file:line each
verification: suites read from test_results/*.json, plus the cold-cache overview run
unresolved:
PROMPT_EOF
)"

lane_model_run "$LANE_MODEL_CHAIN" "$PROMPT" /tmp/lane-business-health-view
