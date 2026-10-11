#!/usr/bin/env bash
#
# Lane: overview-suite-reanchor
#
# The Health view restructure (a44edb54) intentionally changed the overview's structure and left 22
# assertions in tests/daily-ledger/daily_ledger_overview_test.php red (82 pass / 22 fail). A red suite
# is not shippable, and it must be repaired by RE-ANCHORING the assertions to the new layout without
# losing what they guarded - never by deleting them to reach green.
#
# ACCEPTANCE CRITERION (measured by the chair on the current tree):
#   the suite is 0 fail AND its assertion COUNT is >= the count it has today.
#   Count today: 82 pass + 22 fail = 104 assertions. Deleting tests to pass is therefore detectable.
#   Run: php tests/daily-ledger/daily_ledger_overview_test.php
#        then read test_results/daily-ledger-overview.json and compare pass+fail against 104.

cd /var/www/html/applicationostest || exit 1
source tools/lane-model.sh

LANE_MODEL_CHAIN="deepseek-v4-flash,openai-codex/gpt-5.6-sol,openai-codex/gpt-5.6-terra"

PROMPT="$(cat <<'PROMPT_EOF'
You are repairing a test suite in the Ikabud repo at /var/www/html/applicationostest after an
intentional restructure. PHP 8.5 local; production MySQL 5.7 (INSPECTION-ONLY claims).
Read-only against the local test DB copy (baronledger.test).

# THE SITUATION
Commit a44edb54 rebuilt /daily-ledger/admin/overview as a Business Health view: 480 lines removed,
the product blocks moved to a separate Products view, the filter bar reduced from 20 controls to 4,
and a health verdict added. tests/daily-ledger/daily_ledger_overview_test.php is now
**82 pass / 22 fail**. The chair classified the failures. Your job is to re-anchor them.

DO NOT make it green by deleting or weakening assertions. The criterion includes an assertion-count
floor precisely to make that detectable, and a review lane will look for it.

# THE 22 FAILURES, CLASSIFIED BY THE CHAIR - verify each classification yourself
MOSTLY STALE STRUCTURE (the assertion names old markup that legitimately no longer exists; re-anchor
to the new structure, PRESERVING what it actually guarded):
  - handler calls the per-branch helper once (no per-branch loop)
  - viewer/admin/supervisor/auditor renders the read-only overview analytics
  - viewer page shows the per-branch net value
  - viewer page shows the amount-ranked per-branch product
  - ranked table caption reflects the selected direction
  - descending renders the strongest product before the weakest
  - ascending renders the weakest product before the strongest
  - overview renders dependency-free bar charts
  - ranked tables render inline row bars
  - 0% net renders the configuration hint
  - no-sales product is absent from the overview and the 80/20 rule
  - monthly projection omits a product with zero units sold
  - the pending filter defaults on without claiming a filter was applied
  - applying the filter shows the cue for whichever state is active
  - the checkbox value wins when the hidden companion field is also submitted

BEHAVIOURAL - DO NOT ASSUME STALE. For each, FIRST establish whether the behaviour still exists in the
new page. If it is present, re-anchor the assertion. If it was genuinely LOST, that is a DEFECT: report
it and do not silently drop the assertion.
  - branch filter retains All Branches plus every authorized branch
  - inaccessible branch render shows the empty analytics state
       (this is the fail-closed rule for an explicitly requested INACCESSIBLE branch - it must still
        fail closed rather than silently widening; dl_reportFilters() documents preserving it)
  - a completed row is counted whatever its ending value
       (a counting rule: the value of the ending must not change whether the row counts)
The pending_rows trio is about the filter control that RELOCATED into its section. The control still
exists - the chair verified forecast_window, forecast_period, sort_by, sort_dir, chart_limit and
pending_rows are all still in the template. Re-anchor, do not delete.

# ALSO ADD (a gap the chair found in its own criterion)
The chair's acceptance criterion counts controls in the primary bar, so it would pass by DELETING the
relocated controls rather than moving them. Add an assertion that each relocated control
(pending_rows, forecast_window, sort_by, sort_dir, chart_limit) is still PRESENT and reachable in its
section. Then show it is falsifiable: remove one from the template, show red, restore, show green.

# HOW TO WORK
- Reproduce each failure and READ what it actually guarded before changing it. An assertion's NAME is
  not its meaning; several were named for old markup but guarded real behaviour.
- Prefer re-anchoring to literal new markup. If an assertion cannot be re-anchored without guessing,
  say so rather than inventing an equivalent.
- Every assertion you add or re-anchor must be shown FALSIFIABLE, with the mutation shown EFFECTIVE in
  that scenario (print the changed value or assert the code path was reached). A mutation that does not
  execute the changed branch proves nothing.
- Do NOT weaken, disable, reword to pass, or delete. If an oracle disagrees with correct code, decide
  which side is stale (git log -S the assertion text, then git log -1) and report it.
- MySQL 5.7 safe. Do not touch the application code unless a DEFECT is proven - this lane repairs
  tests. If you find a defect, report it; do not fix it here.
- After committing, verify with `git show HEAD:<file> | grep <the-change>` that your change is in the
  COMMITTED BLOB, and diff the commit's file list against `git status`. A previous commit in this repo
  omitted a file and the fix silently vanished from history while the worktree looked correct.

# REPORT
status: PASS|FAIL|PARTIAL|BLOCKED
before / after: the pass+fail counts, and confirmation the total is >= 104
classification_check: for each of the 22, what you found and what you did
behavioural_four: for each of the four behavioural ones - still present, or DEFECT (with evidence)
relocated_controls_assertion: the new assertion and its demonstrated red mutation
falsification: mutation per re-anchored assertion, with evidence it executed
defects_found: anything the restructure genuinely lost, NOT fixed here, for a separate lane
changed: file:line each
unresolved:
PROMPT_EOF
)"

lane_model_run "$LANE_MODEL_CHAIN" "$PROMPT" /tmp/lane-overview-suite-reanchor
