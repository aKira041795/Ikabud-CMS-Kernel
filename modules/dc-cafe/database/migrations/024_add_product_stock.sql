-- 024_add_product_stock.sql
-- Add stock tracking for finished products (donuts, hot meals, parfaits).
-- Products with BOM entries (soft-serve sizes) rely on ingredient deduction.
-- All other products track stock directly.
-- @mysql57-compat: InnoDB, utf8mb4.

SET @stock_col_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'dc_products' AND column_name = 'current_stock'
);

SET @sql := IF(@stock_col_exists = 0,
    'ALTER TABLE dc_products
     ADD COLUMN current_stock DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER is_active,
     ADD COLUMN reorder_level DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER current_stock,
     ADD COLUMN has_stock TINYINT(1) NOT NULL DEFAULT 0 AFTER reorder_level',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Mark products without BOM ingredients as stock-tracked
UPDATE dc_products p
SET p.has_stock = 1
WHERE NOT EXISTS (
    SELECT 1 FROM dc_product_ingredients pi WHERE pi.product_id = p.product_id
);
