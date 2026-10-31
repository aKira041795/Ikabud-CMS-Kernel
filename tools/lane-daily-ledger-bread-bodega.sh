#!/usr/bin/env bash
# Lane: daily-ledger bread vs non-bread filtering + Bodega source on DR receiving.
# Architect contract below. Sol implements; the acceptance test is the oracle.
set -u
cd "$(dirname "$0")/.." || exit 1
source tools/lane-model.sh

read -r -d '' PROMPT <<'PROMPT_EOF'
You are implementing a bounded change in the Ikabud daily-ledger module (PHP 8.x, DiSyL
templates, MySQL 5.7/Bluehost compatibility). Work only inside the files listed under
ALLOWED FILES. Read the existing code before writing any.

## AUDITED FACTS (verified, do not re-derive)
- `dl_products.product_category` is ENUM('bread','cake','other') NOT NULL DEFAULT 'bread'
  (migration 012). Tenant data: bread=111, cake=23, other=80.
- "Non-bread" therefore means `product_category IN ('cake','other')`.
- `templates/modules/daily-ledger/admin/products.disyl` has a Category COLUMN and a
  category select in the add/edit forms, but the page has NO category filter at all.
- `handleAdminProducts()` (modules/daily-ledger/handlers.php ~18600) renders that page and
  passes `search`, `tab`, `branch_id`, `consignee_id` through to the template.
- A separate products API at handlers.php ~12159 accepts only an exact enum value:
  `if ($category !== '' && in_array($category, ['bread','cake','other'], true))`.
- The word "bodega" does not exist anywhere in the repo, in docs, in any table name, or in
  the tenant data. There is no Bodega branch. It is a NEW concept.
- `dl_branch_receivings.origin_type` ENUM('commissary','branch','supplier','local_production',
  'manual_adjustment') — per RECEIVING, not per item. Existing rows are only 'commissary'
  and 'branch'.
- Receiving item rows are written at modules/daily-ledger/handlers-deliveries.php ~1259
  (posted receive loop) and ~2467 (second path). `apiReceiveDelivery` is handlers.php ~10683
  and `apiReceivePaperDelivery` is handlers.php ~11010.
- `dl_branch_receiving_items` currently has: id, receiving_id, delivery_item_id, product_id,
  quantity_received, unit, unit_cost_snapshot, selling_price_snapshot, remarks.

## THE THREE REQUIREMENTS
1. CATEGORY FILTER — the products admin page must separate bread from non-bread.
   `handleAdminProducts` accepts a `category` query parameter with values
   `all|bread|non_bread` (also accept the legacy exact values bread|cake|other, and treat an
   empty/unknown value as `all`). `bread` selects product_category='bread'; `non_bread`
   selects product_category IN ('cake','other'). Filter SERVER-SIDE in SQL, never by hiding
   rows in JS. The template renders the filter so a reload/share keeps it, preserves the
   existing search/tab/branch/consignee params, and shows a count per group. The existing
   products API filter must also accept `non_bread`.

2. BODEGA ON DR RECEIVING — when stock is received against a DR, every NON-BREAD line must be
   recorded as coming from Bodega, automatically, with no manual selection.
   - Add migration `database/migrations/097_receiving_item_supply_origin.sql` (register it in
     module.json after 096): add `supply_origin` ENUM('bodega','commissary','branch',
     'local_production','supplier','manual_adjustment') NOT NULL DEFAULT 'commissary' to
     `dl_branch_receiving_items`, plus an index on it. Guard the ALTER so re-running is safe
     (information_schema check + PREPARE/EXECUTE is the established pattern in this module).
     Do NOT relabel historical rows as bodega: backfill existing rows from their parent
     receiving's origin_type, so history keeps saying what actually happened.
   - Add `dl_supplyOriginForProduct(int $productId, string $fallback = 'commissary'): string`
     in handlers-deliveries.php next to dl_branchHasProduction(): reads product_category live
     (no static cache), returns 'bodega' for non-bread, otherwise the fallback. Fail-closed to
     the fallback on a read error.
   - Write `supply_origin` on BOTH receiving-item insert paths (handlers-deliveries.php ~1259
     and ~2467) and anywhere else receiving items are inserted. A mixed DR must end up with
     bread lines = commissary and non-bread lines = bodega in the SAME receiving.

3. ADMIN TRACING — an admin must be able to see and audit it.
   - The admin deliveries surface (`/daily-ledger/admin/deliveries`,
     templates/.../admin/deliveries.disyl, handler in handlers-deliveries.php ~3701) shows a
     per-line Source column reading "Bodega" / "Commissary" (from supply_origin, with a
     sensible fallback for rows written before 097).
   - When a posted receiving contains at least one bodega line, write ONE durable audit record
     with the receiving id, delivery id, dr_number, bodega line count and total bodega qty.
     Use the module's existing audit helper — do not invent a new log table.

## CONSTRAINTS
- MySQL 5.7: no CTEs, no window functions, no JSON_TABLE, no CHECK constraints, no
  information_schema access from module DB code. Every CREATE/ALTER keeps InnoDB + utf8mb4.
- Module DB access only through `module()->db()`. Distinct bind names per prepared statement.
- Do not change the meaning of `origin_type` on deliveries or receivings.
- Do not touch anything outside ALLOWED FILES. Keep the diff minimal and idiomatic.

## ALLOWED FILES
modules/daily-ledger/handlers.php
modules/daily-ledger/handlers-deliveries.php
modules/daily-ledger/module.json
modules/daily-ledger/database/migrations/097_receiving_item_supply_origin.sql
templates/modules/daily-ledger/admin/products.disyl
templates/modules/daily-ledger/admin/deliveries.disyl
tests/daily-ledger/daily_ledger_bread_bodega_wiring_test.php

## THE ACCEPTANCE TEST (write it; it is the oracle)
Create `tests/daily-ledger/daily_ledger_bread_bodega_wiring_test.php` following the house
style of daily_ledger_own_production_capability_test.php: TestHarness
MODE_INTEGRATION, tenant 'baronledger.test', `modulePushContext('daily-ledger')`,
`$h->section()`, `$h->test(label, condition, failureMode)`, `$h->done()`, and a fixture
cleanup pin at the end. Assert OUTCOMES, not just string greps:
  - the category groups resolve correctly against real product rows you create
    (bread row -> bread, cake row -> non_bread, other row -> non_bread)
  - migration 097 is registered in module.json and `supply_origin` exists on
    dl_branch_receiving_items with default 'commissary'
  - dl_supplyOriginForProduct returns 'bodega' for non-bread and the fallback for bread,
    and 'bodega' for an unknown/zero product id only if that is the honest answer (assert
    what your implementation actually guarantees)
  - a posted receiving with mixed lines stores bodega on non-bread items and commissary on
    bread items (insert the fixture rows through the real code path if feasible; otherwise
    assert the handler source writes supply_origin on every receiving-item insert)
  - the admin deliveries template renders the Source column
Run it yourself and make it pass. Do not weaken an assertion to get green.

## REPORT BACK
status, changed files, what the category filter does at SQL level, where supply_origin is
written, where the admin can see it, test counts, and anything you deliberately left out.
PROMPT_EOF

lane_model_run "$LANE_MODEL_CHAIN" "$PROMPT" /tmp/lane-daily-ledger-bread-bodega
