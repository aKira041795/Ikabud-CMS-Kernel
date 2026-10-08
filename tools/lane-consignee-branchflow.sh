#!/usr/bin/env bash
#
# Lane: consignee-branchflow — the Consignees SUB-TAB of the Commissary Daily Sheet takes the Branches
# shape: consignees become COLUMNS, BEG/ADDTL become the commissary's shared values.
#
# CONTRACT (AUTHORITATIVE): .ai/consignee-sheet-architecture.contract.md  (slice 10)
#
# Owner, 2026-10-08: "at consignees daily sheet, the consignee name is a row, it must also be the same as
# in branches, columned and vertically set."
#
# STRUCTURE (confirmed by the owner): the Commissary page has five tabs -
#   1. Daily Sheet  <-- the sub-tabs live HERE:  Branches | Consignees
#   2. Inventory    (per-product aggregates; already sums across destinations)
#   3. Deliveries   (DR / provenance / verification workflow)
#   4. Pullouts     5. Summary
#
# WHY IT IS A ROW TODAY: the sub-tab is a CUSTODY ledger, and each consignee owns its own BEG and ADDTL -
# so a consignee can only be a row. On the Branches sub-tab BEG/ADDTL belong to the COMMISSARY (one value
# per product row), which is what lets destinations be COLUMNS. ONE SHARED BEG/ADDTL FORCES DESTINATIONS
# TO BE COLUMNS. That is the whole change.
#
# Do NOT hard-code a model chain: lane-model.sh supplies the canonical chain from tools/model-chain.txt.

cd /var/www/html/applicationostest || exit 1
source tools/lane-model.sh

PROMPT="$(cat <<'PROMPT_EOF'
You are implementing slice 10 in the Ikabud repo at /var/www/html/applicationostest.

Read .ai/consignee-sheet-architecture.contract.md FIRST - slice 10 in it is the authority. It records the
owner's decision, the measured evidence, and a CORRECTION the chair had to make.

PHP 8.5 local. Production is MySQL 5.7 and NO 5.7 server exists locally - label every 5.7 claim
INSPECTION-ONLY.

## THE ONE IDEA
On the Commissary > Daily Sheet, the Branches sub-tab shows
    Product | BEG | ADDTL | {one column per BRANCH} | TOTAL | ACTUAL BAL
where BEG and ADDTL are the COMMISSARY'S. The Consignees sub-tab must take the SAME shape, with consignee
columns in place of branch columns and the SAME BEG/ADDTL. Today it is a custody ledger showing
    CONSIGNEE | PRODUCT | BEG | ADDTL | WITHDRAWALS | ENDING
with the consignee as a ROW, which the owner has flagged as wrong.

## READ THIS BEFORE YOU WRITE ANYTHING - A CORRECTION YOU MUST NOT UNDO
Dispatch to a consignee happens at the CASHIER LEDGER, not the commissary:
  - route /daily-ledger/api/v1/cashier/ledger/dispatch -> apiCreateCashierDispatch
  - handlers.php:9083  $originBranchId = $authResult['branch_id']
  - the modal is included at templates/modules/daily-ledger/cashier/ledger.disyl:344
So a consignee delivery with origin_type='branch' is NORMAL, NOT invalid. An earlier version of this
contract wrongly required a "commissary-only" source and would have broken that working flow. That
requirement is DELETED - do NOT reintroduce it, and do NOT add an origin_type='commissary' filter to the
consignee cells. Pin D protects this.

## WORK
R10.1 Add the seam the gate needs (name it EXACTLY):
    dl_fetchProductionSheetConsigneeCells(PDO $db, string $date, int $commissaryId, ?string $shift): array
  returning
    ['beg_addtl'  => [product_id => ['beg'=>int, 'addtl'=>int]],     // the COMMISSARY's, shared
     'cells'     => [product_id => [consignee_id => qty]],          // consignee = a COLUMN dimension
     'consignees' => [consignee_id => ['code'=>.., 'name'=>..]]]    // for the column headers
  BEG/ADDTL come from the SAME source the Branches sub-tab uses for those two columns (the commissary's
  ledger / dl_fetchCommissaryBeginningSuggestions path) - they must be identical values, not recomputed
  differently. Consignee quantities come from dl_consignee_ledger for that date, scoped to the
  sheet's commissary, and MUST include branch-originated dispatches.

R10.2 Use the seam above in the Consignees sub-tab of
  templates/modules/daily-ledger/admin/commissary.disyl so it renders
    Product | BEG | ADDTL | {one column per CONSIGNEE} | TOTAL | ACTUAL BAL
  and REMOVE the custody columns (WITHDRAWALS, the per-consignee ENDING) from that sub-tab.

R10.3 WIDTH IS BOUNDED BY ACTIVITY. Render a consignee column ONLY for consignees with ledger activity
  that date - keep the existing activity filter. The owner's scrolling concern is ALREADY REAL: at ONE
  consignee the current 6-column table already overflows and scrolls. Do not render a column for every
  active consignee.

R10.4 TOTAL is THIS sub-tab's own destination-column sum, so the printed formula
  BEG + ADDTL - TOTAL - WASTAGE = ACTUAL BAL holds on the Consignees sub-tab too. LABEL that ACTUAL BAL
  distinctly from the Branches sub-tab's (e.g. "Commissary balance after consignee dispatch") - the two
  tabs carry different meanings and must not read as the same figure.

R10.5 THE BRANCHES SUB-TAB MUST NOT CHANGE. Its values stay byte-identical. This slice touches only the
  Consignees sub-tab and the shared assembly it needs.

R10.6 No nav change, no route change, no new page. Modify the sub-tab that already exists.
R10.7 Do NOT change dl_bulkAssignConsigneeProductsCore, the branch ledger mechanism, the feature toggle,
  the sales mode, or the Consignee Dispatch Report shipped in slice 8.

## THE GATE (do not modify it to make it pass)
tools/lane-consignee-branchflow-acceptance.sh runs tools/lane-consignee-branchflow-probe.php and must go
FAIL -> PASS:
  A  consignee cells are keyed by CONSIGNEE (a column dimension, not rows)
  B  PIN: only consignees WITH ACTIVITY that date get a column
  C  PIN: BEG/ADDTL are SHARED commissary values, not per-consignee balances
  D  PIN: a BRANCH-originated consignee dispatch IS included
  E  PIN: the custody columns are gone from the sheet markup
  F  PIN: the branch matrix still returns branch-keyed data only
  G  PIN: no leaked fixtures, settings untouched
The gate measures RED on the unchanged tree today (A-E fail, F/G pass).

## ORACLE (required)
Extend tests/daily-ledger/ with cases asserting OUTCOMES:
  - the cells are keyed by consignee, and a consignee with no activity that date has no column
  - BEG/ADDTL equal the values the Branches sub-tab uses for the same product/date
  - a branch-originated consignee dispatch appears in the cells
  - PIN: the Branches sub-tab's matrix is unchanged
  - PIN: the custody columns are gone from the Consignees sub-tab markup
Label each case discriminating or pin and say what each pin defends. Clean up every fixture row and prove
it. Do not depend on live test data - create your own dated fixture.

## VERIFY
- php -l each changed PHP file; check BOTH storage/logs/app.log and storage/logs/error.log; leave both at
  0 bytes. If a test writes app.log, restore it.
- These must stay green: manifest 125/125, routes 82/82, consignee_admin 11/11, consignee_activity 9/9,
  consignee_isolation 9/9, consignee_toggle 9/9, consignee_product_scope 18/18, consignee_sales_mode
  12/12, commercial_slice8 7/7, b2b 14/14 and 10/10, shell-drift 95/95 if present.
- KNOWN PRE-EXISTING, NOT YOURS: the consignee audit suite has a render pin that fails because
  storage/cache is www-data-owned and the CLI cannot compile an edited template; several suites that
  render commissary.disyl will fail the same way once you edit it. Do not "fix" them; do not use them to
  excuse a failure you introduce.
- YOU CANNOT RENDER an edited template FROM CLI. Verify the markup at SOURCE level and the data at handler
  level; the chair verifies rendering in a browser. Do NOT conclude your work is broken because a CLI
  render fails.
- ENVIRONMENT TRAPS:
    * information_schema is FORBIDDEN under modulePushContext (it throws, and a try/catch turns that into
      a SILENT FALSE). Use SHOW COLUMNS.
    * Run fixture-driving handler calls in a CHILD PROCESS: $ctx->json() EXITS the process, so an
      in-process handler call silently false-passes (rc=0 with no assertions run).
    * A gate criterion that can "pass" for an unrelated reason is a FALSE RED/GREEN. On 2026-10-08 this
      slice's first gate tested a refusal that was actually the cashier date guard returning 403
      "Reference only". Make refusals name their real reason.
    * DiSyL ESCAPES rendered output - JSON reaches the screen as {&quot;x&quot;:1}; HTML-decode before
      scanning markup for JSON.
    * DiSyL TRAP: a JS object literal inside <script> is consumed as a DiSyL tag when '{' is IMMEDIATELY
      followed by an identifier AND the braces contain a ? : ternary - it once shipped JSON.stringify(0)
      and an HTTP 500. Keep object literals multi-line.
    * Write regexes containing \b or \d into a FILE with single-quoted patterns; inside a PHP
      double-quoted string \b becomes a backspace and silently kills the pattern.
    * MySQL 5.7: no CTEs, no window functions, no JSON_TABLE.

## REPORT
Report BLOCKED with a precise reason rather than improvising around the contract. A BLOCKED report is a
correct outcome; a PASS claimed without evidence is not. State which parts you verified and which you
could not.
PROMPT_EOF
)"

lane_model_run "$LANE_MODEL_CHAIN" "$PROMPT" /tmp/lane-consignee-branchflow
