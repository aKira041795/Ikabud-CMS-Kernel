#!/usr/bin/env bash
#
# Lane: viewer-area-scope-and-overview-ux
#
# Owner request (2026-10-11): "improve the viewer page view. add the area filter there. also, discuss
# with chatgpt and have sol review, how to better present the business data that's set in the view.
# UI/UX"
#
# Two jobs, in this order. Job 1 is a BUG with a measurement attached. Job 2 is a design review whose
# input is the chair's verdict (already obtained, quoted in the prompt).
#
# The chair measured these and you should re-measure rather than trust the summary.

cd /var/www/html/applicationostest || exit 1
source tools/lane-model.sh

LANE_MODEL_CHAIN="openai-codex/gpt-5.6-sol,deepseek-v4-flash,openai-codex/gpt-5.6-terra"

PROMPT="$(cat <<'PROMPT_EOF'
You are working in the Ikabud repo at /var/www/html/applicationostest. PHP 8.5 local; production is
MySQL 5.7 (label any 5.7 claim INSPECTION-ONLY). A local site runs at http://baronledger.test with
a copy of the production DB; a viewer account is admin_view / viewer123.

# JOB 1 - FINISH THE AREA SCOPE FOR THE viewer ROLE (this is a real, measured defect)

The viewer's landing page is /daily-ledger/admin/overview (their whole product; their nav is
Overview + Reports only). They now get the Area filter, but IT DOES NOT YET SCOPE THE PAGE:

  MEASURED, headless, read-only:
    ?scope=ALL     -> area picker "All areas", branch dropdown 22 options, table 113 rows, first 810.75
    ?scope=AREA:2  -> area picker "Dipolog",  branch dropdown 10 options, table 113 rows, first 810.75
    => the dropdown narrows and the DATA DOES NOT CHANGE. A control that lies.
    For contrast the PRE-EXISTING Branch dropdown DOES scope it:
    ?scope=ALL&branch_id=8 -> table 102 rows, first 725.93  (so the table IS branch-scoped)

Where the scope is decided: the question "may this role use the persisted area scope?" was answered
in THREE independent places. All three now call AdminAreaScope::appliesTo() (commit b5f5e2a1):

  modules/daily-ledger/helpers/admin-area-scope.php  AdminAreaScope::resolve()          fixed
  modules/daily-ledger/helpers/admin-area-scope.php  dl_adminViewBranchIds()            fixed
  modules/daily-ledger/helpers/reporting.php         dl_reportFilters()                 fixed

Verified after that commit:
  dl_adminViewBranchIds(viewer, scope=AREA:2) -> 9 branches [8,9,10,11,12,15,16,18,19]
  dl_reportFilters(['scope'=>'AREA:2'], viewer) -> accessible_branch_ids = those same 9
  module()->input() DOES carry scope (input()['scope'] = 'AREA:2'), so the handler is not blind.

Yet the RENDERED overview is unchanged. So at least one more consumer ignores the scope. FIND IT.
Line up the overview's own data path (handleAdminOverview, handlers.php ~12440; it builds its own
$filters array inline around relative lines 72-78 and calls helpers in helpers/reporting.php such as
dl_overviewProductTotals :1291, dl_overviewBranchProductTotals :1352, dl_overviewForecastRows :1726)
and fix EVERY block so the viewer's Area scope changes it. Assert OUTCOMES (the rendered table
changes), never the mechanism.

Then make it impossible to miss the next one: assert that every consumer of the area scope agrees -
one helper, one predicate, and a test that fails if a consumer hard-codes a role again.

# JOB 2 - FALSIFY OR RETRACT A REPAIRED ASSERTION

tests/daily-ledger/daily_ledger_commissary_default_source_test.php used to assert the PREMISE "no
commissary lies in area 2". That is false: RIZAL-COMMIS1's area_id IS 2 (Dipolog). It passed only
while the scope was ignored for the viewer role, leaving the area set empty. It now asserts the
behaviour instead: a commissary OUTSIDE the selected area must still be offered.
I could NOT falsify it - the mutation I tried left it green, so its falsifier is UNPROVEN. Supply a
mutation that turns it red and show the output, or report it as decorative with the evidence. Do not
claim falsifiability without showing the red.

# JOB 3 - REVIEW THE CHAIR'S INFORMATION-ARCHITECTURE VERDICT (owner asked for exactly this)

The chair (ChatGPT, repository-grounded) returned this verdict on how to present the overview's
business data to a READ-ONLY viewer. Its words, condensed:

  "Rebuild the Overview around business health, not data exploration. The viewer is a business owner,
  not an analyst. The current page's 10 cards, two tables, multiple charts and five control dimensions
  force the owner to do the analysis themselves."
  - Above the fold: reporting period / last updated / DATA COMPLETENESS, then Area+Branch, then THREE
    KPIs (total sales, units sold, reporting coverage "N of M"), then ONE area/branch ranking chart.
  - Below the fold: top 5 products (with revenue + % contribution), one aggregate forecast, and
    expandable detail. Aim for 3 KPIs, one comparison, two secondary sections - not ten co-equal cards.
  - Filter contract: Area is the PARENT, Branch the DEPENDENT child. Changing Area must reset Branch if
    its selection is not in the new area, and Branch options must be only that area's branches. Never
    allow an inconsistent pair. Area=All+Branch=All shows area-level comparison as the DEFAULT view.
  - REMOVE from the primary filter bar: Sort direction and Chart limit (implementation details - a
    business owner should not pick asc/desc to find their best performers). Move Forecast period INTO
    the forecast section; it is not the historical reporting period.
  - CRITICAL SEMANTIC GUARD: distinguish zero sales vs no report submitted vs pending finalization.
    Repo evidence: docs/daily-ledger/unfinalized-shift-sales-semantics.md:60-63. "The Overview must not
    silently turn incomplete activity into a business decline." NOTE the page currently prints "No
    sales recorded for this period" against this production copy - check whether that is true, wrong,
    or a completeness problem, and say which.
  - It explicitly stated an EVIDENCE BOUNDARY: it could not see the Overview's query construction, the
    template beyond lines 10-11, the branch-to-area schema, or the forecast aggregation. VERIFY ITS
    CLAIMS against the code before adopting them - a chair opinion is advice, not evidence.

Deliver: (a) the bug fix for Job 1 with the before/after measurement; (b) the falsifier for Job 2 or a
retraction; (c) for Job 3, a REVIEW - agree/disagree with the chair per point, correct anything it got
wrong about this codebase, and give a concrete implementation plan ordered by value, with what you
would cut. Do NOT implement the redesign in this lane; the owner wants the review first.

# HARD CONSTRAINTS
- Read-only against the local test DB copy and the live site. Do NOT write business data anywhere.
- MySQL 5.7: no window functions, no CTEs, no JSON_TABLE, no CHECK. InnoDB + utf8mb4.
- Do NOT weaken, disable or reword an existing assertion to reach PASS. If an oracle disagrees, decide
  which side is stale (git log -S the assertion, then git log -1 the commit) and report it.
- Every guard you add must be shown FALSIFIABLE: mutate, show red, restore, show green, and report the
  mutation. A guard that cannot go red is decorative and must be reported as such.
- Report BLOCKED with the precise reason rather than improvising.

# REPORT
status: PASS|FAIL|PARTIAL|BLOCKED
job1: root cause file:line, the fix, before/after measurement of the RENDERED table
job1_consumers: every consumer of the area scope and whether it agrees
job2: the mutation that turns the repaired assertion red, with output - or a retraction
job3_review: point-by-point agree/disagree with the chair, corrections grounded in code, ordered plan
changed: file:line each
verification: suites as passed/failed from test_results/*.json, not grep
unresolved:
PROMPT_EOF
)"

lane_model_run "$LANE_MODEL_CHAIN" "$PROMPT" /tmp/lane-viewer-area-scope-overview-ux
