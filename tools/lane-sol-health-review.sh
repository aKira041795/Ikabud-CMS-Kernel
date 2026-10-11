#!/usr/bin/env bash
#
# Lane: sol-health-review
#
# ADVERSARIAL REVIEW - read-only. Sol must NOT commit, edit, or "fix" anything. Its job is to
# falsify, not to help. The chair has already accepted this work; a reviewer that agrees with the
# chair has added nothing, so disagreement is the deliverable.
#
# This is a REVIEW lane, so there is no acceptance criterion for it to turn green:
#   --no-acceptance-gate="review lane: the deliverable is a falsification report, not a passing command"

cd /var/www/html/applicationostest || exit 1
source tools/lane-model.sh

LANE_MODEL_CHAIN="openai-codex/gpt-5.6-sol,openai-codex/gpt-5.6-terra,deepseek-v4-flash"

PROMPT="$(cat <<'PROMPT_EOF'
You are an ADVERSARIAL REVIEWER in the Ikabud repo at /var/www/html/applicationostest.
Read-only: do NOT commit, edit, or fix anything. Report only.

# YOUR JOB
The chair built a Business Health view and a Products Overview, and has already accepted them.
Your value is finding what the chair missed - especially claims that pass a check but are not true
in the browser. Four defects below were already found by the chair's own verification, so the
pattern is established: green checks hid a square "pie", a silently-zero column, a broken template
parse and a dead cross-highlight. Find the ones still hiding.

Treat every claim in the commit messages and criteria as a HYPOTHESIS to falsify, not a fact.

# WHAT TO REVIEW
Commits to examine (git show --stat, then read the diffs):
  a44edb54  Business Health view built
  c1fbd6fd  overview suite re-anchored (105 assertions)
  42be23cd  Health guards: "re-anchor control count to the date range"
  458288f6  Health view: guard the legacy period URL and picked-range precedence
  b8467d61  Period becomes a real date range; comparison becomes visual
  e07f0954, e32bcaa9, 610e181a  Products overview: pie, tooltip, row highlight, sortable cumulative

# THE CLAIMS MOST WORTH ATTACKING, in priority order

## 1. Was the oracle re-anchor legitimate, or a weakened assertion? (HIGHEST PRIORITY)
42be23cd changed the CHAIR-OWNED criterion tools/acceptance-business-health-view.php, moving A7
from `$controlCount <= 4` to `<= 5`, and changed tests/daily-ledger/business_health_view_test.php
from `count === 4` to a new expectation. The commit argues the change is forced: the owner asked for
a date RANGE, so one period input became From + To, and the ceiling moves by exactly one.
Decide whether that reasoning holds or whether it is an oracle bent to fit the code. Check whether
any OTHER assertion in that diff lost strictness, became conditional, or stopped covering a case.
   git show 42be23cd -- tools/acceptance-business-health-view.php tests/daily-ledger/business_health_view_test.php
   git log -S "controlCount <= 4" --oneline -- tools/acceptance-business-health-view.php

## 2. Are the Health view's states actually distinct?
The view must distinguish "no sales recorded", "sales recorded but pending finalization", and
"finalized". Probe 207 has 103 pending rows and 0 completed for 2026-10-11, so the pending state is
the live one. Feed it deliberate inputs and check the three render differently, and that none of them
silently reads as PHP 0.00 or as a decline. A state that is never reachable is an untested branch:
say so if you cannot reach it.

## 3. Is the visual comparison honest?
b8467d61 renders a signed delta, an aria-hidden arrow, and a progress bar when comparable, and
"Not yet comparable: N row(s) pending finalization" when not. The danger is a delta computed against
a period that had no meaningful comparison - check what happens when the prior period is empty, and
when the prior period is itself pending. Report if any path renders an arrow or a percentage that a
business owner would read as real performance when it is not.

## 4. Is "cumulative share" sortable in a way that is actually true?
610e181a makes the cumulative column a sort control that toggles direction, on the argument that
cumulative share is monotonic in ranking order so it cannot be its own sort key, and the column
therefore chooses which END the running total builds from. Attack that: is the running total really
recomputed in the displayed order, does the 80/20 line still mean what the page says it means when
ascending, and does the stated derivation match what the code computes?

## 5. The pie's honesty
Slices are the top 10 plus a grey "Other products" remainder, sized from the step between
consecutive cumulative shares. Verify the steps are right (a contributor row carries a CUMULATIVE
share - reading it as a slice size would overstate every product after the first), that the wheel
reaches 100%, and that the aria-label states what a screen-reader user actually needs.

# METHOD - evidence over assertion
- PHP 8.5 local. MySQL 5.7 - no window functions, no CTEs, no JSON_TABLE, no CHECK.
- Local site http://baronledger.test, viewer admin_view/viewer123, admin for broader scope.
  Tenant 207 holds a copy of the production DB. READ-ONLY: never write business data.
- Render headlessly and read the HTML:
      php tools/acceptance-business-health-view.php
      php tools/acceptance-health-period-comparison.php
      php tools/acceptance-parity-pie-and-ranking.php
  and render a handler directly with the cli-bootstrap pattern used in those files.
- A criterion passing is NOT evidence the page is right in a browser. Prefer measuring the rendered
  DOM. The chair found a square pie, a zero column, an -1 mapping and a template parse failure all
  while the criteria were green - so explicitly look for the equivalent gap in YOUR review.
- Do NOT weaken, reword, or delete an assertion to reach a conclusion. If an oracle disagrees with
  correct code, decide which side is stale (git log -S, then git log -1) and say so with the
  chronology (git merge-base --is-ancestor) as proof.

# REPORT - exactly this shape
verdict: PASS | CHANGES_REQUIRED
finding_count: N

per finding:
  severity:   critical | high | medium | low
  commit:     the sha and file:line
  claim:      what the commit/criterion asserts
  falsified:  the exact command or render that shows it is not true
  evidence:   the observed output
  impact:     what a business owner would do wrong because of it
  fix:        the smallest correct change (describe only - do not apply)

then:
  weakest_claim: the single claim you would least defend, and why
  checked_and_held: what you attacked and could NOT break (so the chair knows what is proven)
  unreached: any branch or state you could not reach, and what would be needed
PROMPT_EOF
)"

lane_model_run "$LANE_MODEL_CHAIN" "$PROMPT" /tmp/lane-sol-health-review
