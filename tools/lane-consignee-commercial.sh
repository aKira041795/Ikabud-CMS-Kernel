#!/usr/bin/env bash
#
# Lane: consignee-commercial-out — take commercial data OFF the production stock sheet.
#
# CONTRACT (AUTHORITATIVE): .ai/consignee-sheet-architecture.contract.md  (slice 8)
#
# Owner, 2026-10-08: "sales data for consignees - right now at production consignees tab - move to its
# own view under sales view menu". The owner also confirmed the priority order: "#4 first (independent,
# low-risk, removes money from the stock sheet) -> then #3".
#
# WHY THIS SLICE EXISTS (the latent defect it closes):
# dl_fetchConsigneeSheetRows() computes SUM(l.addtl) AS sold_qty and
# SUM(l.addtl * price_snapshot) AS for_collection UNCONDITIONALLY. Only the TEMPLATE gates them behind
# the sales mode. So a money figure sits one template edit away from leaking back into a stock sheet.
# Remove it from the QUERY, not just the markup - that is the whole point of the slice.
#
# Do NOT hard-code a model chain: lane-model.sh supplies the canonical chain from tools/model-chain.txt.

cd /var/www/html/applicationostest || exit 1
source tools/lane-model.sh

PROMPT="$(cat <<'PROMPT_EOF'
You are implementing slice 8 of the consignee sheet-architecture change in the Ikabud repo at
/var/www/html/applicationostest.

Read .ai/consignee-sheet-architecture.contract.md FIRST - it is the authority. It states the three
subjects (production / custody / commercial) and their single homes, the measured facts, the prohibited
list, and this slice's acceptance criteria.

PHP 8.5 local. Production is MySQL 5.7 and NO 5.7 server exists locally - label every 5.7 claim
INSPECTION-ONLY.

## THE ONE IDEA
The Production Daily Sheet is a QUANTITY document. A peso figure on it has a different reader and a
different decision from a stock position. Money belongs in Sales, and only there.

## WORK
R8.1 Remove SOLD and FOR COLLECTION from the consignee tab of the production sheet, remove the
  sales-mode banner from that tab, and DROP sold_qty / for_collection FROM THE QUERY
  (dl_fetchConsigneeSheetRows in modules/daily-ledger/handlers.php, around lines 18639-18660). Query-level
  removal is required: leaving them in the query leaves the leak one template edit away.
R8.2 Add the new view: Sales -> Consignee Dispatch Report. Row per dispatch line: Date | Consignee |
  Product | Quantity | Unit price (price snapshot) | Dispatch value | Verification (DR/provenance status)
  | Collection status. Source the quantity and price snapshot from the existing dispatch records -
  dl_consignee_ledger carries price_snapshot (measured: 4.00 on the 2026-10-07 row) and dl_deliveries
  carries the DR number and provenance status. Invent no new ledger.
  Give the row-builder a discoverable name - the gate looks for dl_consigneeDispatchReportRows() first,
  then dl_fetchConsigneeDispatchReport(); use one of those two so the gate can find it.
  Return an array carrying 'sales_mode' (the active mode) and the rows.
R8.3 Label it honestly. It must NOT be called Sales or Receivables and must present no revenue and no AR
  figure. There is NO revenue and NO AR for consignees - the consignee credit touches only
  dl_consignee_ledger. Wording must make clear the value is DISPATCH VALUATION FROM RECORDED PRICE
  SNAPSHOTS: an estimate of what may be collectible, not income and not a receivable.
R8.4 Respect the sales mode. In 'consignment' mode there are no sold quantities: the view must say so in
  WORDS (e.g. "sold pieces are not recorded yet"), and the payload must carry NO money total - null, not
  0 and not PHP 0.00, which would read as a real zero. In 'order' mode show the dispatch valuation.
R8.5 Respect the feature toggle. When consignee_enabled is off the view must not offer NEW work, but
  already-recorded dispatches must stay readable. History is never hidden by a settings change.
R8.6 Do NOT change dl_bulkAssignConsigneeProductsCore, the branch ledger mechanism, or the assignment
  semantics proven in f5d9c215.
R8.7 RE-POINT THE SALES-MODE TEST, DO NOT WEAKEN IT.
  tests/daily-ledger/daily_ledger_consignee_sales_mode_test.php (committed in 035d58f3) asserts the
  ORDER-mode sheet renders 'FOR COLLECTION' and 'PHP 87.50' and that the row carries sold_qty /
  for_collection (lines ~63 and ~75). This slice moves that money off the sheet, so those assertions now
  encode a SUPERSEDED requirement. Update them to assert the NEW contract: neither the sheet markup nor
  dl_fetchConsigneeSheetRows()'s returned row carries a money figure in EITHER mode, and the same figure
  appears on the new report instead. Do NOT simply delete the old assertion - its replacement must be at
  least as strong, and the diff must make the swap visible. If you believe this cannot be done without
  weakening the suite, report BLOCKED.
  NOTE: dl_selling_account_ledger (migration 029) has its OWN sold_qty / gross_amount for SELLING
  ACCOUNTS (B2B). Different subject, NOT touched by this slice. The grep hits in
  handlers-deliveries.php:818-940 belong to it - leave them alone.

## THE GATE (do not modify it to make it pass)
tools/lane-consignee-commercial-acceptance.sh runs tools/lane-consignee-commercial-probe.php and must go
from FAIL to PASS:
  A the consignee sheet QUERY no longer returns a money field
  B the production sheet markup no longer references the money columns
  C the Consignee Dispatch Report returns dispatch lines with quantity and dispatch value
  D PIN: in consignment mode the report reports the mode and fabricates no money
  E PIN: disabling the feature leaves recorded consignee history readable AND UNCHANGED
  F PIN: the gate restored the setting it changed
  G PIN: the gate leaked no fixture rows
Every pin has been validated in BOTH directions before dispatch: a money-in-consignment stub reddens D
alone, a hide-history mutation reddens E alone (enabled=1 disabled=0), a no-op control leaves all pins
green, and a correct stub makes C and D pass - so the gate is SATISFIABLE, not a permanent false red.
A red pin therefore means YOUR code broke that property, not that the gate is flaky.

## ORACLE (required)
Extend tests/daily-ledger/ with cases asserting OUTCOMES:
  - the sheet query returns no money field, in either sales mode
  - the sheet markup references no money column
  - the report returns dispatch lines with quantity and a price-snapshot-derived value
  - PIN: consignment mode reports the mode and carries no money total (null, not zero)
  - PIN: disabling the feature leaves recorded consignee history readable and unchanged
  - PIN: branch-side production values are untouched by this slice
Label each case discriminating or pin and say what each pin defends. Clean up every fixture row and prove
you did.

## VERIFY
- php -l each changed PHP file; check BOTH storage/logs/app.log and storage/logs/error.log; leave both at
  0 bytes. If a test writes app.log, restore it.
- These must stay green: manifest 125/125, routes 82/82, consignee_admin 11/11, consignee_activity 9/9,
  consignee_isolation 9/9, consignee_toggle 9/9, consignee_product_scope 18/18, b2b 14/14 and 10/10.
- KNOWN PRE-EXISTING, NOT YOURS: the consignee audit suite has a render pin that fails because
  storage/cache is www-data-owned and the CLI cannot compile an edited template; eight further suites are
  red with counts identical at HEAD; daily_ledger_handlers_test.php aborts resolving the base db. Do not
  fix them; do not use them to excuse a failure you introduce.
- YOU CANNOT RENDER an edited template FROM CLI. storage/cache/compiled is www-data-owned, so once you
  edit commissary.disyl the CLI cannot compile it and a render check would be a permanent false red.
  Verify the markup at SOURCE level and the data at handler level; the chair verifies rendering in a
  browser. Do NOT conclude your work is broken because a render fails in CLI.
- ENVIRONMENT TRAPS:
    * information_schema is FORBIDDEN under modulePushContext (it throws, and a try/catch turns that
      into a SILENT FALSE). Use SHOW COLUMNS.
    * Run fixture-driving handler calls in a CHILD PROCESS: $ctx->json() EXITS the process, so an
      in-process handler call silently false-passes (rc=0 with no assertions run).
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

lane_model_run "$LANE_MODEL_CHAIN" "$PROMPT" /tmp/lane-consignee-commercial
