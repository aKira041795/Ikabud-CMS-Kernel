#!/usr/bin/env bash
#
# Lane: viewer-branch-affordance
#
# Source: docs/daily-ledger/VIEWER-SURFACE-ASSESSMENT-PLAN-2026-10-12.md — §4 F13 (branch-name links
# are plain coloured text, a weak affordance for the page's main drill-down) and F14 (the silent-
# branch list can dominate the page: measured "0 recorded activity · 22 silent" on the default
# view), §6 W10 (make drill-downs visibly clickable; collapse the silent list behind a count).
#
# Criterion (2 RED on the base, proven by the dispatch gate):
#   tools/acceptance-viewer-branch-affordance.php
#
# Owner directive driving this dispatch (2026-10-12): delegate to Sol until rate is exhausted.

cd /var/www/html/applicationostest || exit 1
source tools/lane-model.sh

LANE_MODEL_CHAIN="openai-codex/gpt-5.6-sol,deepseek-v4-flash,openai-codex/gpt-5.6-terra"

PROMPT="$(cat <<'PROMPT_EOF'
You are working in the Ikabud repo at /var/www/html/applicationostest. PHP 8.5 local; production is
MySQL 5.7. Local site http://baronledger.test runs tenant 207 (a copy of production); the surface is
the daily-ledger Business Health page (/daily-ledger/admin/overview) seen as role=viewer.

# CONTEXT (read first)

- docs/daily-ledger/VIEWER-SURFACE-ASSESSMENT-PLAN-2026-10-12.md - §4 F13/F14, §6 W10.
  The design is SETTLED; do not re-litigate it.
- tools/acceptance-viewer-branch-affordance.php - YOUR ORACLE. 2 RED right now; make it 0 RED
  WITHOUT weakening it. B3 (drill-downs keep date_from/date_to in every href) is a regression pin -
  it must stay green.

# REQUIRED OUTCOME (W10) - templates/modules/daily-ledger/admin/overview.disyl ONLY

a) F13 - visible affordance on every branch drill-down. The links at the branch chart bars (~:128),
   the working-branch cards (~:129) and the silent-branch cards (~:135) currently render as plain
   `text-sm font-semibold text-indigo-700` anchors. Add a visible affordance signal to ALL of them,
   e.g. `underline decoration-dotted underline-offset-2` and/or a `hover:` class; btn- classes are
   also acceptable. Keep them links, keep their current colour/semibold look, and keep every href's
   scope/date_from/date_to/branch_id parameters (pinned by B3).

b) F14 - collapse the silent-branch list behind a count. The card at ~:133-:137 currently renders
   all 15-22 silent branches as a full always-open list. Wrap the list in a CLOSED native
   `<details>` (no `open` attribute) with a `<summary>` that STATES the count, e.g.
   "22 branches with no recorded activity". Suggested structure: the card-header keeps
   "Branches with no recorded activity" + "not ranked"; the <details> below it carries the summary
   and the list. All silent branch links stay INSIDE the details; nothing in that card may expose
   branch links outside it. Only render the <details> when the silent count > 0 (the existing
   "Every branch in scope recorded at least one ledger row." message stays for the zero case).

   IMPORTANT - these exact strings are pinned by tests/daily-ledger/business_health_view_test.php
   and must survive: "Branches with no recorded activity", "not ranked",
   "No ledger rows; no performance conclusion is made."

c) No JavaScript; native details/summary only (accessible by default). No other sections change -
   the Ledger Control Status work from earlier today stays as it is.

# CONSTRAINTS

- Edit ONLY templates/modules/daily-ledger/admin/overview.disyl. Not the handler, not the oracle,
  not tests, not other templates.
- The counts you need are already in context ({silent_branches | count} and friends).
- Do NOT git add / git commit; leave the tree for review.
- DiSyL lint: `php _lint_disyl.php` must stay at 703/703.

# VERIFY (report the actual output lines)

1. php tools/acceptance-viewer-branch-affordance.php        -> must end '0 RED'
2. php tests/daily-ledger/business_health_view_test.php     -> must stay 15/15
3. php tests/daily-ledger/daily_ledger_overview_test.php    -> must stay 106/106
4. php tools/acceptance-viewer-overview-honesty.php         -> must stay '0 RED' (regression)
5. php _lint_disyl.php                                      -> 703/703

# REPORT (exactly this shape)

status: PASS|FAIL|PARTIAL|BLOCKED
changed: file:line each
design_calls: the affordance classes chosen; the summary wording; the zero-case structure
verification: the five commands' result lines, verbatim
unresolved:
PROMPT_EOF
)"

lane_model_run "$LANE_MODEL_CHAIN" "$PROMPT" /tmp/lane-viewer-branch-affordance
