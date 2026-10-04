#!/usr/bin/env bash
#
# Lane: sales-pending-marker
#
# Makes every COUNTED sales row visibly marked, distinguishes the two non-official
# states, and names the dates that have pending data in a range view.
#
# MEASURED on the base (chair, 2026-10-05) — this is why the feature is needed:
#   942 rows in dl_daily_ledger have bal_end IS NULL. They ARE counted in the view's
#   "Provisional" footer total (2976 provisional rows), yet carry NO badge at all.
#
# Oracle (written by the chair, DO NOT EDIT): tests/daily-ledger/daily_ledger_sales_pending_marker_test.php
#   base result: 6/14 passed
#
set -u
cd /var/www/html/applicationostest || exit 1
source tools/lane-model.sh

LANE_MODEL_CHAIN="deepseek-v4-flash,openai-codex/gpt-5.6-sol,openai-codex/gpt-5.6-terra"

PROMPT="$(cat <<'PROMPT_EOF'
You are making the Daily Ledger admin Sales view tell the truth about uncounted rows.
This is a UI/marker change. You are NOT changing any sales arithmetic.

THE OWNER'S REQUEST
"at admin view when viewing a specific date for sales ... a visual note on pending cells where
ending is missing ... on multi dates, show an orange cell on cells with pending and show as
tool tip dates with pending sales data"

THE DEFECT YOU ARE CLOSING — measured, not inferred
The Sales view's TABLE ROWS and its FOOTER TOTALS come from two different code paths:
  - totals  <- dl_reportSalesData() in helpers/reporting.php, which uses the canonical
               dl_rowIsProvisional() and counts 2976 rows as provisional;
  - rows    <- a separate list query in handlers.php (~L10625-10635) that selects
               `ss.status AS shift_status` but NO status_label.
Because the row path carries no label, the TEMPLATE re-derives the rule itself, and it got it
wrong:
    {if row.bal_end !== null && row.bal_end !== '' && row.shift == 'PM' && row.shift_status != 'finalized'}
That condition can NEVER be true when the ending is missing, yet 942 rows with
bal_end IS NULL are counted in the provisional total. Those rows are invisible.
The chair's oracle shows the rendered row for exactly that case:
    ... MRK-B 10 0 4 [blank] 6 25 150
i.e. Bal End renders blank, Sales renders a DERIVED 6, and nothing says the row is pending.

THE WORDING — already decided, do not invent alternatives
  bal_end IS NULL (nobody has entered a count) -> "Pending count"
  ending present but shift not finalized       -> "Provisional"   (keep this word)
Keep the status_label VOCABULARY that dl_reportSalesData() already uses, exactly:
  'pending ending' | 'provisional' | 'official'
The "Pending count" is the BADGE TEXT; the label value stays 'pending ending'.

WHAT TO BUILD

1. helpers/reporting.php — ONE canonical labeller, reusing the existing predicate.
   Add:
       function dl_salesRowStatusLabel(array $row): string
   returning 'pending ending' when $row['bal_end'] is null, else 'provisional' when
   dl_rowIsProvisional($row), else 'official'.
   It MUST delegate to dl_rowIsProvisional() — do not copy the rule, that is the defect.
   Then refactor dl_reportSalesData() so its inline
       $row['status_label'] = $pending ? 'pending ending' : ($provisional ? 'provisional' : 'official');
   becomes a call to the new function. This must be behaviour-identical:
   tests/daily-ledger/daily_ledger_reporting_test.php is 76/76 and pins those values.
   Do NOT touch the bucket logic — it was fixed deliberately (see the long comment at
   reporting.php ~L226) and must stay.

2. handlers.php — the sales list path (~L10625-10688).
   After `$salesRows = $listStmt->fetchAll(...)`, annotate EVERY row:
       $row['status_label'] = dl_salesRowStatusLabel($row);
   Then derive, from those same labelled rows, the distinct ledger_date values whose
   status_label is NOT 'official', and pass them to the render context as
   `pending_dates` (an array of date strings, sorted, unique).
   Do not add a fourth copy of the rule and do not compute pending dates in SQL.

3. templates/modules/daily-ledger/admin/sales.disyl
   a. DELETE the hand-written badge condition and render from `row.status_label`.
      Badge text: "Pending count" for 'pending ending', "Provisional" for 'provisional'.
      Nothing at all for 'official'.
   b. Put the marker on the **Sales** cell as well as the Shift cell. A blank Bal End beside
      a derived Sales number reads as a counted figure; the marker is what stops that.
   c. Use an ORANGE tint for the marked cells (amber-100 / amber-800 family — the template
      already uses amber for the provisional badge and the footer, so match it).
   d. Add a pending-dates summary line above the table, shown only when pending_dates is
      non-empty, naming the dates. Something in the spirit of:
        "3 dates have pending data: 2031-03-02, 2031-03-03 — their figures are provisional
         and excluded from the official total."
      It must be VISIBLE TEXT, not a tooltip. A tooltip is secondary detail only.

NON-NEGOTIABLE CONSTRAINTS
  - COLOUR ALONE IS NOT A MARKER. This view is printed (block head has @media print, and
    there is a Print button), and tooltips do not survive print or touch. Every mark must
    carry visible TEXT, and the badge must carry an accessible label
    (title="..." or aria-label="...") explaining what it means.
  - An OFFICIAL row must carry NO marker. If everything is marked, nothing is.
  - Do NOT change any sales arithmetic, the official/provisional BUCKET, or the footer
    totals. The arithmetic is correct; only the labelling is missing.
  - Do NOT edit the oracle: tests/daily-ledger/daily_ledger_sales_pending_marker_test.php
    Fix the code until it passes. If you believe an oracle assertion is wrong, say so in your
    report with evidence and leave it alone — do not weaken it.
  - MySQL 5.7 safe. No new dependency. No new JS framework.
  - DiSyL 4.8: filters bind tightly, so parenthesise any filtered expression.

ACCEPTANCE — the first currently FAILS on the base (6/14):
  php tests/daily-ledger/daily_ledger_sales_pending_marker_test.php   -> 14/14
  php tests/daily-ledger/daily_ledger_reporting_test.php              -> 76/76  (bucket pinned)
  php tests/daily-ledger/daily_ledger_production_controls_test.php    -> 70/70
  php tests/daily-ledger/daily_ledger_handlers_test.php               -> 229/229
  php tests/daily-ledger/daily_ledger_overview_test.php               -> 104/104
  php tests/daily-ledger/daily_ledger_routes_test.php                 -> 82/82
  php ikabud module:validate daily-ledger

Also re-render nothing else: only admin/sales.disyl may change among templates.

Check BOTH logs (storage/logs/app.log, storage/logs/error.log) and report what you found.
Declare an expected line with $h->allowLogLines() ONLY in a test you own — you do not own the
oracle, and it already declares disyl.compile.phases.

REPORT COMPACTLY:
  status: PASS | FAIL | BLOCKED
  changed:
  the exact badge condition you now use, and confirm it comes from status_label
  how pending_dates is computed (which rows feed it)
  what the printed report looks like for a pending row (is the text there, or only colour?)
  a rendered excerpt of a pending-ending row from your own check
  verification: exact commands and numbers
  logs:
  risks / unresolved:

BLOCKED is correct if the oracle contradicts the current design, or if a marker cannot be
rendered without changing the arithmetic.
PROMPT_EOF
)"

lane_model_run "$LANE_MODEL_CHAIN" "$PROMPT" /tmp/sales-pending-marker
rc=$?
echo "lane: sales-pending-marker — completed by: ${LANE_MODEL_USED:-<none>} (rc=$rc)"
exit "$rc"
