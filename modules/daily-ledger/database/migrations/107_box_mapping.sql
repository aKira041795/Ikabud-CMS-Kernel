-- 107: box mapping — link a product to the box it consumes when it sells.
--
-- OWNER DIRECTION 2026-11-19, verbatim: "at products, a new view for box mapping ... assigned to a
-- product so that when a qty shows how many were sold in a shift, the box mapped is also auto
-- deducted at the branch."
--
-- WHY THIS EXISTS: boxes belong to BRANCHES (owner, same day: "PIC only produces products; boxes of
-- any kind belong to the branches"). Nothing links a cake to a box today - there is no column, no
-- table, no mapping of any kind - so selling 20 cakes moves box stock by zero. Box inventory then
-- overstates what is on hand and understates cost, and the branch symptom is "we keep buying boxes
-- but the app says we have plenty".
--
-- THREE columns, not the two the design first named:
--
--   is_box          replaces the `product_category = 'other' AND name LIKE '%box%'` test in
--                   dl_isPackagingProduct(). That test is a guess: measured 2026-11-20,
--                   product_category is inconsistent WITHIN a product family (TOASTED BREAD @6 and
--                   @9 are 'other' while @15 is 'bread'), so category cannot identify packaging.
--                   An explicit flag can.
--
--   box_product_id  the box this product consumes. Self-referencing column, deliberately WITHOUT a
--                   foreign key: dl_products is the table being altered, the module's migrations run
--                   on hosts where FK creation has failed before, and a bad pointer is refused in
--                   the handler rather than by the server (MySQL 5.7 cannot express "not itself" as a
--                   CHECK anyway).
--
--   units_per_box   how many pieces one box holds. Default 1 is the common case, and it is NOT a
--                   decoration: pcs_per_pack is NULL on every product row (measured 2026-11-20), so
--                   without this field a box that holds twelve mamon would deduct twelve boxes for
--                   twelve sales instead of one. Owner default is 1 cake = 1 box; the column exists so
--                   the pack case is stated rather than assumed.
--
-- Idempotent: guarded by INFORMATION_SCHEMA, the same pattern as 101, 104 and 106. Safe to re-run.
-- MySQL 5.7 compatible: no IF NOT EXISTS DDL, no CHECK constraints, no CTEs, no window functions.

-- ── is_box ────────────────────────────────────────────────────────────────────────────────
SET @dl_products_is_box_exists := (
    SELECT COUNT(*)
      FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name = 'dl_products'
       AND column_name = 'is_box'
);
SET @dl_products_is_box_ddl := IF(
    @dl_products_is_box_exists = 0,
    'ALTER TABLE dl_products ADD COLUMN is_box TINYINT(1) NOT NULL DEFAULT 0 AFTER is_active',
    'SELECT 1'
);
PREPARE dl_products_is_box_st FROM @dl_products_is_box_ddl;
EXECUTE dl_products_is_box_st;
DEALLOCATE PREPARE dl_products_is_box_st;

-- ── box_product_id ────────────────────────────────────────────────────────────────────────
SET @dl_products_box_product_id_exists := (
    SELECT COUNT(*)
      FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name = 'dl_products'
       AND column_name = 'box_product_id'
);
SET @dl_products_box_product_id_ddl := IF(
    @dl_products_box_product_id_exists = 0,
    'ALTER TABLE dl_products ADD COLUMN box_product_id INT UNSIGNED NULL DEFAULT NULL AFTER is_box',
    'SELECT 1'
);
PREPARE dl_products_box_product_id_st FROM @dl_products_box_product_id_ddl;
EXECUTE dl_products_box_product_id_st;
DEALLOCATE PREPARE dl_products_box_product_id_st;

-- ── units_per_box ─────────────────────────────────────────────────────────────────────────
SET @dl_products_units_per_box_exists := (
    SELECT COUNT(*)
      FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name = 'dl_products'
       AND column_name = 'units_per_box'
);
SET @dl_products_units_per_box_ddl := IF(
    @dl_products_units_per_box_exists = 0,
    'ALTER TABLE dl_products ADD COLUMN units_per_box INT UNSIGNED NOT NULL DEFAULT 1 AFTER box_product_id',
    'SELECT 1'
);
PREPARE dl_products_units_per_box_st FROM @dl_products_units_per_box_ddl;
EXECUTE dl_products_units_per_box_st;
DEALLOCATE PREPARE dl_products_units_per_box_st;

-- Index for the mapping lookup ("which products use this box"), added the same guarded way.
SET @dl_products_box_idx_exists := (
    SELECT COUNT(*)
      FROM information_schema.statistics
     WHERE table_schema = DATABASE()
       AND table_name = 'dl_products'
       AND index_name = 'idx_dl_products_box_product'
);
SET @dl_products_box_idx_ddl := IF(
    @dl_products_box_idx_exists = 0,
    'ALTER TABLE dl_products ADD INDEX idx_dl_products_box_product (box_product_id)',
    'SELECT 1'
);
PREPARE dl_products_box_idx_st FROM @dl_products_box_idx_ddl;
EXECUTE dl_products_box_idx_st;
DEALLOCATE PREPARE dl_products_box_idx_st;
