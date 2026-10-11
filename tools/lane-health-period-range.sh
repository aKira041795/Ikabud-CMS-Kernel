#!/usr/bin/env bash
#
# Lane: health-period-range-and-visual-comparison
#
# Owner's review comment on the new Business Health view, verbatim (2026-10-11):
#   "period must be a date range, if we need to compare, the visually show how. continue with
#    outstanding tasks"
#
# ACCEPTANCE CRITERION - written and MEASURED RED by the chair BEFORE dispatch:
#   php tools/acceptance-health-period-comparison.php   ->  2 RED, exit 1
#     P1 RED  type="date" fields=0, date_from=NO, date_to=NO,
#             and "still asks the user to TYPE a range: YES"
#     P3 RED  no signed/percentage delta, no visual delta element
#     P2 PASS  it already names its baseline and shows the dates (keep that)
#     P4 PASS  the bar holds 4 controls; a range adds one, 6 is the ceiling
#     P5 PASS  verdict + opportunity must survive (keep them passing)
#
# Small and surgical, so flash first.

cd /var/www/html/applicationostest || exit 1
source tools/lane-model.sh

LANE_MODEL_CHAIN="deepseek-v4-flash,openai-codex/gpt-5.6-sol,openai-codex/gpt-5.6-terra"

PROMPT="$(cat <<'PROMPT_EOF'
You are refining the Business Health view in the Ikabud repo at /var/www/html/applicationostest.
PHP 8.5 local; production MySQL 5.7 (INSPECTION-ONLY claims). Local site http://baronledger.test
against a copy of the production DB; viewer account admin_view / viewer123. Read-only: render and
resolve, never write business data.

# YOUR ACCEPTANCE CRITERION - run FIRST, confirm 2 RED, make it exit 0
    php tools/acceptance-health-period-comparison.php
Owned by the chair. Do NOT weaken, rewrite or delete it. P2, P4 and P5 pass today and must STAY
passing. If you believe a check is wrong, say so with evidence and leave the file intact.

# WHAT THE OWNER SAID (verbatim) - this is the whole brief
    "period must be a date range, if we need to compare, the visually show how."

## 1. PERIOD MUST BE A DATE RANGE (P1)
Today the primary bar has ONE free-text input:
    <input class="form-input" name="period" value="{period_value}"
           placeholder="YYYY-MM-DD or YYYY-MM-DD to YYYY-MM-DD">
The owner must TYPE a range in an exact format. Replace it with TWO date inputs (from + to) using
type="date", named date_from and date_to, so they are picked rather than typed. Sensible defaults
(the current period behaviour must not regress - whatever period the page defaults to today must
remain the default). Keep the parsing tolerant of the old `period` value if it still arrives, so an
existing bookmarked URL does not break; do not introduce a second source of truth for "what period
is this".

## 2. COMPARISON MUST BE SHOWN VISUALLY (P3)
The page currently renders the card title "Trend vs previous period" plus a text value, and it
ALREADY names its baseline and shows the dates (P2 passes - keep that). What is missing is the
VISUAL: a signed delta (absolute and/or percentage) and a visual indicator for direction - up/down,
bar, arrow, colour - so the owner can see how it compares without reading a sentence.
Constraints: the indicator must be driven by real numbers, and it must be honest when there is no
comparison available (no prior data, or prior data pending finalization) rather than rendering a
misleading zero or a green arrow. Today 2026-10-11 has 103 pending rows and 0 completed, so the
"no honest comparison yet" state is the LIVE state - handle it explicitly.

# CONSTRAINTS
- "not complicated" still holds: the bar may hold Area, Branch, from, to, Apply. P4 enforces that
  ceiling - do not add other controls to it.
- Do NOT move or delete the relocated controls (pending_rows, forecast_window, sort_by, sort_dir,
  chart_limit). They live in their sections; an assertion at tests/daily-ledger/daily_ledger_overview_test.php:684
  guards that, with mutation M14 as its falsifier. Do not regress it.
- MySQL 5.7: no window functions, no CTEs, no JSON_TABLE, no CHECK.
- Do NOT weaken, disable, reword or delete an existing assertion to reach PASS. If an oracle
  disagrees with correct code, identify which side is stale (git log -S, then git log -1) and report.
- Every guard you add must be FALSIFIABLE, and the mutation must be shown EFFECTIVE in that scenario
  (print the changed value or assert the code path was reached). A mutation that does not execute the
  changed branch proves nothing - this has bitten twice in this repo.
- After committing: verify with `git show HEAD:<file> | grep <the-change>` that the fix is in the
  COMMITTED BLOB, then diff the commit's file list against `git status`. A previous commit omitted a
  template and the fix silently vanished from history while the worktree looked correct.

# REPORT
status: PASS|FAIL|PARTIAL|BLOCKED
acceptance_before / acceptance_after: the RED and then GREEN output
period: the two fields, their names, their defaults, and how an old `period` URL is still handled
comparison: the delta computation, what drives the visual indicator, and what is shown when no honest
  comparison exists (today's live state)
falsification: mutation per new guard, with evidence it executed
changed: file:line each
verification: suites read from test_results/*.json, plus the two acceptance criteria
unresolved:
PROMPT_EOF
)"

lane_model_run "$LANE_MODEL_CHAIN" "$PROMPT" /tmp/lane-health-period-range
