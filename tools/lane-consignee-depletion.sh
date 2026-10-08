#!/usr/bin/env bash
#
# Lane: consignee-depletion — the commissary's stock must deplete when goods are dispatched to a
# consignee, from ONE derivation of "what left this commissary" shared by every consumer.
#
# CONTRACT (AUTHORITATIVE): .ai/commissary-consignee-depletion.contract.md
#
# Owner, 2026-10-08:
#   "consignee movement owned by commissary but dispatch done by cashier"
#   "commissary stocks are depleted when dispatched to consignee. this should clear your logic flow"
#   "write it. it alters historical figures and must be in a good/better way. UI becomes less complicated
#    to manage"
#
# THE OPERATIONAL FLOW (owner-stated): the COMMISSARY produces and delivers to branches AND consignees.
# Branches have a receive step (Receive Stocks modal, or a late DR paper receipt). CONSIGNEES HAVE NO
# RECEIVE STEP - the DR paper IS the admin's proof of delivery. The dispatch action is performed at the
# cashier ledger, so origin_type='branch' is NORMAL and must stay accepted.
#
# Do NOT hard-code a model chain: lane-model.sh supplies the canonical chain from tools/model-chain.txt.

cd /var/www/html/applicationostest || exit 1
source tools/lane-model.sh

PROMPT="$(cat <<'PROMPT_EOF'
You are implementing the commissary-consignee depletion correction in the Ikabud repo at
/var/www/html/applicationostest.

Read .ai/commissary-consignee-depletion.contract.md FIRST - it is the authority. It contains the owner's
ownership model, the measured defect, the "one owner" design, the UI requirement and the prohibited list.

PHP 8.5 local. Production is MySQL 5.7 and NO 5.7 server exists locally - label every 5.7 claim
INSPECTION-ONLY.

## THIS CHANGE ALTERS HISTORICAL FIGURES. READ THAT AGAIN.
Any day with a consignee dispatch will show a LOWER commissary balance afterwards. That is the CORRECTION,
not a regression - but it must be stated in your report, with before/after totals, never applied quietly.

## THE DEFECT (measured, not inferred)
dl_fetchProductionSheetDispatchMatrix() (handlers.php:857-888) filters
    d.origin_type = 'commissary' AND d.destination_type = 'branch'
so EVERY consignee dispatch is excluded. Measured 2026-10-07 (E2E-B2C-001, product 53, qty 3):
    dl_daily_ledger        branch 8 withdraw=3      (the cashier's branch)
    dl_consignee_ledger    consignee 99750 addtl=3
    dl_commissary_product_ledger  0 rows            <-- the commissary is untouched
Net effect: goods leave the bakeshop's ledger, appear on the consignee's, and the commissary still counts
them as on hand. The Inventory tab inherits the same error, which is why the tabs disagree.

## THE FIX IS ONE OWNER, NOT A PATCH
Do NOT copy the branch matrix and add a consignee copy. That leaves THREE places computing the same
number, which is the finding of docs/engineering/commissary-coherence-audit-2026-10-08.md.

Build ONE derivation and make every consumer read it:

    dl_commissaryDepartedQtyByProduct($db, int $commissaryId, string $date, ?string $shift): array
      => [product_id => qty]     // departures to ALL destination types, ANY origin, status='posted'

Name it EXACTLY - the gate calls it. It must have NO destination_type filter (branches AND consignees are
both departures) and NO origin filter (the cashier performs the dispatch).

Consumers that must read it instead of re-deriving:
  1. the Daily Sheet's TOTAL / ACTUAL BAL
  2. dl_commissary_product_ledger.dispatched_qty (the projection)
  3. the Inventory tab's Dispatched Today
  4. calc_variance (via the corrected inputs)

## WORK
R1 the single derivation above, used everywhere. No second copy of the filter anywhere.
R2 THE PROJECTION MUST DEPLETE. dl_commissary_product_ledger.dispatched_qty must include consignee
   departures. FIRST establish with recorded evidence HOW that column is written (the audit found writers
   at handlers.php:2636, 3286, 3365, 3629, 3675, 3975). Do not assume it is derived at read time; do not
   assume it comes from dl_deliveries. State what you found BEFORE changing it, and report BLOCKED if it
   cannot be established.
R3 BACKFILL existing rows: guarded, idempotent, rerun-safe (the 081-085 migration pattern), for past
   dates, so persisted values stop diverging from what the Daily Sheet now shows. Report which rows
   changed and the before/after totals.
R4 TELL THE TRUTH: report the balance change per affected date. Do not restate a figure silently.
R5 UI BECOMES LESS COMPLICATED - this is a REQUIREMENT, not a nicety. The two Daily Sheet sub-tabs
   (Branches | Consignees) become ONE table with a destination filter, not two parallel implementations.
   ACTUAL BAL collapses to ONE figure with ONE meaning on both sub-tabs, replacing the two distinct labels
   introduced by the previous slice. If the change leaves the operator with MORE concepts to reconcile
   than before, it has FAILED this requirement - say so rather than shipping it.
R6 do NOT reintroduce a commissary-only dispatch source. A branch does the dispatching; that is normal.
   Pin F of the gate and pin D of tools/lane-consignee-branchflow-probe.php both protect this.
R7 do NOT change dl_bulkAssignConsigneeProductsCore, the branch assignment mechanism, the feature toggle,
   the sales mode, or the Consignee Dispatch Report.

## THE GATE (do not modify it to make it pass)
tools/lane-consignee-depletion-acceptance.sh runs tools/lane-consignee-depletion-probe.php, FAIL -> PASS:
  A  a consignee dispatch IS counted in what left the commissary
  B  the identity BEG + ADDTL - TOTAL - WASTAGE = ACTUAL BAL still holds
  C  the projection's dispatched_qty equals the single derivation (this is the BACKFILL's correctness)
  D  MESHING: Inventory and the Daily Sheet are the SAME derivation, product by product
  E  PIN: a day with no activity yields no departures
  F  PIN: a branch-originated consignee dispatch is counted
  G  PIN: only dispatched moves - BEG/ADDTL/WASTAGE untouched
  H  PIN: the backfill is idempotent
  I  PIN: no leaked fixtures, settings untouched

C and D together make "one derivation" ENFORCEABLE rather than asserted: a tree with two derivations
cannot pass both. The gate has been validated in BOTH directions before dispatch - a correct stub passes
A/C/D, and a branch-only stub reddens A and F. So a red A or F means YOUR derivation excludes consignees.

## ORACLE (required)
Extend tests/daily-ledger/ with cases asserting OUTCOMES, with their own dated fixture:
  - a consignee dispatch increases what left the commissary, and lowers ACTUAL BAL by exactly that qty
  - the printed identity still holds after the correction
  - the projection equals the derivation (backfill correctness)
  - Inventory's figure equals the Daily Sheet's for the same commissary/product/date, and the assertion is
    built to FAIL if they are ever derived separately again
  - PIN: a day with no consignee dispatch is byte-identical
  - PIN: running the backfill twice changes nothing the second time
Label each case discriminating or pin and say what each pin defends. Clean up every fixture and prove it.

## VERIFY
- php -l each changed PHP file; check BOTH storage/logs/app.log and storage/logs/error.log; leave both 0.
- These must stay green: manifest 125/125, routes 82/82, consignee_admin 11/11, consignee_activity 9/9,
  consignee_isolation 9/9, consignee_toggle 9/9, consignee_product_scope 18/18, consignee_sales_mode
  12/12, commercial_slice8 7/7, consignee_branchflow 9/9, b2b 14/14 and 10/10, shell_drift 95/95.
- KNOWN PRE-EXISTING, NOT YOURS: several suites render commissary.disyl and fail with
  "Failed to write compiled template cache" because storage/cache/compiled is www-data-owned. Do not
  "fix" them; do not use them to excuse a failure you introduce.
- YOU CANNOT RENDER an edited template FROM CLI. Verify at source + handler level; the chair verifies in a
  browser.
- ENVIRONMENT TRAPS:
    * information_schema is FORBIDDEN under modulePushContext (it throws; a try/catch makes it a SILENT
      FALSE). Use SHOW COLUMNS.
    * Run fixture-driving handler calls in a CHILD PROCESS: $ctx->json() EXITS the process.
    * ONLY_FULL_GROUP_BY is ON. Every aggregate SELECT must GROUP BY its non-aggregated columns. A missing
      GROUP BY is a hard 1140 error - this bit the chair's own validation stub on 2026-10-08.
    * A criterion that passes for an unrelated reason is worthless: this area's first gate tested a
      refusal that was actually the cashier date guard returning 403 "Reference only".
    * DiSyL TRAP: a JS object literal in <script> is eaten as a DiSyL tag when '{' is immediately followed
      by an identifier and the braces contain a ? : ternary. Keep object literals multi-line.
    * Write regexes containing \b or \d in a FILE with single-quoted patterns.
    * MySQL 5.7: no CTEs, no window functions, no JSON_TABLE.

## REPORT
Report BLOCKED with a precise reason rather than improvising. State what you established about the
projection's write path, which rows the backfill changed, the before/after totals, and whether R5 (UI
simplification) was achieved or not.
PROMPT_EOF
)"

lane_model_run "$LANE_MODEL_CHAIN" "$PROMPT" /tmp/lane-consignee-depletion
