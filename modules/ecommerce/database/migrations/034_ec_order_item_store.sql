-- ============================================================
-- Ecommerce Module — Order Item Store ID
-- Stamps store_id on each order item at checkout so per-store
-- revenue reports and fulfilment routing work at line-item level.
-- Safe to re-run (idempotent).
-- ============================================================

SET @col_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'ec_order_items'
      AND COLUMN_NAME = 'store_id'
);

SET @sql = IF(@col_exists = 0,
    'ALTER TABLE ec_order_items
     ADD COLUMN store_id INT UNSIGNED NULL DEFAULT NULL AFTER id,
     ADD KEY idx_ec_order_items_store_id (store_id)',
    'SELECT "store_id column already exists on ec_order_items"'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
