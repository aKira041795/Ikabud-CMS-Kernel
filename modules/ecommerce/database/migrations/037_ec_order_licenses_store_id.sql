-- ============================================================
-- Ecommerce Module — Add store_id to ec_order_licenses
-- Links license records to the originating store so store-admin
-- views and store-aware license generation can operate correctly.
-- Safe to re-run (idempotent via IF NOT EXISTS / duplicate check).
-- ============================================================

-- Add store_id column if it does not exist.
SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'ec_order_licenses'
      AND COLUMN_NAME  = 'store_id'
);

SET @stmt = IF(@col_exists = 0,
    'ALTER TABLE ec_order_licenses ADD COLUMN store_id INT UNSIGNED NULL DEFAULT NULL AFTER customer_id',
    'SELECT 1'
);
PREPARE _ec_mig FROM @stmt;
EXECUTE _ec_mig;
DEALLOCATE PREPARE _ec_mig;

-- Index for store-admin license queries.
SET @idx_exists = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'ec_order_licenses'
      AND INDEX_NAME   = 'idx_ec_order_licenses_store_id'
);

SET @stmt2 = IF(@idx_exists = 0,
    'CREATE INDEX idx_ec_order_licenses_store_id ON ec_order_licenses (store_id)',
    'SELECT 1'
);
PREPARE _ec_mig2 FROM @stmt2;
EXECUTE _ec_mig2;
DEALLOCATE PREPARE _ec_mig2;
