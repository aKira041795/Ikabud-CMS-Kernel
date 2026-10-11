#!/usr/bin/env bash
#
# Lane: review-overview-completeness-area-reset
#
# Sol, review lane. Dispatched after overview-completeness-and-area-branch-reset LANDS and its tree is
# verified by the chair. This is a REVIEW: your job is to find what is WRONG, not to confirm.
#
# The chair will state the reviewed commit in the prompt when dispatching. If it is not stated, use
# `git log --oneline -1` and review that commit plus its working-tree diff.
#
# WHY THIS LANE EXISTS - the failure modes it is aimed at, all measured in this repo recently:
#  - a lane reported PASS with its own suite green over a page that rendered ZERO columns, because its
#    "excluded" assertion was vacuously true and its "network" assertion checked a different view
#  - an assertion the chair "repaired" was later proven DECORATIVE: the mutation left it green
#  - a chair claim ("the area filter does not scope the data") rested on a NON-DISCRIMINATING
#    measurement (ALL vs AREA:2 were identical because every row is in area 2)
# So: distrust every green, and check the criterion could have failed.

cd /var/www/html/applicationostest || exit 1
source tools/lane-model.sh

LANE_MODEL_CHAIN="openai-codex/gpt-5.6-sol,deepseek-v4-flash,openai-codex/gpt-5.6-terra"

PROMPT="$(cat <<'PROMPT_EOF'
You are REVIEWING, not implementing, in the Ikabud repo at /var/www/html/applicationostest.
PHP 8.5 local; production is MySQL 5.7 (label any 5.7 claim INSPECTION-ONLY). Local site:
http://baronledger.test against a copy of the production DB (viewer account admin_view / viewer123).
Read-only: render and resolve, never write business data.

Review the diff and the work of lane `overview-completeness-and-area-branch-reset`, which had four
items. Its contract is in tools/lane-overview-completeness-area-reset.sh - read it, then judge the work
against it rather than against the summary below.

  [A] The Business Overview said "No sales recorded for this period" while today (2026-10-11) has
      103 ledger rows / 103 PENDING endings / 0 completed. It must not read as a business decline.
  [B] A branch invalidated by an Area change (branch 13 is area 1, not area 2) must RESET to All
      Branches, not resolve to a set the explicit branch_id cannot match (which renders empty).
  [C] daily-ledger-overview asserted app.log is untouched, but DiSyL logs an info
      `disyl.render.breakdown` line when it COMPILES a template, so the suite fails on the first run
      after any template change (measured 1 fail, then 0, 0).
  [D] An assertion in daily_ledger_commissary_default_source_test.php was proven DECORATIVE by the
      chair and Sol: it queries all commissaries independently instead of observing the rendered
      selector, so narrowing the selector does not turn it red. It had to be rewritten to observe
      rendered output AND shown going red under a mutation.

# YOUR JOB - answer each with evidence, and be adversarial
1. IS A ACTUALLY FIXED, or only reworded? Verify the three states are DISTINCT and correct on real
   data: genuine no-activity, activity-with-pending-endings, and finalized activity. Produce the three
   renderings and show what each says. A page that now says "pending" in one place while still saying
   "no sales" in another is NOT fixed - the chair measured exactly that inconsistency
   (`page mentions pending/incomplete: yes` alongside `page says "No sales recorded": YES`).
2. IS B SAFE? The reset must not widen access. Confirm that an explicitly requested INACCESSIBLE
   branch still fails closed rather than silently widening (dl_reportFilters() documents preserving an
   inaccessible branch for exactly this reason). Try to construct the case that breaks it.
3. IS C HONEST? The guard must still catch genuine unexpected app.log writes - it must not have been
   widened into a no-op or deleted. Show that an unexpected line still fails it. If it now tolerates
   anything, say so plainly.
4. IS D FALSIFIABLE NOW? Re-apply the mutation that narrows the selector against the real query and
   SHOW the red output. If it stays green, say so - do not accept the claim.
5. WERE ANY ASSERTIONS WEAKENED? This is the most important check. Diff every test file against HEAD
   and look for deleted assertions, relaxed comparisons, removed cases, or a check that now passes
   vacuously. `git diff HEAD -- tests/` is your surface. Report any weakening as a finding even if
   suites are green.
6. DOES THE ACCEPTANCE CRITERION DISCRIMINATE? Run `php tools/acceptance-daily-ledger-overview.php` and confirm it is now
   GREEN. Then check it could have failed: was any part of it edited to make it pass? It is owned by
   the chair and must be byte-identical to HEAD apart from nothing at all - report if it changed.
7. ANYTHING UNNAMED? Read the WHOLE diff, not just the files the contract names. A lane that changed
   files outside its contract is a finding.

# OUTPUT
verdict: PASS | CHANGES_REQUIRED | BLOCKED
findings: for each of the seven checks, what you did, what you observed, and your judgement. Quote
  command output rather than paraphrasing it.
weakest_claim: the single thing in this diff most likely to be wrong, and what evidence would settle it
unverified: what you could NOT check and why
Do NOT fix anything. Report only. If you believe a finding is wrong, say why with evidence.
PROMPT_EOF
)"

lane_model_run "$LANE_MODEL_CHAIN" "$PROMPT" /tmp/lane-review-overview-completeness-area-reset
