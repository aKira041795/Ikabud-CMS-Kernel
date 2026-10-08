#!/usr/bin/env bash
#
# Lane: reconcile-legacy-shift — a legacy NULL-shift projection row must not be a FALSE mismatch.
#
# CONTRACT (AUTHORITATIVE): .ai/commissary-reconcile-legacy-shift.contract.md
# ACCEPTANCE GATE:          tools/lane-reconcile-legacy-shift-acceptance.sh
# MUST NOT REGRESS:         tools/lane-reconcile-dispatch-acceptance.sh
#
# Do NOT hard-code a model chain: lane-model.sh supplies it from tools/model-chain.txt.

cd /var/www/html/applicationostest || exit 1
source tools/lane-model.sh

PROMPT="$(cat <<'PROMPT_EOF'
You are repairing ONE defect in verification code in the Ikabud repo at
/var/www/html/applicationostest. It is SMALL and BOUNDED. Read the contract FIRST:

    .ai/commissary-reconcile-legacy-shift.contract.md

## WHAT HAPPENED BEFORE YOU

A previous lane added dl_reconcileCommissaryDispatch() (modules/daily-ledger/handlers.php,
around line 948) and made tools/lane-consignee-depletion-probe.php criteria B/C/D refuse an
empty comparison. That work is GOOD and VERIFIED - do not redo it, do not weaken it.

It also disclosed one limitation honestly. That limitation is now a confirmed defect.

## THE DEFECT - already REPRODUCED for you, do not re-derive it

dl_reconcileCommissaryDispatch() compares each projection bucket against
dl_commissaryDepartedQtyByProduct($db, $cid, $date, $shift). That helper's documented contract
is "a null shift means DO NOT FILTER ON SHIFT" - it returns the ALL-SHIFT total. It CANNOT
express `shift IS NULL`.

So a projection row whose shift IS NULL gets compared against a per-day total it can never
equal, and is reported as a MISMATCH.

This is NOT hypothetical:
- Migration 070 added `shift ENUM('AM','PM') NULL DEFAULT NULL` to dl_commissary_product_ledger
  WITH NO BACKFILL.
- The unique key is uq_dl_cpl_shift (commissary_branch_id, product_id, ledger_date, shift_key)
  where shift_key = COALESCE(shift, '').
- Therefore on ANY tenant with pre-070 history a legacy NULL row and a new 'AM' row for the
  same product/date LEGITIMATELY COEXIST.

Measured on the current tree, transactionally and rolled back:

    VERDICT=FAIL reason=false_mismatch detail=product 53 null-shift row projection=45 derived=3 kind=value

A guard that reports disagreements that do not exist is worse than no guard, because it is
trusted. Fix it without weakening what the guard DOES check.

## THE FIX

In dl_reconcileCommissaryDispatch() (modules/daily-ledger/handlers.php):

A projection bucket whose shift IS NULL must NOT be compared per-shift. The invariant
`projection.dispatched_qty == derived(date, shift)` is UNDEFINED for a pre-shift-era aggregate,
not violated. Do not claim agreement, and do not claim disagreement.

Add a NEW return key alongside 'checked' and 'mismatches':

    'uncomparable' => [
        ['commissary_id'=>int,'date'=>string,'product_id'=>int,'projection'=>int,'reason'=>string],
        ...
    ]

with reason naming the cause, e.g. 'legacy_null_shift_cannot_be_compared_per_shift'.

- checked MUST NOT count these (checked means 'values actually compared').
- mismatches MUST NOT contain them (only genuine value / missing_row disagreements).

In tools/lane-consignee-depletion-probe.php:

- Surface the uncomparable count in the B/C/D detail text when it is non-zero, so a legacy
  tenant's state is VISIBLE rather than silent.
- It must NOT by itself turn the criterion red. The criterion means 'every value I could
  compare agreed'.

## DO NOT DO THESE

- Do NOT change dl_commissaryDepartedQtyByProduct(). Its 'null means no filter' contract is
  used elsewhere and is not yours to change.
- Do NOT touch the departure SQL anywhere.
- Do NOT restore any `$rows === [] ||` escape. The empty-comparison refusal MUST keep working:
  checked === 0 must still make criteria B/C/D FAIL.
- No migration, no schema change, no data change, no rendering, no product behaviour.
- `git add -A` is forbidden (the owner has untracked work in this tree). Do NOT commit.

## THE COUNTER-RISK - READ THIS

The danger with an exclusion is excluding TOO MUCH. If a NORMAL shifted row is wrongly treated
as uncomparable, the guard silently stops checking the row that matters and every gate still
passes. That failure mode is worse than the one you are fixing.

PIN against it: on tenant 207 the projection has exactly ONE row, shift 'AM'. After your change
that row must STILL be compared. So:

    checked must still be 1, mismatches must be [], uncomparable must be []

If your change makes the real row uncomparable, you have broken it - that is a FAIL, not a PASS.

## FACTS ALREADY ESTABLISHED - do not re-derive, do not contradict

- Tenant 207 = baronledger. Bootstrap as the gates do (bootstrap.php, module-manager.php,
  module helpers.php, handlers-deliveries.php, handlers.php, modulePushContext('daily-ledger'),
  app()->dbForTenant(207)).
- The projection holds exactly 1 row: commissary 18, product 53, 2026-10-07, shift 'AM',
  beg 0, produced 0, dispatched 3, wastage 0, actual_end_qty NULL, end_source NULL,
  calc_variance NULL. No NULL-shift rows exist on tenant 207 today.
- Only ONE commissary exists: branch id 18.
- dl_production_movements, dl_commissary_ledger and dl_production_runs are all EMPTY (0 rows).

## ENVIRONMENT TRAPS (measured in this repo - all real)

- information_schema is FORBIDDEN under modulePushContext (it throws; a try/catch turns it
  into a SILENT FALSE). Use SHOW COLUMNS.
- $ctx->json() EXITS the process. Run fixture-driving handler calls in a CHILD PROCESS.
- ONLY_FULL_GROUP_BY is ON: every aggregate SELECT must GROUP BY its non-aggregated columns.
- Do not end an acceptance command with a pipe (`| head`): the exit code becomes the pipe's and
  a real failure reads as success.
- Write regexes containing \b or \d into a FILE with single-quoted patterns.

## VERIFY BEFORE YOU REPORT

- php -l on both touched files.
- `bash tools/lane-reconcile-legacy-shift-acceptance.sh` -> must exit 0 (it exits 1 before you).
- `bash tools/lane-reconcile-dispatch-acceptance.sh` -> must STILL exit 0. Do not regress it.
- Prove the empty-comparison refusal still fires.
- Confirm checked=1, mismatches=[], uncomparable=[] for tenant 207.
- Both storage/logs/app.log and storage/logs/error.log must be 0 bytes at the end.

## REPORT

  status:        PASS | FAIL | BLOCKED
  changed:       files with line counts
  before_after:  the legacy-shift gate's result before and after your change
  no_regression: the reconcile-dispatch gate's exit code
  counter_risk:  evidence that the real 'AM' row is STILL compared (checked = 1)
  verification:  php -l, both gates' exit codes, the empty-refusal result, both log sizes
  scope:         git status --porcelain
  unresolved:    anything you could not establish

Report BLOCKED with a precise reason rather than improvising. Do not exclude rows to reach
green - excluding the row that matters is the exact failure this lane must not introduce.
PROMPT_EOF
)"

lane_model_run "$LANE_MODEL_CHAIN" "$PROMPT" /tmp/lane-reconcile-legacy-shift
