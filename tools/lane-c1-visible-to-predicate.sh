#!/usr/bin/env bash
#
# Lane: c1-visible-to-predicate
#
# C1 ("a DERIVED ending is never a count") is enforced only where the row is fetched WITH
# end_source. Two row-level paths fetch without it, so the clause cannot fire.
#
# Measured on the base with a fixture (settled ending + FINALIZED shift, the state the
# render-time auto-finalize produces once settle fills the endings):
#   status_label = 'official'      <- a derived, UNVERIFIED ending counted as revenue
#   official_amount = 150.0        <- and it reaches the official total
#
# Oracle (chair-owned, DO NOT EDIT): tests/daily-ledger/daily_ledger_c1_derived_ending_test.php
#   base 3/7
#
set -u
cd /var/www/html/applicationostest || exit 1
source tools/lane-model.sh

LANE_MODEL_CHAIN="deepseek-v4-flash,openai-codex/gpt-5.6-sol,openai-codex/gpt-5.6-terra"

PROMPT="$(cat <<'PROMPT_EOF'
You are making the C1 clause of the provisional rule actually reachable on two row-level paths.
You are NOT changing the rule, and NOT changing any arithmetic.

BACKGROUND - read dl_rowIsProvisional() first (modules/daily-ledger/helpers/reporting.php ~L136)

It opens with the C1 clause:

    $endSource = (string)($row['end_source'] ?? '');
    if ($endSource === 'derived-from-movements' || $endSource === 'zero-forced') {
        return true;                  // a DERIVED ending is never a count
    }

C1 is the entire reason `end_source` exists: the settle ladder writes a derived ending with
end_source naming the rung, and the row must stay PROVISIONAL until an admin verifies it (verify
clears end_source, which is what makes the row countable again).

Note the `?? ''` — the predicate is DEFENSIVE, so a row fetched without the column silently
evaluates as "no derived ending" instead of erroring. That is how the clause went dead.

THE DEFECT - MEASURED, not inferred

Two row-level fetches do not select the column, so C1 can never fire for them:

  1. dl_reportSalesData()  modules/daily-ledger/helpers/reporting.php ~L223-226
       SELECT ... dl.bal_end, {qty} AS sales, dl.price_snapshot, {amount} AS amount,
              ss.status AS shift_status
     -> no dl.end_source, so the bucket AND $row['status_label'] are blind.
  2. the admin sales LIST query  modules/daily-ledger/handlers.php ~L10627
       SELECT dl.ledger_date, dl.shift, ... dl.bal_end, ...
     -> no dl.end_source, so the row BADGE is blind too.

With a fixture row that has an ending, end_source='derived-from-movements', and a FINALIZED
shift, the report currently returns status_label='official' and puts its amount (150.0) into
official_amount. A derived, unverified ending is being counted as revenue.

WHY IT IS REACHABLE, not theoretical
Settle is only allowed while a shift is unfinalized, so right after a settle the shift-status
clause keeps the row provisional BY ACCIDENT. But settling FILLS the endings, which makes the PM
shift COMPLETE, and dl_maybeAutoFinalizeCommissaryPmShift() finalizes a complete PM shift on the
next RENDER. From then on the row has a finalized shift and an unverified derived ending, and any
path that cannot see C1 counts it official. The dashboard can see C1 (it now calls
dl_provisionalSqlExpr), so the dashboard and the report would disagree about the same row.

WHAT TO DO
  - Find EVERY row-level caller of dl_rowIsProvisional() and dl_salesRowStatusLabel()
    (grep both names across modules/daily-ledger/) and make sure each one's SQL SELECT includes
    the end_source column of the ledger row it passes in. Fix at least the two above; fix any
    others you find rather than leaving the next one blind.
  - Prefer a shared column list or an explicit `dl.end_source` in each SELECT. Do not fetch it
    for aggregate-only queries that never call the predicate on a row.
  - Add a short comment at each site saying the column is required by C1, so a future
    optimisation does not drop it again.

CONSTRAINTS
  - Do NOT change dl_rowIsProvisional(), dl_salesRowStatusLabel() or dl_provisionalSqlExpr().
    They are correct; the callers are what are blind.
  - Do NOT change any arithmetic, the provisional bucket totals, or the ladder.
  - Do NOT edit the oracle:
      tests/daily-ledger/daily_ledger_c1_derived_ending_test.php
  - If a query uses POSITIONAL fetching (fetchColumn on an index, or SELECT *), adding a column
    can shift offsets. Check before adding and say so if you had to touch one.
  - MySQL 5.7 / DiSyL 4.8 safe.

ACCEPTANCE - the first currently FAILS on the base (3/7):
  php tests/daily-ledger/daily_ledger_c1_derived_ending_test.php       -> 7/7
  php tests/daily-ledger/daily_ledger_pending_not_monetised_test.php   -> 11/11
  php tests/daily-ledger/daily_ledger_sales_pending_marker_test.php    -> 21/21
  php tests/daily-ledger/daily_ledger_reporting_test.php               -> 76/76
  php tests/daily-ledger/daily_ledger_production_controls_test.php     -> 70/70
  php tests/daily-ledger/daily_ledger_handlers_test.php                -> 229/229
  php tests/daily-ledger/daily_ledger_overview_test.php                -> 104/104
  php tests/daily-ledger/daily_ledger_routes_test.php                  -> 82/82
  php ikabud module:validate daily-ledger

Remember: a derived ending must still be excluded from the OFFICIAL total, and still counted in
the provisional one. Run the reporting suite specifically - it pins the bucket split.

Check BOTH logs (storage/logs/app.log, storage/logs/error.log) and report what you found.

REPORT COMPACTLY:
  status: PASS | FAIL | BLOCKED
  changed:
  every row-level caller you found and whether its SELECT already had end_source
  confirmation that no arithmetic/bucket change was made
  verification: exact commands and numbers
  logs:
  risks / unresolved:

BLOCKED is correct if adding the column would change an existing total, or if a caller cannot be
made to see C1 without a schema change.
PROMPT_EOF
)"

lane_model_run "$LANE_MODEL_CHAIN" "$PROMPT" /tmp/c1-visible-to-predicate
rc=$?
echo "lane: c1-visible-to-predicate — completed by: ${LANE_MODEL_USED:-<none>} (rc=$rc)"
exit "$rc"
