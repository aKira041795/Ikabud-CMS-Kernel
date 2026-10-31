#!/usr/bin/env bash
# Lane: product categories become data (lookup table) instead of a closed ENUM,
# preserving every product's current category. Architect contract below.
set -u
cd "$(dirname "$0")/.." || exit 1
source tools/lane-model.sh

read -r -d '' PROMPT <<'PROMPT_EOF'
You are implementing a bounded change in the Ikabud daily-ledger module (PHP 8.x, DiSyL
templates, MySQL 5.7/Bluehost). Work ONLY inside ALLOWED FILES. Read the existing code first.

## WHY (owner decision, already approved)
`dl_products.product_category` is ENUM('bread','cake','other'), so an admin cannot add a
category - the column IS the list. The owner wants categories to become data. The one hard
requirement: PRESERVE every product's current category. Live tenant data (baronledger.test,
id 207) at the time of writing: 214 products = bread 111, cake 23, other 80. Product rows and
their categories must not change meaning, and no product may lose its category.

## AUDITED FACTS (verified, do not re-derive)
- ENUM declared in database/migrations/012_add_product_category.sql, NOT NULL DEFAULT 'bread'.
- Exactly four code sites hardcode the three values:
    handlers.php ~12164  API list filter   in_array($category, ['bread','cake','other'])
    handlers.php ~19329  product create    fallback to 'bread' when not in the list
    handlers.php ~19467  product update    same
    handlers.php ~21050  CSV import map    same
- The add/edit selects are hardcoded Bread/Cake/Other:
  templates/modules/daily-ledger/admin/products.disyl ~335 (add) and ~423 (edit).
- handleAdminProducts() (~18501) reads `category` (all|bread|non_bread|cake|other), filters in
  SQL and computes bread/non_bread counts. It renders `category` and `category_counts`.
- dl_supplyOriginForProduct() in handlers-deliveries.php ~108 derives 'bodega' for non-bread
  (cake/other) and is used by both receiving-item insert paths. This must keep working.
- Migration 097 added dl_branch_receiving_items.supply_origin. Latest migration is 098 next.
- The established safe-ALTER pattern in this module is an information_schema guard +
  PREPARE/EXECUTE/DEALLOCATE (see 096 and 097). Follow it.

## WHAT TO BUILD

1. MIGRATION `database/migrations/098_product_categories.sql` (register in module.json after 097)
   - CREATE TABLE IF NOT EXISTS `dl_product_categories`:
       id INT UNSIGNED AUTO_INCREMENT PK,
       code VARCHAR(50) NOT NULL,
       label VARCHAR(100) NOT NULL,
       grouping ENUM('bread','non_bread') NOT NULL DEFAULT 'non_bread',
       sort_order INT NOT NULL DEFAULT 0,
       is_active TINYINT(1) NOT NULL DEFAULT 1,
       created_at / updated_at DATETIME,
       UNIQUE KEY uq_dl_prod_cat_code (code),
       INDEX idx_dl_prod_cat_group (grouping),
       ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
   - Seed EXACTLY these three, matching today's behaviour (do not "improve" the grouping):
       ('bread','Bread','bread',10), ('cake','Cake','non_bread',20), ('other','Other','non_bread',30)
     Use INSERT ... ON DUPLICATE KEY UPDATE so a re-run cannot duplicate them.
   - ALTER dl_products ADD COLUMN category_id INT UNSIGNED NULL AFTER product_category, plus
     an index, plus a FK to dl_product_categories(id) ON DELETE RESTRICT. Guard it exactly like
     096/097 so re-running is safe.
   - BACKFILL (idempotent): set p.category_id = c.id joining c.code = p.product_category, for
     rows where category_id IS NULL. Every one of the 214 products must end up non-NULL.
   - KEEP the legacy `product_category` column. This is a dual-write transition: category_id is
     the new source of truth, product_category stays as a mirror so nothing that reads it breaks
     and rollback is possible. Do not drop it.

2. CODE — make the table the source of truth
   - Add `dl_productCategoryGroups()` / `dl_productCategories(bool $activeOnly = true)` helpers
     (live read, no static cache) returning code => [id, label, grouping, sort_order].
   - products list (`handleAdminProducts`): filter by the category table.
       * `category_id=<id>` selects that one category
       * `category=bread|non_bread` selects by GROUPING
       * `category=<code>` (legacy codes) still works by resolving the code
       * unknown/empty => all
     Counts for the select must come from the same join, not from hardcoded values.
   - product create/update: accept `category_id` (preferred). If only `category` code arrives
     (legacy clients, CSV), resolve it against the table. Reject an unknown value with a clear
     422 - do not silently fall back to 'bread' any more. WRITE BOTH columns: category_id and
     product_category = the category's code (mirror).
   - CSV import (~21050): resolve by code OR label against the table, case-insensitive. Unknown
     value => that row fails with a message naming the bad value; never silently becomes bread.
   - dl_supplyOriginForProduct(): resolve the grouping through category_id; when category_id is
     NULL (a row not yet backfilled) fall back to the legacy product_category mapping. Keep the
     fail-closed contract and keep returning 'bodega' only for the non_bread grouping.
   - New admin-only endpoints (add to routes.php, implement in handlers.php):
       GET  /daily-ledger/api/v1/admin/product-categories         -> list with product counts
       POST /daily-ledger/api/v1/admin/product-categories         -> create {code,label,grouping,sort_order}
       POST /daily-ledger/api/v1/admin/product-categories/update  -> update {id,label,grouping,sort_order,is_active}
     Validate code as ^[a-z0-9_]{2,50}$ and force it lowercase. Refuse a deactivation
     (is_active=0) while any product still uses that category, and say how many. Audit both
     create and update with the before/after pair (module audit helper, do not invent a table).

3. UI — templates/modules/daily-ledger/admin/products.disyl
   - The add and edit Category selects are populated from the category table (server-rendered),
     with the product's current category selected. Group them: bread-group categories first,
     then non-bread, so the admin can see which are bakery and which are Bodega-sourced.
   - Add a "Manage categories" button beside the existing + Add / Import CSV buttons, opening a
     compact modal that lists each category with its label, its grouping, how many products use
     it, an active toggle, and an edit action; plus an add row. Follow the existing modal and
     design-system classes in that template - do not invent new colours. The modal must not
     scroll sideways at 768px (the owner's floor is tablet).
   - The category filter already on the page keeps working, now backed by the table, and its
     counts must match reality.

## CONSTRAINTS
- MySQL 5.7: no CTEs, no window functions, no JSON_TABLE, no CHECK constraints.
- Module DB only via module()->db(); distinct bind names per statement.
- Do not change dl_branch_receiving_items.supply_origin semantics or migration 097.
- Keep the diff minimal; no refactors outside the listed concerns.

## ALLOWED FILES
modules/daily-ledger/handlers.php
modules/daily-ledger/handlers-deliveries.php
modules/daily-ledger/routes.php
modules/daily-ledger/module.json
modules/daily-ledger/database/migrations/098_product_categories.sql
templates/modules/daily-ledger/admin/products.disyl
tests/daily-ledger/daily_ledger_product_categories_test.php

## THE ACCEPTANCE TEST (write it; it is the oracle)
Create `tests/daily-ledger/daily_ledger_product_categories_test.php` in the house style of
daily_ledger_bread_bodega_wiring_test.php (TestHarness MODE_INTEGRATION, tenant
'baronledger.test', modulePushContext('daily-ledger'), $h->section(), $h->test(label, cond,
failureMode), $h->done(), fixture cleanup pin). Assert OUTCOMES against the real DB:
  - migration 098 is registered in module.json and dl_product_categories exists and is seeded
    with exactly bread/cake/other with groupings bread/non_bread/non_bread
  - DATA PRESERVATION: no product has category_id NULL, and for every product the category_id
    resolves to a code equal to its legacy product_category value (zero mismatches)
  - the per-code product counts match the legacy column counts exactly (compute both sides in
    the test; do not hardcode magic numbers)
  - dl_supplyOriginForProduct still returns bodega for cake/other and the fallback for bread
  - creating a NEW category (grouping non_bread) and moving a fixture product to it makes that
    product filter as non_bread AND source as bodega - prove the whole chain, then clean up
  - deactivating a category that still has products is refused, with the count in the message
  - an unknown category on create/update is refused (422), not silently defaulted
Run it yourself, make it pass, and do not weaken an assertion to get green.

## REPORT BACK
status, changed files, the exact SQL used for the backfill, how many products ended up with
category_id set, test counts, and anything you deliberately left out.
PROMPT_EOF

lane_model_run "$LANE_MODEL_CHAIN" "$PROMPT" /tmp/lane-daily-ledger-product-categories
