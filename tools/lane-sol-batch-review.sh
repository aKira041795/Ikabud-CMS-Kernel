#!/usr/bin/env bash
#
# Lane: sol-batch-review  (READ-ONLY — analysis only, no edits)
#
# Independent architectural review of the daily-ledger batch that landed on 2026-10-05.
# Sol is the architect/review role in this repo's role separation; flash did the implementation.
#
# A review that only confirms is worthless. This brief names the specific places where the batch
# could be wrong and asks for evidence, not assurance.
#
set -u
cd /var/www/html/applicationostest || exit 1
source tools/lane-model.sh

# Sol first. Terra and flash are fallbacks so an exhausted provider cannot stop the review.
LANE_MODEL_CHAIN="openai-codex/gpt-5.6-sol,openai-codex/gpt-5.6-terra,deepseek-v4-flash"

PROMPT="$(cat <<'PROMPT_EOF'
You are performing an INDEPENDENT ARCHITECTURAL REVIEW of a batch of changes to the Daily Ledger
module in the Ikabud application. You are NOT implementing anything.

DO NOT EDIT ANY FILE. Do not create files. Do not run migrations or mutations. Read, query, measure,
and report. If you believe something must change, say so in the report - the chair will decide.

CONTEXT

The batch closed eight issues in one session. The commits, oldest first:
  c806060a  mark pending sales rows and name the dates with pending data
  3d412e72  stop showing a provisional sales amount anywhere        (owner directive)
  f7c4fe5d  one provisional SQL rule + deterministic browser fixture
  91cc92cf  make C1 reachable in the reporting and sales-list queries
  plus a pending change to two browser specs + the browser seed (date-relative dates)
and the chair's own oracle commits interleaved.

Owner directive that drove part of it, verbatim: "the provisional sales amount should not surface
again. what the admin sees is the actual, correct amount thus pending sales are not included. my
point is, provisional sales amount confuses accounting."

THE PROVISIONAL RULE, and why it was the centre of the batch
"is this ledger row provisional?" existed in FIVE places. The canonical one is
dl_rowIsProvisional() in modules/daily-ledger/helpers/reporting.php (~L136). It has three clauses:
  C1  end_source IN ('derived-from-movements','zero-forced')  -> provisional (a DERIVED ending is
      never a count; the settle ladder writes this and only an admin verify clears it)
  C2  bal_end IS NULL -> provisional (nobody has entered a count)
  C3  a shift-status row that EXISTS and is not 'finalized' -> provisional for ANY shift, AM
      included; a MISSING shift row is ambiguous and deliberately keeps the historical bucketing
      (AM official, PM provisional) because 3,149 AM rows / 20,680 units hang on it
The SQL copies now call dl_provisionalSqlExpr(), which mirrors all three.

WHAT TO REVIEW, AND HOW TO MAKE IT WORTH SOMETHING

Treat every claim below as UNPROVEN until you check it. Measure; do not read and agree.

1. THE MONETISATION REMOVAL (commit 3d412e72). The owner wants no provisional money figure in
   front of accounting. Verify by GREPPING AND RUNNING, not by trusting the commit message:
     - Does ANY template under templates/modules/daily-ledger/ still render a provisional amount?
     - Does the CSV/PDF EXPORT still carry one? Check dl_reportDefinitions() column lists in
       helpers/reporting.php AND the totals block in dl_generateGovernedReport().
     - Is there an API or JSON surface that still returns a provisional money figure? The chair
       found dl_branchConsolidatedSummary() (handlers-deliveries.php) returning 'provisional_sales'
       and deliberately LEFT IT because it is a data contract, not a display. Decide whether that
       is defensible or whether it defeats the owner's intent, and say which.
     - Did removing the display break any CONSUMER? A column dropped from a report definition
       changes an export's shape. Look for anything that reads those exports or those columns.

2. THE C1 FIX (commit 91cc92cf). dl_reportSalesData() and the admin sales list query now select
   dl.end_source. Ask:
     - Are there OTHER row-level callers of dl_rowIsProvisional() / dl_salesRowStatusLabel() whose
       SELECT still omits end_source, i.e. where C1 is still dead? Grep both names and check every
       call site's SELECT. This is the question the fix was supposed to answer completely.
     - The predicate reads the column defensively ($row['end_source'] ?? ''). Is there a way a
       future caller silently reintroduces the same blindness? Should it be louder?

3. THE SCHEMA DEPENDENCY. dl_provisionalSqlExpr() hard-references dl_daily_ledger.end_source,
   added by migration 074. Assess the deployment risk for a tenant that has NOT run 074:
     - Would the dashboard / sales view / consolidated summary now ERROR where they previously
       worked? Check what the settle path already required before this change.
     - Is the dependency acceptable, and is it gated or documented anywhere?

4. THE BROWSER FIXTURE AND THE TENANT CLOCK. An earlier attempt made two prior-pending specs green
   by having database/seeds/browser_environment.php PIN the test tenant's operating clock
   (operating_timezone -> Pacific/Midway, close_of_day_time -> 00:00). That moved tenant 207's
   business date from 2026-10-05 to 2026-10-04, i.e. the owner's manual testing silently showed the
   wrong day. The chair restored Asia/Manila + 23:59 by hand. The pending change makes the specs
   date-relative instead.
     - Read the CURRENT state of database/seeds/browser_environment.php and the two specs
       (tests/browser/daily-ledger-nextday-entry.spec.js, daily-ledger-prior-ledger-link.spec.js).
     - Does the seed write ANY clock/config key now? Prove it by reading it.
     - Were any ASSERTIONS WEAKENED while making the dates dynamic? Compare each assertion against
       what it was: linkCount > 0, priorStatus === 'open', banner visible, nextStatus === 'open',
       the write/persist checks. A relaxed assertion is the failure mode to catch here.
     - Is the fixture still idempotent across repeated runs?

5. THE MONETISATION DECISION ITSELF. The chair kept provisional UNITS and removed only the AMOUNT,
   relabelled "not counted". Is that coherent with the owner's words, or does it leave a
   half-measure that still invites the confusion the owner described? Give a recommendation, not a
   hedge.

6. SCOPE AND REGRESSION. Run:
     php tests/daily-ledger/daily_ledger_c1_derived_ending_test.php
     php tests/daily-ledger/daily_ledger_pending_not_monetised_test.php
     php tests/daily-ledger/daily_ledger_sales_pending_marker_test.php
     php tests/daily-ledger/daily_ledger_reporting_test.php
     php tests/daily-ledger/daily_ledger_production_controls_test.php
   and report the numbers you ACTUALLY got. If a number differs from what a commit message claims,
   that is a finding.

7. THE HARDEST QUESTION. Name anything in this batch that is ASSERTED BUT NOT PROVEN - a comment
   promising behaviour nobody tested, a guard whose removal would not turn anything red, a test
   that passes for a reason other than the one it names. This session already shipped TWO guards
   that could not fail (an oracle assertion that passed on unfixed code, and an acceptance
   criterion that was unsatisfiable). Assume the pattern repeats and hunt for it.

REPORT
  verdict: PASS | CHANGES_REQUIRED, with your confidence and what would change it
  for each of the 7 areas: what you checked, what you MEASURED, and your finding
  findings, each as: severity / file:line / what is wrong / the evidence / the minimal fix
  anything asserted but not proven, with the mutation that would expose it
  what you could NOT check, and why
  the single thing you would change first if only one change were possible

Be specific and cite file:line. Prefer a measured number to an opinion. If the batch is sound, say
so plainly and briefly - do not manufacture findings to look thorough.
PROMPT_EOF
)"

lane_model_run "$LANE_MODEL_CHAIN" "$PROMPT" /tmp/sol-batch-review
rc=$?
echo "lane: sol-batch-review — completed by: ${LANE_MODEL_USED:-<none>} (rc=$rc)"
exit "$rc"
