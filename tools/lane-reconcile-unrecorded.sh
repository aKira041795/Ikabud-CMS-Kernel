#!/usr/bin/env bash
#
# Lane: reconcile-unrecorded — an UNRECORDED day must not be reported as a mismatch.
#
# CONTRACT (AUTHORITATIVE): .ai/commissary-reconcile-unrecorded.contract.md
# ACCEPTANCE GATE:          tools/lane-reconcile-unrecorded-acceptance.sh
# MUST NOT REGRESS:         tools/lane-reconcile-dispatch-acceptance.sh
#                           tools/lane-reconcile-legacy-shift-acceptance.sh
#
# Do NOT hard-code a model chain: lane-model.sh supplies it from tools/model-chain.txt.

cd /var/www/html/applicationostest || exit 1
source tools/lane-model.sh

PROMPT="$(cat <<'PROMPT_EOF'
You are making ONE correction to verification code in the Ikabud repo at
/var/www/html/applicationostest. It is SMALL and BOUNDED. Read the contract FIRST:

    .ai/commissary-reconcile-unrecorded.contract.md

## WHAT CAME BEFORE YOU (all verified - do not redo, do not weaken)

Two lanes already landed, committed as 8b96ccee:

1. dl_reconcileCommissaryDispatch() reconciles the commissary projection against the single
   departure derivation. It is READ-ONLY and reuses dl_commissaryDepartedQtyByProduct().
2. tools/lane-consignee-depletion-probe.php criteria B/C/D no longer pass vacuously - they
   FAIL when checked === 0. (They used to report a full green PASS having compared nothing.)
3. A legacy NULL-shift projection row is reported as 'uncomparable' instead of being compared
   against the all-shift sum (which produced a false mismatch).

You are fixing the THIRD instance of the same class: a day with no projection row being
reported as a disagreement.

## THE DEFECT - already MEASURED for you, do not re-derive it

The function reports kind='missing_row' INSIDE 'mismatches' when a departure exists for a
(commissary, date) with NO projection row.

The invariant `projection.dispatched_qty == derived(commissary, date, shift)` is UNDEFINED for
such a day. There is no projection row to compare against. A day with no recorded sheet is not a
day the projection DISAGREES; it is a day the projection does not COVER.

This is not a corner case on tenant 207:
- 43 dates carry posted deliveries; 42 of them have NO projection row at all
- those 42 dates carry 95,437 units of committed departures
- only 1 of 132 posted deliveries ever produced a dl_delivery_ledger_effects row, so the
  projection-writing effect path is newly wired and the rest predate it

Measured, 2026-10-02 (37 departures, 2544 units, no projection row):

    VERDICT=FAIL reasons=unrecorded day reported 37 mismatch(es) (e.g. missing_row p22 projection=0 derived=1)

A guard that reports 95,437 units of EXPECTED HISTORY as disagreement is untrustworthy for
exactly the reason it was built.

## THE FIX

In dl_reconcileCommissaryDispatch() (modules/daily-ledger/handlers.php):

A departure for a (commissary, date, shift) scope whose projection bucket is ABSENT must NOT
appear in 'mismatches'. Report it in a dedicated bucket:

    'unrecorded' => [
        ['commissary_id'=>int,'date'=>string,'shift'=>?string,'product_id'=>int,'derived'=>int],
        ...
    ],

- 'checked' must NOT count them (no projection row existed to compare).
- 'mismatches' must contain ONLY genuine disagreements: a projection row EXISTS and its
  dispatched_qty differs from the derivation. After your change `kind` should only ever be
  'value'. Keep the key for shape stability but stop emitting 'missing_row' into it.
- The existing 'uncomparable' bucket (legacy NULL shift) is UNCHANGED.

In tools/lane-consignee-depletion-probe.php:

- Surface the unrecorded count in the B/C/D detail text when non-zero, so an unrecorded day
  stays VISIBLE. It must NOT by itself turn a criterion red.

## THE COUNTER-RISK - THIS IS THE PART THAT MATTERS MOST

The danger is DROPPING the departures. A guard that stops noticing departures is WORSE than one
that over-reports, because the failure is silent. The departures must still be reported - just
not called a disagreement.

The acceptance gate pins this: criterion 2 requires the 37 departures to appear in a dedicated
bucket OUTSIDE mismatches. If you make them vanish, that criterion FAILS. That is correct.

Do NOT be tempted to "fix" the 42 unrecorded dates by backfilling the projection. Whether the
projection SHOULD be backfilled is the OWNER's decision about production data and is explicitly
out of your scope.

## ALSO PINNED - do not regress

- The empty-comparison refusal MUST keep working. Note the new combination: an unrecorded day
  now yields checked === 0 AND a NON-EMPTY 'unrecorded' bucket. That combination must STILL
  refuse. A run that compared no values may never report success.
- The legacy NULL-shift exclusion must not regress: a real NULL-shift bucket stays
  'uncomparable'.
- On tenant 207, 2026-10-07 must still report checked = 1, mismatches = [], uncomparable = [].
  That is the pin against excluding too much - if the real row stops being compared, that is a
  FAIL, not a PASS.

## DO NOT DO THESE

- Do NOT change dl_commissaryDepartedQtyByProduct() or the departure SQL.
- No migration, no schema change, NO DATA CHANGE, no rendering, no product behaviour.
- Do NOT restore any `$rows === [] ||` escape.
- `git add -A` is forbidden. Do NOT commit.

## FACTS ALREADY ESTABLISHED - do not re-derive, do not contradict

- Tenant 207 = baronledger. Bootstrap as the gates do (bootstrap.php, module-manager.php,
  module helpers.php, handlers-deliveries.php, handlers.php, modulePushContext('daily-ledger'),
  app()->dbForTenant(207)).
- 2026-10-07 is the ONLY date with a projection row: commissary 18, product 53, shift 'AM',
  dispatched 3.
- 2026-10-02 has 37 attributable departures (2544 units) and NO projection row.
- Only ONE commissary exists: branch id 18.
- dl_production_movements, dl_commissary_ledger and dl_production_runs are all EMPTY (0 rows).

## ENVIRONMENT TRAPS (measured in this repo - all real)

- information_schema is FORBIDDEN under modulePushContext (it throws; a try/catch turns it into
  a SILENT FALSE). Use SHOW COLUMNS.
- $ctx->json() EXITS the process. Run fixture-driving handler calls in a CHILD PROCESS.
- ONLY_FULL_GROUP_BY is ON: every aggregate SELECT must GROUP BY its non-aggregated columns.
- Do not end an acceptance command with a pipe (`| head`): the exit code becomes the pipe's and
  a real failure reads as success.
- Write regexes containing \b or \d into a FILE with single-quoted patterns.

## VERIFY BEFORE YOU REPORT

- php -l on both touched files.
- ALL THREE gates must exit 0:
    bash tools/lane-reconcile-unrecorded-acceptance.sh      # exits 1 before you
    bash tools/lane-reconcile-dispatch-acceptance.sh        # must stay 0
    bash tools/lane-reconcile-legacy-shift-acceptance.sh    # must stay 0
- Prove the empty-comparison refusal still fires.
- Confirm 2026-10-07: checked=1, mismatches=[], uncomparable=[], unrecorded=[].
  Confirm 2026-10-02: mismatches=[], unrecorded non-empty, checked=0.
- Both storage/logs/app.log and storage/logs/error.log must be 0 bytes at the end.

## REPORT

  status:        PASS | FAIL | BLOCKED
  changed:       files with line counts (delta for THIS lane, not cumulative vs HEAD)
  before_after:  the unrecorded gate's result before and after
  buckets:       for 2026-10-02 and for 2026-10-07: checked / mismatches / uncomparable / unrecorded
  no_regression: exit codes of the other two gates
  visibility:    evidence the departures are still reported (not dropped)
  verification:  php -l, all three gate exit codes, the empty-refusal result, both log sizes
  scope:         git status --porcelain
  unresolved:    anything you could not establish

Report BLOCKED with a precise reason rather than improvising. Dropping the departures to reach
green is the exact failure this lane must not introduce.
PROMPT_EOF
)"

lane_model_run "$LANE_MODEL_CHAIN" "$PROMPT" /tmp/lane-reconcile-unrecorded
