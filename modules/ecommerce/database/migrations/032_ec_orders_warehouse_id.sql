-- ============================================================
-- Ecommerce Module — Add warehouse_id to ec_orders
-- Required by Phase 7B (per-store WMS routing at checkout).
-- Safe to re-run (idempotent).
-- ============================================================

SET @col_exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'ec_orders'
      AND COLUMN_NAME  = 'warehouse_id'
);

SET @sql = IF(
    @col_exists = 0,
    'ALTER TABLE ec_orders
         ADD COLUMN warehouse_id INT UNSIGNED NULL DEFAULT NULL
             COMMENT ''wms_warehouses.id resolved from store inventory source at checkout''
             AFTER store_id,
         ADD KEY idx_ec_orders_warehouse_id (warehouse_id)',
    'SELECT 1'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
