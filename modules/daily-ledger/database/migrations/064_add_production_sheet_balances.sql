-- 064: production-sheet beginning and physical ending balances
--
-- `beg_qty` is a per-day snapshot populated only when the application first
-- creates a product-ledger row. It is intentionally never backfilled here.
-- `actual_end_qty` stores only an operator-entered physical count; NULL means
-- not counted. The suggestion remains a read-time UI value.
--
-- @mysql57-compat: guarded dynamic ALTER statements, InnoDB table unchanged,
-- utf8mb4_unicode_ci required by this module, and same-row STORED expression.

SET @beg_exists := (
    SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name = 'dl_commissary_product_ledger'
       AND column_name = 'beg_qty'
);
SET @sql := IF(@beg_exists = 0,
    'ALTER TABLE dl_commissary_product_ledger ADD COLUMN beg_qty INT NOT NULL DEFAULT 0 AFTER ledger_date',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @actual_exists := (
    SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name = 'dl_commissary_product_ledger'
       AND column_name = 'actual_end_qty'
);
SET @sql := IF(@actual_exists = 0,
    'ALTER TABLE dl_commissary_product_ledger ADD COLUMN actual_end_qty INT NULL DEFAULT NULL AFTER remaining_qty',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @variance_exists := (
    SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name = 'dl_commissary_product_ledger'
       AND column_name = 'calc_variance'
);
SET @sql := IF(@variance_exists = 0,
    'ALTER TABLE dl_commissary_product_ledger ADD COLUMN calc_variance INT GENERATED ALWAYS AS (actual_end_qty - (beg_qty + produced_qty - dispatched_qty - wastage_qty)) STORED AFTER actual_end_qty',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
