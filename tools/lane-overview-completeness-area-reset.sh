#!/usr/bin/env bash
#
# Lane: overview-completeness-and-area-branch-reset
#
# Chair-dispatched 2026-10-11. Two decidable defects plus two test-integrity repairs. The acceptance
# criterion was BUILT AND MEASURED RED ON THE UNCHANGED TREE before this dispatch:
#
#   php tools/acceptance-daily-ledger-overview.php   ->  2 RED, exit 1
#
#   [A] today=2026-10-11 rows=103 pending=103 completed=0 | page says "No sales recorded": YES
#   [B] branch 13 is in area 1 but not area 2 | after scope=AREA:2 the filter still carries
#       branch_id=13 and resolves 9 branches   (9 accessible branches, but the explicit stale
#       branch_id predicate matches none of them, so the page renders nothing)
#
# Model chain is flash-first: this is implementation and test repair at a settled design, which is
# DeepSeek's lane by the repo's role split. Sol reviews the diff afterwards.

cd /var/www/html/applicationostest || exit 1
source tools/lane-model.sh

LANE_MODEL_CHAIN="deepseek-v4-flash,openai-codex/gpt-5.6-sol,openai-codex/gpt-5.6-terra"

PROMPT="$(cat <<'PROMPT_EOF'
You are implementing four bounded items in the Ikabud repo at /var/www/html/applicationostest.
PHP 8.5 local; production is MySQL 5.7 (label any 5.7 claim INSPECTION-ONLY). A local site runs at
http://baronledger.test against a copy of the production DB. A viewer account exists: admin_view /
viewer123. DO NOT write business data anywhere - render and resolve only.

# YOUR ACCEPTANCE CRITERION (run it FIRST, confirm it is RED, and make it GREEN)
    php tools/acceptance-daily-ledger-overview.php        # currently: 2 RED, exit 1
It is written and owned by the chair. Do not weaken it, rewrite it to pass, or delete it. If you
believe a check is wrong, say so in your report with evidence and leave the file intact.

# ITEM A - COMPLETENESS MUST NOT READ AS A BUSINESS DECLINE
Measured today (2026-10-11): 103 ledger rows, 103 pending endings, 0 completed rows. The Business
Overview says "No sales recorded for this period." That is false and it is the most damaging way for
a reporting page to be wrong: unfinished reporting reading as "the business did nothing".
Note from the probe: the page ALREADY mentions pending/incomplete somewhere
(`page mentions pending/incomplete: yes`), so this is an INCONSISTENCY between two parts of the same
page, not missing plumbing. Find where each statement comes from and make them agree.
Required behaviour:
  - activity with no finalized ending  -> say reporting is PENDING / not yet finalized, and say how
    many rows are pending, rather than implying zero sales
  - genuinely no activity            -> the existing no-sales message is correct
  - finalized activity               -> unchanged
Keep the wording plain and factual; do not invent a new visual language. Precedent for the semantics:
docs/daily-ledger/unfinalized-shift-sales-semantics.md:60-63.

# ITEM B - A BRANCH INVALIDATED BY AN AREA CHANGE MUST RESET, NOT EMPTY
Branch 13 belongs to area 1 and not area 2. Selecting branch 13 and then switching Area to area 2
leaves `branch_id = 13` in the filter while the accessible set is area 2's 9 branches, so the
explicit-branch predicate matches nothing and the page renders empty. Sol found this; it is safe but
useless, and it violates the Area->Branch contract (Area is the parent, Branch the dependent child).
Required: normalize SERVER-SIDE (client-side reset is a nicety, not the fix) so an explicit branch_id
outside the effective scope resets to All Branches instead of resolving to nothing. Keep the existing
deny-by-default rule intact: an explicitly requested INACCESSIBLE branch must still fail closed rather
than silently widening - see dl_reportFilters()'s comment about preserving an inaccessible branch.

# ITEM C - A FLAKY GUARD, WHICH IS HOW REAL FAILURES GET IGNORED
tests/daily-ledger/daily_ledger_overview_test.php asserts app.log is untouched during the run, but
DiSyL writes an info `disyl.render.breakdown` line when it COMPILES a template. So the suite fails on
the FIRST run after any template change and passes afterwards. Measured: 1 fail, then 0, 0. Make the
assertion tolerate the cold-compile diagnostic while still catching genuine unexpected writes - do not
simply delete the check. Acceptance: clear storage/cache for the compiled templates, run the suite,
0 failures; and show that a genuinely unexpected log line would still fail it.

# ITEM D - A DECORATIVE ASSERTION I ALREADY RETRACTED
In tests/daily-ledger/daily_ledger_commissary_default_source_test.php the assertion "the selector lists
every active commissary, including ones outside the selected area" does NOT go red when the selector is
narrowed, because it queries all commissaries independently instead of observing the handler's rendered
selector. Sol reproduced that against the real selector query. Rewrite it so it observes RENDERED
output (the pattern in tests/daily-ledger/daily_ledger_admin_area_filters_harness.php is the model),
then SHOW the mutation that turns it red: apply it, show the red output, restore, show green.

# HOW TO WORK
- Reproduce each item before fixing it. Report the reproduction even if the fix later fails.
- Prefer the smallest correct change. Reuse existing helpers rather than adding new ones; if a helper
  is the right place, say so.
- MySQL 5.7: no window functions, no CTEs, no JSON_TABLE, no CHECK. InnoDB + utf8mb4.
- Do NOT weaken, disable, reword or delete an existing assertion to reach PASS. If an oracle
  disagrees with you, decide which side is stale (git log -S the assertion text, then git log -1 the
  commit that introduced it) and report it - a red test is evidence of disagreement, not of a bug.
- Every guard you add must be shown FALSIFIABLE: mutate, show red, restore, show green, report the
  mutation. A guard that cannot go red is decorative and must be reported as such.
- Report BLOCKED with the precise reason rather than improvising or widening scope.

# REPORT
status: PASS|FAIL|PARTIAL|BLOCKED
acceptance_before: the RED output you got from php tools/acceptance-daily-ledger-overview.php
acceptance_after: the GREEN output
item_a: file:line, what was inconsistent, the fix, and what the page now says for each of the three states
item_b: file:line, where the normalization lives, and the before/after of the stale branch_id case
item_c: the assertion change and the cold-cache run showing 0 failures
item_d: the rewritten assertion, the mutation, and the red output it produced
changed: file:line each
verification: suites as passed/failed read from test_results/*.json (not grep), plus the cold-cache run
unresolved:
PROMPT_EOF
)"

lane_model_run "$LANE_MODEL_CHAIN" "$PROMPT" /tmp/lane-overview-completeness-area-reset
