#!/usr/bin/env bash
#
# Lane: consignee-product-scope — make the product modal's scope control cover CONSIGNEES, not just
# branches.
#
# CONTRACT (AUTHORITATIVE): .ai/consignee-product-scope.contract.md
#
# Owner, 2026-10-08: "at edit product: 1. All active branches and consignees 2. Only selected branches
# and consignees - emit the consignees list here"
#
# THE PRECEDENT ALREADY EXISTS — mirror it, do not invent a mechanism.
# The BRANCH half is already built and working:
#   dl_products.assignment_mode ENUM('all_active','specific') default 'all_active'
#   dl_normalizeAssignmentMode()            handlers.php:5860
#   dl_targetBranchIdsForAssignmentMode()   handlers.php:5889
#   dl_applyProductAssignmentMode()         handlers.php:5936   (all_active ADDITIVE, specific removes diff)
#   dl_setBranchProductActive()             handlers.php:5775
#   new branch auto-assigns all_active products   handlers.php:17063
#   Add modal UI  products.disyl:352-370    Edit modal UI  products.disyl:429-445
# The CONSIGNEE half must become its faithful twin. There is no per-pair consignee setter and no
# consignee mode column today — that is the gap this slice fills.

cd /var/www/html/applicationostest || exit 1
source tools/lane-model.sh

# Do NOT hard-code a chain here. lane-model.sh already sets $LANE_MODEL_CHAIN from the single source
# tools/model-chain.txt when a lane does not override it, and that file's own header records why:
# "lane scripts used to hard-code their own two-model list, so when both providers became unavailable the
# work simply STOPPED". This script originally overrode it with a hand-ordered TWO-model list, and the
# harness warned "carries fewer than 3 models - one exhausted provider ends the work" on 2026-10-08.
# Use the canonical chain; the harness skips an exhausted model and moves to the next.

PROMPT="$(cat <<'PROMPT_EOF'
You are implementing consignee support in the product modal's assignment control, in the Ikabud repo at
/var/www/html/applicationostest.

Read .ai/consignee-product-scope.contract.md FIRST - it is the authority. It contains the measured
branch precedent you must mirror, four chair decisions you must not relitigate, the prohibited list, and
the acceptance criteria.

PHP 8.5 local. Production is MySQL 5.7 and NO 5.7 server exists locally - label every 5.7 claim
INSPECTION-ONLY.

## WHAT THE USER WANTS, IN THEIR WORDS
At edit product: "1. All active branches and consignees  2. Only selected branches and consignees" and
"emit the consignees list here". The picker must list consignees next to the branches, under the one
mode control. Do BOTH the Add and the Edit modal (contract decision 3).

## THE ONE SEMANTIC THAT MUST NOT BREAK
'all_active' is ADDITIVE. It assigns every active target and NEVER strips an existing pair. The branch
code proves this: handlers.php:5944 sets \$toRemove to EMPTY when the mode is 'all_active'. If your
consignee implementation makes 'all_active' destructive, editing a product silently wipes its branch and
consignee pairs - that is the single dangerous outcome of this slice and gate pin E exists to catch it.

Two related traps:
  * a MISSING mode must read as 'all_active', never as 'specific'. An older or external caller that does
    not send the key must not have its assignments wiped.
  * a GARBAGE mode must also read as 'all_active'. Coerce to the safe default, never to the destructive
    one.

## WORK
R1 Migration 085: add to dl_products
     consignee_assignment_mode ENUM('all_active','specific') NOT NULL DEFAULT 'all_active'
   Use the ENUM - it matches dl_products.assignment_mode exactly (measured: enum('all_active','specific')
   default 'all_active') and it is the last line of defence if a normalizer is ever bypassed. Guarded and
   rerun-safe exactly like 081-084, registered in module.json. VERIFY 085 is still free before using it:
   the highest existing is 084, migration 071 was double-claimed on 2026-10-02, and 041 is a pre-existing
   duplicate you must NOT renumber.
R2 The helpers, mirroring the branch four: dl_normalizeConsigneeAssignmentMode(),
   dl_targetConsigneeIdsForAssignmentMode(), dl_applyProductConsigneeAssignmentMode(), plus a per-pair
   setter mirroring dl_setBranchProductActive(). Reuse dl_bulkAssignConsigneeProductsCore() ONLY if it
   genuinely fits the product-centric direction - do NOT change its contract.
R3 apiUpdateProduct and the create path accept consignee_assignment_mode + consignee_ids, persisting
   both the column and the dl_consignee_products rows inside the existing transaction.
R4 BOTH modals: relabel the two options to "All active branches and consignees" / "Only selected branches
   and consignees"; emit {foreach consignees as c} checkboxes under a visible "Consignees" heading next
   to the branch list (the view ALREADY receives consignees - handlers.php:15717); send consignee_ids from
   the ticked consignee checkboxes when the mode is 'specific' and [] when 'all_active'; extend
   openEditProduct() so the modal opens reflecting the product's real consignee assignment instead of
   defaulting to "all"; and expose the product's assigned consignee ids in the read path feeding the
   modal, mirroring the assigned_branch_ids GROUP_CONCAT subquery at handlers.php:15799 / :15845.
   Emit the list with {foreach consignees as c} - do NOT build it in JS.
R5 When a NEW consignee is created, products with consignee_assignment_mode='all_active' are assigned to
   it, mirroring the new-branch behaviour at handlers.php:17063-17070. Products set to 'specific' are
   NEVER auto-assigned to a consignee created later. Keep the active-products-only restriction.
R6 consignee_assignment_count (handlers.php:18872) and the sheet's no-products message must reflect the
   new rows correctly.
R7 With an old payload carrying no consignee keys, behaviour is byte-identical to today. Do not change
   the branch mechanism, the consignee-centric picker tab, or dl_bulkAssignConsigneeProductsCore's
   contract.

## PROHIBITED
- do NOT build two independent all/specific controls (contract decision 1 - that is a separate slice; the
  single shared control cannot express "all branches but only these consignees", and that limitation is
  DELIBERATE and recorded, not an oversight to fix here)
- do NOT auto-assign a 'specific' product to a consignee created later
- do NOT make 'all_active' strip existing pairs; do NOT make a missing mode mean "remove all"
- do NOT touch dl_branch_products from the consignee path
- do NOT add a branch-style unassignment blocker to consignees: "Consignee custody has no nullable
  ending-entry workflow, so there is no branch-style unfinished-ending blocker" (handlers.php:6081-6086)
  is a PRE-EXISTING documented decision, not an omission
- do NOT weaken, skip or delete an existing test; no new dependencies
- MySQL 5.7: no CTEs, no window functions, no JSON_TABLE

## THE GATE (do not modify it to make it pass)
tools/lane-consignee-scope-acceptance.sh runs tools/lane-consignee-scope-probe.php and must go from
FAIL to PASS. It asserts: A the column exists and defaults to all_active; B a missing mode coerces to
all_active; C a garbage mode coerces to all_active; D 'specific' assigns exactly the ticked consignees
and deactivates the unticked one; E PIN 'all_active' is ADDITIVE (never decreases the pair count, reaches
every active consignee, keeps the pre-existing pair); F PIN the consignee path never touches
dl_branch_products; G PIN the gate leaves the database exactly as it found it.

The helper names in the gate are the ones R2 specifies. The gate's fixtures live in ONE transaction that
is rolled back, and it self-cleans any leftover S7GATE rows first so a leaking earlier run cannot poison
it. Every pin in it has been validated in BOTH directions before dispatch (a stripping stub reddens E
alone, a branch-touching stub reddens F alone, a committing stub reddens G alone, a no-op control leaves
all pins green) - so a red pin means YOUR code broke that property, not that the gate is flaky.

## ORACLE (required)
Extend tests/daily-ledger/ with cases asserting OUTCOMES:
  - the column exists and defaults to all_active
  - a missing/garbage mode coerces to all_active, never to specific/strip
  - 'specific' assigns exactly the ticked consignees and deactivates the rest
  - PIN: 'all_active' is additive - a product with pre-existing consignee AND branch pairs keeps every one
    of them and gains the rest
  - PIN: the consignee path never touches dl_branch_products
  - PIN: an old payload with no consignee keys leaves the branch side byte-identical
Label each case discriminating or pin and say what each pin defends. Clean up every fixture row you
create and prove you did.

## VERIFY
- php -l each changed PHP file; check BOTH storage/logs/app.log and storage/logs/error.log; leave both at
  0 bytes
- these stay green: daily_ledger_manifest_test.php 125/125, daily_ledger_routes_test.php 82/82,
  daily_ledger_consignee_admin_test.php 11/11, daily_ledger_consignee_activity_test.php 9/9,
  daily_ledger_consignee_isolation_test.php 9/9, daily_ledger_consignee_sales_mode_test.php 9/9,
  b2b 14/14 and 10/10
- KNOWN PRE-EXISTING, NOT YOURS: daily_ledger_consignee_audit_test.php has a render pin that fails
  because storage/cache is www-data-owned and the CLI cannot compile the template - the page renders
  correctly live. Eight further suites are red with counts identical at HEAD, and
  daily_ledger_handlers_test.php aborts resolving the base db. Do not fix them; do not use them to excuse
  a failure you introduce.
- YOU CANNOT RENDER products.disyl FROM CLI. storage/cache/compiled is www-data-owned, so once you edit
  that template the CLI cannot compile it and any render-based check would be a permanent false red. Do
  not write one and do not conclude your work is broken because of it - verify the emitted list in a
  browser if you can, and otherwise report that the chair must. The gate deliberately covers only the
  handler/data semantics for this reason.
- ENVIRONMENT TRAPS you must respect:
    * information_schema is FORBIDDEN under modulePushContext ("Access to system schema
      'information_schema' is forbidden for modules"). It throws, and a try/catch turns that into a
      SILENT FALSE. Use SHOW COLUMNS.
    * Run fixture-driving handler calls in a CHILD PROCESS: \$ctx->json() EXITS the process, so an
      in-process handler call silently false-passes (rc=0 with no assertions run).
    * DiSyL ESCAPES rendered output, so a value reaches the screen as {&quot;x&quot;:1}. HTML-decode
      before scanning markup for JSON.
    * DiSyL TRAP: a JS object literal in a <script> is consumed as a DiSyL tag when '{' is IMMEDIATELY
      followed by an identifier AND the braces contain a ? : ternary - that shipped JSON.stringify(0) and
      an HTTP 500 in slice 1. Keep object literals multi-line.
    * Write regexes containing \b or \d into a FILE with single-quoted patterns; inside a PHP
      double-quoted string \b becomes a backspace and silently kills the pattern.
    * Prefer SHOW COLUMNS over information_schema, and remember MySQL 5.7 has no CTEs or window functions.

## REPORT
Report BLOCKED with a precise reason rather than improvising around the contract. A BLOCKED report is a
correct outcome when the contract cannot be satisfied; a PASS claimed without evidence is not. State
explicitly which parts you verified and which you could not.
PROMPT_EOF
)"

lane_model_run "$LANE_MODEL_CHAIN" "$PROMPT" /tmp/lane-consignee-scope
