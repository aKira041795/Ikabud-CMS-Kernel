#!/usr/bin/env bash
#
# Lane: reconcile-dispatch — make the dispatch reconciliation UNABLE to pass vacuously.
#
# CONTRACT (AUTHORITATIVE): .ai/commissary-dispatch-reconciliation.contract.md
# ACCEPTANCE GATE:          tools/lane-reconcile-dispatch-acceptance.sh
#
# Do NOT hard-code a model chain: lane-model.sh supplies it from tools/model-chain.txt.
#
# WHY THIS LANE EXISTS. The invariant
#     dl_commissary_product_ledger.dispatched_qty  ==  dl_commissaryDepartedQtyByProduct()
# is asserted in that helper's docblock and enforced NOWHERE that can fail.
# tools/lane-consignee-depletion-probe.php criteria B, C and D are each written
# `$rows === [] || <no mismatches>`: when the projection yields no rows the left side is TRUE
# and the criterion reports PASS. Measured 2026-10-08 - pointed at a date with no projection
# rows, the gate printed a FULL GREEN PASS on A-I and exited 0.
#
# So the gate that certified the consignee-depletion work would also certify an EMPTY
# database. This lane repairs that claim. It adds NO product behaviour.

cd /var/www/html/applicationostest || exit 1
source tools/lane-model.sh

PROMPT="$(cat <<'PROMPT_EOF'
You are repairing a verification hole in the Ikabud repo at /var/www/html/applicationostest.
This is a SMALL, BOUNDED task. Read the contract FIRST - it is the authority.

    .ai/commissary-dispatch-reconciliation.contract.md

## THE ONE THING THAT MATTERS

tools/lane-consignee-depletion-probe.php criteria B, C and D are each written:

    $rows === [] || <no mismatches>

When the projection returns NO ROWS, the left side is TRUE, so the criterion reports PASS.
The gate therefore reports a full green result while having compared NOTHING.

This was MEASURED, not inferred. Pointed at a date with no projection rows, the unmodified
gate printed:

    PASS A a consignee dispatch is counted in what left the commissary
    PASS B the identity BEG + ADDTL - TOTAL - WASTAGE = ACTUAL BAL still holds  (no projection rows for the date)
    PASS C the projection dispatched_qty equals the single derivation           (no projection rows)
    PASS D MESHING Inventory and the Daily Sheet share one derivation           (identical for 0 product(s))
    PASS E ... PASS F ... PASS G ... PASS H ... PASS I ...
    PASS: the commissary depletes on consignee dispatch, from ONE derivation shared by every consumer
    GATE EXIT=0

A gate that passes on an empty database certifies nothing. Fix that.

## DELIVERABLE A - one owner for the comparison (READ-ONLY, product code)

Add to modules/daily-ledger/handlers.php, ADJACENT TO dl_commissaryDepartedQtyByProduct()
(that function is at roughly line 877):

    function dl_reconcileCommissaryDispatch($db, ?int $commissaryId = null, ?string $date = null, ?string $shift = null): array

Return shape:

    [
      'checked'    => int,      // how many projection values were ACTUALLY compared
      'mismatches' => [
         ['commissary_id'=>int,'date'=>string,'shift'=>?string,'product_id'=>int,
          'projection'=>int,'derived'=>int,'kind'=>'value'|'missing_row'],
         ...
      ],
    ]

Rules, all of them load-bearing:
- PURE READ. No writes, no DDL, no side effects. It must be safe against production.
- REUSE dl_commissaryDepartedQtyByProduct(). Do NOT re-derive the departure SQL. Duplicating
  the derivation is the exact defect the coherence audit condemned.
- 'checked' counts every value actually compared. When nothing was compared it MUST be 0 -
  the gate distinguishes "compared nothing" from "compared values that were all zero".
- kind='value' when both sides exist and differ; kind='missing_row' when the derivation
  reports a non-zero quantity for a product the projection has no row for.
- Filter arguments are optional; null means "do not filter on this".
- MySQL 5.7 SAFE: no window functions, no CTEs, no JSON_TABLE. Production is 5.7 and there is
  NO 5.7 server locally, so label every 5.7 claim INSPECTION-ONLY.

## DELIVERABLE B - the gate can no longer pass on empty

In tools/lane-consignee-depletion-probe.php:

- Criteria B, C and D must call dl_reconcileCommissaryDispatch() instead of each holding its
  own copy of the comparison.
- Each must FAIL when checked === 0, with an explicit reason, e.g.
  `no projection rows to compare`.
- They must still PASS when values were compared and all agree.
- They must REPORT the checked count in their detail text, e.g. `checked 178 value(s)`.

CRITICAL SUBTLETY - do not get this backwards:
  "there were no rows to compare"          -> must FAIL
  "rows were compared and all were zero"   -> must PASS
Conflating these makes the gate either vacuous (the current bug) or unusable.

Removing the `$rows === [] ||` escape must make the gate STRICTER, never looser. Do NOT
weaken any other criterion to reach green.

## DELIVERABLE C - prove the guard can refuse (BOTH directions)

Include in your report a negative control. Inside a TRANSACTION:

  1. perturb one dl_commissary_product_ledger.dispatched_qty by +1
  2. run the reconciliation and show it reports a mismatch naming that product
  3. ROLLBACK

Nothing may persist. Verify afterwards that the healthy tree still reconciles (the chair's
acceptance gate re-runs the healthy check after your perturbation precisely to catch a leak).

A guard never observed refusing is not a guard. A run that reports PASS on your own tests is
NOT sufficient evidence - state the perturbation, the observed mismatch, and the rollback.

## SCOPE - exactly two files

  modules/daily-ledger/handlers.php            (ADD function A; change no existing behaviour)
  tools/lane-consignee-depletion-probe.php     (criteria B/C/D)

PROHIBITED:
  - a new standalone CLI tool (deliberately out of scope; the gate IS the entry point)
  - any change to dl_commissary_product_ledger schema, or any migration, or any data change
  - any change to product behaviour, rendering, or templates
  - any change to the departure SQL inside dl_commissaryDepartedQtyByProduct()
  - storage/backups/**, docs/reviews/windows-desktop-client-feasibility-2026-10-07.md
  - `git add -A`  (the owner has untracked work in this tree)
  - Do NOT commit. Leave the tree for the chair to verify and commit.

## DO NOT "FIX" A MISMATCH

If the reconciliation reports a real mismatch on tenant 207, REPORT IT. Do not change a stored
figure: altering historical numbers is out of scope and requires the owner's decision.

## FACTS ALREADY ESTABLISHED - do not re-derive, do not contradict

- Tenant 207 = baronledger. The login is not needed; bootstrap via the gate's existing pattern.
- The invariant currently HOLDS on tenant 207: 1 projection value checked, 0 mismatches.
  So this lane is a REGRESSION GUARD, not a bug fix. It adds no product behaviour.
- Only ONE commissary exists: branch id 18 (dl_branches.is_commissary=1).
- Only ONE projection row carries values: commissary 18, product 53, 2026-10-07, shift AM,
  beg 0, produced 0, dispatched 3, wastage 0, actual_end_qty NULL, end_source NULL,
  calc_variance NULL.
- dl_production_movements is EMPTY (0 rows) and dl_commissary_ledger is EMPTY (0 rows) and
  dl_production_runs is EMPTY (0 rows). They are NOT write models in use in this tenant.
  Therefore a FOUR-input reconciliation is impossible: beg/produced/wastage have no write
  model in use. ONLY dispatched_qty is reconcilable. Do not attempt the others.
- The live dispatch write model is dl_deliveries (132 rows) + dl_delivery_items (2195).
  dl_deliveries carries origin_type/origin_id/resolved_origin_id, destination_type,
  consignee_id, delivery_date, production_shift, status, delivery_kind.
- A consignee dispatch has origin_type='branch' (the cashier performs it) with the consignee
  supplying commissary via dl_consignees.assigned_commissary_id. That is WHY the helper has
  no origin filter - do not add one.

## ENVIRONMENT TRAPS (measured in this repo - all real)

- information_schema is FORBIDDEN under modulePushContext (it throws; a try/catch turns it
  into a SILENT FALSE). Use SHOW COLUMNS.
- $ctx->json() EXITS the process. Run any fixture-driving handler call in a CHILD PROCESS.
- ONLY_FULL_GROUP_BY is ON. Every aggregate SELECT must GROUP BY its non-aggregated columns;
  a missing GROUP BY is a hard 1140 error that silently suppresses output.
- Do not end an acceptance command with a pipe (`| head`): the exit code becomes the pipe's
  and a real failure reads as success.
- storage/cache/compiled is www-data-owned, so you CANNOT render an edited template from CLI.
  This lane should not need to render anything.
- Write regexes containing \b or \d into a FILE with single-quoted patterns.

## VERIFY BEFORE YOU REPORT

- php -l on both touched files.
- Run: bash tools/lane-reconcile-dispatch-acceptance.sh
  On the UNCHANGED tree it exits 1 with 2 failures. It must exit 0 when you are done.
- Both storage/logs/app.log and storage/logs/error.log must be 0 bytes at the end.
- git status --porcelain must show only the two files you touched (plus the owner's untracked
  docs/reviews/windows-desktop-client-feasibility-2026-10-07.md).

## REPORT

Report in this shape:
  status:            PASS | FAIL | BLOCKED
  changed:           the files, with line counts
  reconciliation:    the checked count on the healthy tree, and the mismatch count
  empty_refusal:     what the gate now does on a date with no projection rows, with the exit code
  perturbation:      the perturbation applied, the observed mismatch, and confirmation of ROLLBACK
  verification:      php -l results, the acceptance gate's exit code, both log sizes
  scope:             git status --porcelain output
  unresolved:        anything you could not establish

Report BLOCKED with a precise reason rather than improvising or weakening the gate.
A lane that reports PASS on its own tests has not proved the guard can REFUSE. Show the refusal.
PROMPT_EOF
)"

lane_model_run "$LANE_MODEL_CHAIN" "$PROMPT" /tmp/lane-reconcile-dispatch
