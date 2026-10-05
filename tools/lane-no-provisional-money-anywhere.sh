#!/usr/bin/env bash
#
# Lane: no-provisional-money-anywhere
#
# Independent review by Sol (2026-10-05) returned CHANGES_REQUIRED at 94%: the owner's directive
# ("the provisional sales amount should not surface again ... pending sales are not included") is
# NOT met. Provisional money still reaches the admin:
#
#   1. templates/.../admin/sales.disyl:202   row.amount is rendered for EVERY status, including a
#      provisional row. Confirmed by the strengthened oracle:
#        "the provisional row renders the amount 150"
#   2. templates/.../admin/reports.disyl:73-75   Daily Sales report rows render amount for every row.
#   3. helpers/reporting.php:417   the CSV/PDF column list includes row `amount`.
#   4. helpers/reporting.php:559-572   summary total_amount / net_amount / share_pct are computed
#      from OFFICIAL + PROVISIONAL, and reports.disyl:83-95 displays that as "Net".
#
# The chair's first oracle could not see (1) because its provisional fixture had amount => null and
# its regex only rejected money written as "provisional ... PHP" - the amount cell has no PHP
# prefix. That is fixed; the oracle NOW FAILS on the real tree.
#
# Oracle (chair-owned, DO NOT EDIT): tests/daily-ledger/daily_ledger_pending_not_monetised_test.php
#   base 13/15
#
set -u
cd /var/www/html/applicationostest || exit 1
source tools/lane-model.sh

LANE_MODEL_CHAIN="deepseek-v4-flash,openai-codex/gpt-5.6-sol,openai-codex/gpt-5.6-terra"

PROMPT="$(cat <<'PROMPT_EOF'
You are finishing the owner's monetisation directive. Provisional data must not be presented as
money, anywhere, to an admin or in an export.

OWNER DIRECTIVE, verbatim: "the provisional sales amount should not surface again. what the admin
sees is the actual, correct amount thus pending sales are not included. my point is, provisional
sales amount confuses accounting."

An independent review found the earlier attempt was a HALF-MEASURE: it removed the explicitly
labelled provisional amount but left provisional money reachable in four other places. MEASURED
on the current tree with the strengthened oracle:

    ❌ a provisional row does not display its computed money amount
       the provisional row renders the amount 150:
       2031-03-02 PM Provisional Money Branch Unsettled Product MON-C 20 100 5 100 15 10 150

WHAT TO FIX - four places, and search for others

1. ROW-LEVEL DISPLAY. A row whose status_label is not 'official' must not display a money amount.
   - templates/modules/daily-ledger/admin/sales.disyl (~L202): row.amount renders unconditionally.
   - templates/modules/daily-ledger/admin/reports.disyl (~L73-75): Daily Sales rows the same.
   Render an em dash for a non-official row, exactly as you already do for an uncounted Sales cell.
   Note the amount cell has NO `PHP` prefix, so do not just think about the prefix - the bare number
   IS the leak.

2. THE EXPORT. modules/daily-ledger/helpers/reporting.php (~L417) lists row `amount` for the
   exported CSV/PDF. A settled or pending row's amount must not be exported as money.
   Decide the cleanest boundary and justify it in your report: either emit the dash/empty for a
   non-official row, or keep the raw value but ONLY alongside an explicit status column so it can
   never be read as revenue. State which you chose and why. Do not silently drop the status column.

3. THE SUMMARY MONEY. modules/daily-ledger/helpers/reporting.php (~L559-572) computes
   total_amount / net_amount / share_pct from OFFICIAL + PROVISIONAL, and reports.disyl (~L83-95)
   displays that as "Net". This is the worst of the four: it is a single authoritative-looking money
   figure that contains provisional sales.
   The owner's rule is "pending sales are not included", so any money figure PRESENTED AS SALES must
   be computed from OFFICIAL rows only. Adjust the calculation, or stop presenting the
   provisional-inclusive figure as Net - your choice, but state it. Units may keep the provisional
   breakdown; the owner explicitly accepted units, relabelled "not counted".

4. ANY REMAINING SURFACE. Search for other places provisional money can reach a human: other report
   types, other templates, the overview, dashboards, any JSON a UI renders. Fix what you find and
   list what you checked so the search is auditable.

DELIBERATELY OUT OF SCOPE, so you do not over-reach:
  - `dl_branchConsolidatedSummary()` returns `provisional_sales` over a JSON endpoint. The review
    recommends VERSIONED DEPRECATION rather than silent removal. Do NOT remove it in this lane; note
    it in your report.
  - Provisional UNITS stay visible. They are operational information, not money.

CONSTRAINTS
  - Do NOT change the provisional PREDICATE, the SQL helper, the settle ladder or the day lifecycle.
    This is about what money is DISPLAYED and EXPORTED, not about the classification.
  - The OFFICIAL total must remain exactly as it is. You are removing provisional money, not
    changing the official figure. Run the reporting suite - it pins the bucket split.
  - Do NOT edit the oracle: tests/daily-ledger/daily_ledger_pending_not_monetised_test.php
    It currently reports 13/15 and the 2 failures are the point. Fix the code until it is 15/15.
  - If you conclude a figure MUST keep a provisional component, say so in the report with the reason
    rather than quietly leaving it OR quietly changing it.

ACCEPTANCE - the first currently FAILS (13/15):
  php tests/daily-ledger/daily_ledger_pending_not_monetised_test.php   -> 15/15
  php tests/daily-ledger/daily_ledger_c1_derived_ending_test.php       -> 7/7
  php tests/daily-ledger/daily_ledger_sales_pending_marker_test.php    -> 21/21
  php tests/daily-ledger/daily_ledger_reporting_test.php               -> 76/76
  php tests/daily-ledger/daily_ledger_production_controls_test.php     -> 70/70
  php tests/daily-ledger/daily_ledger_handlers_test.php                -> 229/229
  php tests/daily-ledger/daily_ledger_overview_test.php                -> 104/104
  php tests/daily-ledger/daily_ledger_routes_test.php                  -> 82/82
  php ikabud module:validate daily-ledger

Check BOTH logs (storage/logs/app.log, storage/logs/error.log) and report what you found.

REPORT COMPACTLY:
  status: PASS | FAIL | BLOCKED
  changed:
  for each of the 4 areas: what was leaking, what it does now, and the exact file:line
  what you chose for the export and for the Net figure, and why
  every surface you searched and found clean
  verification: exact commands and numbers
  logs:
  risks / unresolved:

BLOCKED is correct if a fix would change an OFFICIAL figure, or if removing a figure would break a
consumer you cannot safely change.
PROMPT_EOF
)"

lane_model_run "$LANE_MODEL_CHAIN" "$PROMPT" /tmp/no-provisional-money-anywhere
rc=$?
echo "lane: no-provisional-money-anywhere — completed by: ${LANE_MODEL_USED:-<none>} (rc=$rc)"
exit "$rc"
