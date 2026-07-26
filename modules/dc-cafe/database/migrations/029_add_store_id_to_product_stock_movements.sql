-- 029_add_store_id_to_product_stock_movements.sql
-- Add store_id to product stock movements so every movement is branch-aware.
-- Also backfill store_id from dc_products for existing records.
-- @mysql57-compat: InnoDB, utf8mb4.

SET @store_col_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'dc_product_stock_movements' AND column_name = 'store_id'
);

SET @sql := IF(@store_col_exists = 0,
    'ALTER TABLE `dc_product_stock_movements`
     ADD COLUMN `store_id` INT DEFAULT NULL AFTER `product_id`,
     ADD KEY `idx_dc_psm_store` (`store_id`)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Backfill store_id for existing movements from dc_products
UPDATE `dc_product_stock_movements` m
JOIN `dc_products` p ON p.`product_id` = m.`product_id`
SET m.`store_id` = p.`store_id`
WHERE m.`store_id` IS NULL;
