#!/usr/bin/env bash
#
# Lane: consignee-destinations-slice3 — consignee administration.
#
# CONTRACT (AUTHORITATIVE): .ai/consignee-destinations-slice3.contract.md
#
# Owner, 2026-10-08:
#   "what i'd like at the add consignee/edit added: 1. Area 2. Address 3. Price group"
#   "products view, add tab for 'show in consignees' also"
#   and, settling the design: "your consignee solution is better than mine. keep it"
#
# THE KEYWORD IS "keep it": the consignee stays a SEPARATE ENTITY (dl_consignees, its own Consignees tab,
# its own Add/Edit). Do NOT merge it into dl_branches and do NOT add a "Type: Branch|Consignee" selector
# to the Add/Edit branch form - that was the rejected alternative.
#
# THE PART THAT IS EASY TO MISS: adding Price Group to a consignee makes slice-1 code wrong in a way that
# would otherwise go unnoticed. dl_applyConsigneeLedgerDelta credits at dl_defaultPriceGroupId()
# (handlers.php:2917), ignoring the consignee, so the new field would be DECORATIVE and the consignee
# sheet would price goods at the wrong group. Fix it and prove it both ways.

cd /var/www/html/applicationostest || exit 1
source tools/lane-model.sh

LANE_MODEL_CHAIN="openai-codex/gpt-5.6-sol,openai-codex/gpt-5.6-terra,deepseek-v4-flash"

PROMPT="$(cat <<'PROMPT_EOF'
You are implementing slice 3 of the consignee feature in the Ikabud repo at
/var/www/html/applicationostest.

Read .ai/consignee-destinations-slice3.contract.md FIRST - it is the authority, including the scope
correction recording the owner's "keep it" decision, the measured column shapes, and the prohibited list.

PHP 8.5 local. Production is MySQL 5.7 and NO 5.7 server exists locally - label every 5.7 claim
INSPECTION-ONLY.

## KEEP THE SEPARATE MODEL
The consignee is a separate entity. Do NOT merge dl_consignees into dl_branches, do NOT add a
"Type: Branch | Consignee" selector to the Add/Edit branch form, and do NOT represent a consignee as a
branches row. The owner explicitly endorsed the separate solution.

## WORK
R1 migration 083 = dl_consignee_products, mirroring dl_branch_products EXACTLY:
   (id, consignee_id, product_id, is_active, created_at), UNIQUE uq_dl_consignee_product
   (consignee_id, product_id), FK consignee_id -> dl_consignees(id) ON DELETE CASCADE,
   FK product_id -> dl_products(id) ON DELETE CASCADE,
   ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci.
   VERIFY 083 IS FREE FIRST - slice 2 already took 082 (082_consignee_discrepancy_effects.sql) and
   migration 071 was double-claimed on 2026-10-02. Guarded/rerun-safe (information_schema + PREPARE
   pattern of 072/080/081/082). Register in module.json. FK column types must match referenced columns
   exactly - MySQL 5.7 rejects a width/signedness mismatch.
R2 migration 084 = add area VARCHAR(100) NULL, address VARCHAR(255) NULL, price_group_id INT UNSIGNED
   NULL to dl_consignees. Mirror dl_branches' widths exactly. Nullable so existing rows and the live
   Lee Plaza record stay valid. Guarded, rerun-safe, registered. VERIFY 084 IS FREE.
R3 apiSaveConsignee accepts, validates and persists area, address, price_group_id. price_group_id must
   be REJECTED if it does not exist (mirror how the branch path validates its commissary). Edit must
   preload all three; the list query and the Consignees table must show them.
R4 the Add/Edit Consignee form (templates/modules/daily-ledger/admin/branches.disyl) gains Area,
   Address and Price Group, reusing the existing price_groups template variable, mirroring the branch
   form's fields and labels so the two read consistently.
R5 Products view: a third tab "Show in Consignees", mirroring the existing "Show in Branches"
   (?tab=assignment) visual and behavioural language - pick a consignee, then the product list with
   per-product assignment, change summary, refusal box and the unsaved-changes guard. Active consignees
   only, ordered by name. Do NOT restyle the existing two tabs.
R6 a bulk assign/unassign endpoint mirroring apiBulkAssignBranchProducts, same semantics (upsert with
   is_active, dl_auditLog on every change as consignee_product_assigned / _unassigned, and the same
   refusal behaviour). Add the route to routes.php AND to workbench-contract.json (slice 1 learned the
   route oracle needs it).
R7 the assignment governs which products appear for a consignee in the consignee production sheet. An
   empty assignment must not render a blank sheet with no explanation - say so in the UI.
R8 THE CONSEQUENCE: dl_applyConsigneeLedgerDelta currently resolves price with
   dl_resolveProductPrice($productId, dl_defaultPriceGroupId(), $ledgerDate) (handlers.php:2917),
   ignoring the consignee. Make the consignee credit resolve through the CONSIGNEE'S price group, falling
   back to dl_defaultPriceGroupId() when it is NULL. Otherwise R3's Price Group is decorative and the
   sheet prices goods wrongly.

## PROHIBITED
- do not merge the tables; no type selector on the branch form
- do not rebuild apiBulkAssignBranchProducts - mirror it
- do not change the consignee->commissary scoping rule
- do not weaken, skip or delete an existing test; no new dependencies
- MySQL 5.7 only: no CTEs, no window functions, no JSON_TABLE; every new table ENGINE=InnoDB

## ORACLE (required)
Extend tests/daily-ledger/ with cases asserting OUTCOMES:
  - saving a consignee persists area/address/price group, and edit preloads them
  - a price group that does not exist is REFUSED
  - R8 BOTH WAYS: a consignee on a non-default price group credits at THAT group's price, and one with
    NULL still credits at the default. This is the case that stops Price Group being decorative.
  - the Show in Consignees assignment persists and is audited, and unassigning is distinguishable
  - an empty assignment is explained, not silently blank
  - the existing "Show in Branches" and "Products" tabs are unaffected (regression pin)
Label each case discriminating or pin and say what each pin defends. Clean up every fixture row.
Run fixture-driving handler calls in a CHILD PROCESS: $ctx->json() exits the process, so an in-process
call silently false-passes a gate (measured on the slice-2 gate, rc=0 with no assertions run).

## VERIFY
- php -l each changed PHP file; check BOTH storage/logs/app.log and storage/logs/error.log
- php ikabud tenant:migrate 207 daily-ledger TWICE (idempotency) and a direct SQL rerun of 083 and 084
- daily_ledger_manifest_test.php 125/125, daily_ledger_handlers_test.php, daily_ledger_routes_test.php
  82/82, daily_ledger_consignee_isolation_test.php 9/9, daily_ledger_consignee_audit_test.php 7/7,
  b2b 14/14 and 10/10 must stay green
- KNOWN PRE-EXISTING, NOT YOURS: eight daily-ledger suites are red with counts identical at HEAD
  (branch_cell_entry, branch_order, defect_fixes_s13, delivery_variance_visibility, dispatch_enforcement,
  finding_type_enum, preserve_cashier_variance, shared_account_latest_holder); four abort in CLI on a
  www-data-owned compiled-template cache; daily_ledger_handlers_test.php currently aborts resolving the
  BASE db (applicationostest.dl_users), verified identical at HEAD. Do not fix them; do not use them to
  excuse a failure you introduce.
- DiSyL TRAP: a JS object literal in a <script> is consumed as a DiSyL tag when '{' is IMMEDIATELY
  followed by an identifier (no space) AND the braces contain a ? : ternary - that shipped
  JSON.stringify(0) and an HTTP 500 in slice 1. Keep such literals multi-line or hoist the ternary.
- Report BLOCKED with a precise reason rather than improvising around the contract.
- Report status PASS | PARTIAL | BLOCKED; files changed; migration idempotency evidence; per-case
  discrimination; the R8 both-ways evidence; anything unverified; anywhere the contract is wrong.
PROMPT_EOF
)"

lane_model_run "$LANE_MODEL_CHAIN" "$PROMPT" /tmp/consignee-slice3
rc=$?
echo "lane: consignee-slice3 — completed by: ${LANE_MODEL_USED:-<none>} (rc=$rc)"
exit "$rc"
