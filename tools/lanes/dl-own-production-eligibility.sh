#!/usr/bin/env bash
#
# Lane: Own production eligibility — hide a product from the Own production choices WITHOUT
# removing it from the cashier ledger.
#
# Client, verbatim 2026-11-14 (translated):
#   "About Own production — can I hide some products? Because the others use it since they no
#    longer use a DR; it should be only for what THEY produced that day."
# Owner:
#   "we have the active/inactive toggle already, but it also hides it from the cashier ledger —
#    it should hide it only from the Own production choices."
#
# DECISION RECORD (read it, do not re-litigate): .ai/consult/own-production-decision-2026-11-14.md
#   D1 = a per-branch-per-product eligibility flag, defaulting to ALLOWED, enforced server-side.
#        NOT a per-day plan. Deliberate: a day-scoped catalogue would put a setup step in front of
#        a 3-tap cashier action, and its empty start-of-day state is what pushes people onto
#        adjustment_add — the exact abuse we are trying to close.
#
# THE CAUSE, MEASURED (verify by reading before editing):
#   Own production has NO product list of its own. templates/modules/daily-ledger/cashier/
#   modal_patch.disyl loadProducts() scrapes `document.querySelectorAll('tr[data-product-id]')` —
#   the cashier LEDGER rows, which come from dl_branch_products WHERE is_active = 1. So the only
#   way to hide a product from Own production was to remove it from the branch, which also removes
#   it from the ledger, the Daily Sheet, dispatch and receive.
#
# WHAT TO BUILD
#   1. Migration modules/daily-ledger/database/migrations/101_branch_product_own_production.sql
#      adds dl_branch_products.allow_own_production TINYINT(1) NOT NULL DEFAULT 1.
#      Idempotent, MySQL 5.7, information_schema guard exactly like 098/100.
#      Register it in modules/daily-ledger/module.json ("migrations" array) — an unregistered
#      migration never runs.
#   2. modules/daily-ledger/handlers.php (or handlers-deliveries.php, wherever
#      dl_setBranchProductActive lives — keep the pair together):
#        dl_setBranchProductOwnProduction($db, int $branchId, int $productId, bool $allow,
#            ?int $actorId = null): array
#          upserts the dl_branch_products row, changes ONLY allow_own_production, and writes one
#          audit row action 'branch_product_own_production_changed' with old -> new.
#          MUST NOT touch is_active.
#        dl_assertOwnProductionAllowed($db, int $branchId, int $productId): void
#          throws RuntimeException when the flag is 0. Message must name the product (real name) and
#          contain the words "own production", and give the remedy ("an admin can allow it").
#   3. Enforce on the SERVER in BOTH write paths, next to the existing
#      `dl_branchHasProduction()` check:
#        modules/daily-ledger/handlers.php   apiSaveCashierWithdrawals  (~line 9809)
#        modules/daily-ledger/handlers-offline.php                        (~line 619)
#      Only for type 'own_production'. Leave adjustment_add alone.
#   4. A dedicated feed: GET /daily-ledger/api/v1/branch/own-production-products
#      (route in modules/daily-ledger/routes.php, handler next to apiToggleBranchProduct) returning
#        { ok, products: [{ id, name, pcs_per_pack }] }
#      = the branch's ACTIVE products whose allow_own_production = 1. Authorize with the same
#      branch scoping the other cashier endpoints use (dl_authorizeBranch / dlCurrentUser).
#   5. modal_patch.disyl: when the type is own_production, fill `this.products` from that feed
#      instead of the ledger DOM. Keep the current behaviour ONLY as a fallback when the fetch
#      fails, and keep the change strictly inside loadProducts() — do not restructure the modal.
#   6. One admin control to flip it: on the existing per-branch product assignment surface
#      (the picker that already calls apiToggleBranchProduct / dl_bulkAssignBranchProductsCore),
#      add an "Own production" checkbox per product that POSTs to a new endpoint
#      /daily-ledger/api/v1/admin/branch-products/own-production { branch_id, product_id, allow }.
#      Match that surface's existing row controls and permissions; do not redesign it.
#
# HARD RULES
#   - Never touch is_active from the new code paths. Hiding from Own production must leave the
#     product on the branch, so its ledger row, Daily Sheet cell and dispatch/receive entries stay.
#   - Never rewrite historical rows. The flag governs what may be entered NOW.
#   - Do not loosen dl_canAddStockWithoutPaperDr(). adjustment_add must not become the workaround.
#   - No fake dr_number, no synthesised delivery. (The no-DR receipt model is a SEPARATE phase.)
#   - DiSyL trap: DiSyL eats { } inside inline HTML handlers. Put JS in <script>/Alpine.
#
# ACCEPTANCE (must pass before you stop):
#     php tests/daily-ledger/daily_ledger_own_production_eligibility_test.php  ->  12/12 passed
#     php -l on every PHP file you changed
#     php _lint_disyl.php                                                      -> all valid
#     php tests/daily-ledger/daily_ledger_authz_test.php                       -> 84/84
#     php tests/daily-ledger/daily_ledger_product_categories_test.php          -> 10/10
#     storage/logs/app.log and storage/logs/error.log: no new lines
#
# Report exactly which files you changed and the final output of every command above.
set -euo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"

source "$HERE/../lane-model.sh"

PROMPT="$(cat <<'PROMPT_EOF'
You are working in /var/www/html/applicationostest — the Ikabud Kernel OS "daily-ledger" module
(PHP 8, DiSyL templates, MySQL 5.7 compatibility required). Do not run composer/npm installs.
Stay inside modules/daily-ledger/** and templates/modules/daily-ledger/**.

TASK: hide a product from the "Own production" choices WITHOUT removing it from the cashier ledger.

READ FIRST (do not re-derive, but verify each claim in the code):
  .ai/consult/own-production-decision-2026-11-14.md      <- the decision record (D1 is what you build)
  tests/daily-ledger/daily_ledger_own_production_eligibility_test.php  <- your acceptance contract
  templates/modules/daily-ledger/cashier/modal_patch.disyl  (loadProducts)
  modules/daily-ledger/handlers.php  (apiSaveCashierWithdrawals, search OWN_PRODUCTION_DISABLED)
  modules/daily-ledger/handlers-offline.php (the same check for the offline replay)
  modules/daily-ledger/handlers-deliveries.php (dl_canAddStockWithoutPaperDr, and wherever
      dl_setBranchProductActive / dl_bulkAssignBranchProductsCore live)

CAUSE, MEASURED: Own production has no product list of its own — the modal scrapes
tr[data-product-id] off the cashier ledger, and those rows are dl_branch_products WHERE is_active=1.
One toggle therefore controls both scopes, which is why the client could not do what she asked.

BUILD (six parts, all required):
 1. Migration modules/daily-ledger/database/migrations/101_branch_product_own_production.sql
    adding dl_branch_products.allow_own_production TINYINT(1) NOT NULL DEFAULT 1.
    Idempotent + MySQL 5.7, information_schema guard like 098_product_categories.sql /
    100_receiving_supply_origin_cake_correction.sql. Register it in module.json migrations[].
 2. dl_setBranchProductOwnProduction($db, int $branchId, int $productId, bool $allow,
    ?int $actorId = null): array — upserts the pair row, changes ONLY allow_own_production, writes
    audit action 'branch_product_own_production_changed' (old -> new). MUST NOT touch is_active.
    dl_assertOwnProductionAllowed($db, int $branchId, int $productId): void — throws
    RuntimeException when the flag is 0; message names the real product and contains the words
    "own production" plus the remedy.
 3. Enforce server-side in BOTH write paths, next to the existing dl_branchHasProduction() check,
    for type 'own_production' only: apiSaveCashierWithdrawals (handlers.php) and its offline
    mirror (handlers-offline.php). Leave adjustment_add alone.
 4. GET /daily-ledger/api/v1/branch/own-production-products (route + handler) ->
    { ok, products:[{id,name,pcs_per_pack}] } = this branch's ACTIVE products with
    allow_own_production = 1, authorized with the same scoping the other cashier endpoints use.
 5. modal_patch.disyl loadProducts(): for own_production fill this.products from that feed; keep
    the ledger-DOM path ONLY as a fallback when the fetch fails. Do not restructure the modal.
 6. One admin control on the EXISTING per-branch product assignment surface (the picker that
    already calls apiToggleBranchProduct / dl_bulkAssignBranchProductsCore): an "Own production"
    checkbox per product posting to
    /daily-ledger/api/v1/admin/branch-products/own-production { branch_id, product_id, allow }.
    Match that surface's existing row controls and permission checks; do not redesign it.

HARD RULES:
 - The new paths never touch is_active. The product must stay on the branch so its ledger row,
   Daily Sheet cell and dispatch/receive entries are unchanged.
 - Never rewrite historical rows; the flag governs what may be entered now.
 - Do not loosen dl_canAddStockWithoutPaperDr(). adjustment_add must not become the workaround.
 - No fake dr_number and no synthesised delivery — the no-DR receipt is a separate phase.
 - DiSyL eats { } inside inline HTML handlers: put JS in <script> blocks or Alpine.

ACCEPTANCE (all must pass before you stop):
     php tests/daily-ledger/daily_ledger_own_production_eligibility_test.php   -> 12/12 passed
     php -l on every PHP file you changed
     php _lint_disyl.php                                                       -> all valid
     php tests/daily-ledger/daily_ledger_authz_test.php                        -> 84/84
     php tests/daily-ledger/daily_ledger_product_categories_test.php           -> 10/10
     storage/logs/app.log and storage/logs/error.log: no new lines
Also give the exact SQL you expect migration 101 to run, and confirm the column default is 1 so a
deploy removes nothing. Report every file changed and the final output of each command.
PROMPT_EOF
)"

lane_model_run "$LANE_MODEL_CHAIN" "$PROMPT" /tmp/lane-dl-own-production
