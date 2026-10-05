#!/usr/bin/env bash
#
# Lane: pending-not-monetised
#
# Owner directive (2026-10-05): "the provisional sales amount should not surface again. what the
# admin sees is the actual, correct amount thus pending sales are not included. my point is,
# provisional sales amount confuses accounting."
#
# Base state, measured: the sales view footer renders
#   "Provisional (pending ending / unfinalized PM): 6 PHP 150.00"
# and the dashboard surfaces a provisional amount in three places, and the reports view in its
# header and all three row sets.
#
# Oracles (chair-owned, DO NOT EDIT):
#   tests/daily-ledger/daily_ledger_pending_not_monetised_test.php   base 5/11
#   tests/daily-ledger/daily_ledger_sales_pending_marker_test.php    base 19/21 (density)
#
set -u
cd /var/www/html/applicationostest || exit 1
source tools/lane-model.sh

LANE_MODEL_CHAIN="deepseek-v4-flash,openai-codex/gpt-5.6-sol,openai-codex/gpt-5.6-terra"

PROMPT="$(cat <<'PROMPT_EOF'
You are removing a provisional MONEY figure from the Daily Ledger admin surfaces. This is a
DISPLAY change. You are NOT changing any arithmetic.

THE OWNER'S DIRECTIVE, verbatim
  "the provisional sales amount should not surface again. what the admin sees is the actual,
   correct amount thus pending sales are not included. my point is, provisional sales amount
   confuses accounting."

THE IMPORTANT FACT THAT KEEPS THIS LOW-RISK
The official totals are ALREADY correct. dl_reportSalesData() already buckets rows with the
canonical predicate, so grand_amount ALREADY excludes provisional. Nothing is miscalculated.
The defect is only that a SECOND money figure is put in front of the admin - a
"Provisional ... PHP X" line - which reads as revenue and invites double counting.

So: DO NOT change any total, bucket, settle or variance arithmetic. Only stop displaying the
provisional amount.

WHAT TO REMOVE - every admin surface that shows a provisional money figure
Measured locations (re-verify, there may be more):
  1. templates/modules/daily-ledger/admin/sales.disyl      - the footer row
        "Provisional (pending ending / unfinalized PM): {provisional_units} ... PHP {provisional_amount}"
  2. templates/modules/daily-ledger/admin/dashboard.disyl  - THREE places
        line ~72  "+ {provisional_units_today} units · PHP {provisional_amount_today} provisional"
        line ~106 "+ {branch_provisional_units} units · PHP {branch_provisional_amount} provisional"
        line ~122 "+ PHP {card.provisional_amount} provisional"
  3. templates/modules/daily-ledger/admin/reports.disyl    - the header AND three row sets
        line ~68  "Provisional: {totals.provisional_units} units · PHP {totals.provisional_amount}"
        lines ~85, ~90, ~95  per-branch / per-month / per-category rows with
                              {row.provisional_units} and PHP {row.provisional_amount}
  4. Whatever feeds the reports TABLE COLUMNS and the CSV/PDF EXPORT. The reports view renders
     {columns} from a definitions array and the export uses the same definitions
     (handlers.php ~L10415-10460). If a provisional-amount column or key exists there, remove it
     from the definitions too, or the export will still carry the figure.

WHAT TO KEEP - do not over-remove
  - PROVISIONAL UNITS stay. Units are not money, and they tell an operator how much is
    outstanding. But they must be LABELLED so they cannot be read as sales. Replace a
    "provisional sales" framing with an explicit pending / not-counted framing, e.g.
    "Pending (not counted yet): 6 units" rather than "6 units provisional".
    Make the absence of money explicit in the sales view, so nobody re-adds it: say that no
    amount is shown because the figures are not counted.
  - The row-level markers stay: every row whose data is not final must still be visibly marked.
  - The provisional BUCKET must stay in helpers/reporting.php. The settlement workflow and the
    official totals depend on that classification. Only its DISPLAY is being removed. The
    monetisation oracle asserts reporting.php still computes a provisional_amount.

MARKER DENSITY - one badge per row
Measured: a pending row currently carries TWO "Pending count" badges (shift cell and sales cell),
which reads as noise across a 70-row table. Change to ONE:
  - Keep the badge on the SHIFT cell (both wordings: 'Pending count' for a missing ending,
    'Provisional' for an entered-but-uncertified ending). This is the row marker.
  - The SALES cell keeps its amber tint, and must gain the not-final flag as an attribute on the
    <td> ITSELF: title="..." and/or aria-label="...". Not a second badge, and not only a colour -
    the attribute is what a screen reader announces and it is what the oracle checks.
    The oracle matches the attribute on a <td>, so putting it on an inner span will NOT pass.

EXPLICIT DASH FOR UNCOUNTED FIGURES
Measured: for a row with bal_end IS NULL the Bal End, Sales and Amount cells render EMPTY, which
reads as a rendering failure. Render an explicit em dash (—) instead.
The existing template has `{if row.sales !== null && row.sales !== ''}{row.sales}{else}—{/if}`
and the dash does NOT come out - investigate why (DiSyL may not be evaluating that compound
condition as written) and make it render. A working alternative is a `| default:'—'` filter or
passing a display-ready string from the handler. Whatever you choose, PROVE the dash is in the
rendered HTML, and keep it consistent across Bal End, Sales and Price/Amount.
Note: this is a DiSyL/template-level question. If you conclude DiSyL itself mishandles the
construct, say so in your report with the reproduction - do not silently work around a language
bug without reporting it.

CONSTRAINTS
  - No arithmetic change. No change to the bucket, the ladder, the settle path, or the markers'
    meaning.
  - Do NOT edit either oracle:
      tests/daily-ledger/daily_ledger_pending_not_monetised_test.php
      tests/daily-ledger/daily_ledger_sales_pending_marker_test.php
    Fix the code until they pass. If you think an oracle assertion is wrong, say so with evidence
    and leave it alone.
  - Do NOT remove the provisional UNITS from the underlying data or handlers - the views still
    need them; only drop the AMOUNT figure from display (and the amount key from report columns).
  - Keep MySQL 5.7 compatibility and DiSyL 4.8 syntax. Watch the tight pipe-binding rule: write
    {(a + b) | filter} with explicit parentheses.

ACCEPTANCE - the first currently FAILS on the base (5/11):
  php tests/daily-ledger/daily_ledger_pending_not_monetised_test.php   -> 11/11
  php tests/daily-ledger/daily_ledger_sales_pending_marker_test.php    -> 21/21
  php tests/daily-ledger/daily_ledger_reporting_test.php               -> 76/76   (bucket pinned)
  php tests/daily-ledger/daily_ledger_production_controls_test.php     -> 70/70
  php tests/daily-ledger/daily_ledger_handlers_test.php                -> 229/229
  php tests/daily-ledger/daily_ledger_overview_test.php                -> 104/104
  php tests/daily-ledger/daily_ledger_routes_test.php                  -> 82/82
  php _lint_disyl.php templates/modules/daily-ledger/admin/sales.disyl
  php ikabud module:validate daily-ledger

Check BOTH logs (storage/logs/app.log, storage/logs/error.log) and report what you found.

REPORT COMPACTLY:
  status: PASS | FAIL | BLOCKED
  changed:
  for EACH surface: what the provisional line said before -> what it says now
  confirm no arithmetic/bucket/total change was made
  how the dashboard and reports row/column definitions were handled (and the export)
  the dash: what was wrong and what now renders (quote the rendered HTML)
  verification: exact commands and numbers
  logs:
  risks / unresolved:

BLOCKED is correct if removing a report COLUMN would break a stored definition or an export
contract you cannot safely change, or if an oracle contradicts the directive.
PROMPT_EOF
)"

lane_model_run "$LANE_MODEL_CHAIN" "$PROMPT" /tmp/pending-not-monetised
rc=$?
echo "lane: pending-not-monetised — completed by: ${LANE_MODEL_USED:-<none>} (rc=$rc)"
exit "$rc"
