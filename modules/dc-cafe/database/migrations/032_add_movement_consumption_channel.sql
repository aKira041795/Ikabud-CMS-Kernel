-- 032_add_movement_consumption_channel.sql
-- Distinguish a direct counter sale from a component consumed by a box/bundle.
--
-- A box (e.g. DC DELIGHTS COMBO 6pcs) is priced as a whole, so its component
-- doughnuts carry no revenue of their own. They are still real stock movements,
-- but reporting must be able to separate them from direct sales:
--
--   consumption_channel = 'direct'  -> the customer bought this product directly
--   consumption_channel = 'bundle'  -> consumed to fulfil a box/bundle order
--
-- movement_type intentionally stays 'sale'. The void handler
-- (handlers-orders.php) selects restore candidates by movement_type = 'sale',
-- so introducing a new enum value here would silently strand the stock of every
-- voided box. The channel is provenance, not a movement class.
--
-- @mysql57-compat: ALTER TABLE via information_schema guards, InnoDB FKs.

SET @cc_exists := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'dc_product_stock_movements'
      AND column_name = 'consumption_channel'
);

SET @sql := IF(@cc_exists = 0,
    'ALTER TABLE `dc_product_stock_movements`
     ADD COLUMN `consumption_channel` ENUM(''direct'',''bundle'') NOT NULL DEFAULT ''direct'' AFTER `movement_type`,
     ADD COLUMN `bundle_product_id` INT DEFAULT NULL AFTER `consumption_channel`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Index is created separately so it is not skipped when the column pre-exists.
SET @idx_exists := (
    SELECT COUNT(*)
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'dc_product_stock_movements'
      AND index_name = 'idx_dc_psm_channel'
);

SET @sql2 := IF(@idx_exists = 0,
    'ALTER TABLE `dc_product_stock_movements`
     ADD KEY `idx_dc_psm_channel` (`consumption_channel`, `store_id`)',
    'SELECT 1'
);
PREPARE stmt2 FROM @sql2; EXECUTE stmt2; DEALLOCATE PREPARE stmt2;

SET @fk_exists := (
    SELECT COUNT(*)
    FROM information_schema.table_constraints
    WHERE table_schema = DATABASE()
      AND table_name = 'dc_product_stock_movements'
      AND constraint_name = 'fk_dc_psm_bundle_product'
);

SET @sql3 := IF(@fk_exists = 0,
    'ALTER TABLE `dc_product_stock_movements`
     ADD CONSTRAINT `fk_dc_psm_bundle_product`
     FOREIGN KEY (`bundle_product_id`) REFERENCES `dc_products` (`product_id`)',
    'SELECT 1'
);
PREPARE stmt3 FROM @sql3; EXECUTE stmt3; DEALLOCATE PREPARE stmt3;

-- Backfill: existing rows are all direct sales/adjustments.
UPDATE `dc_product_stock_movements`
SET `consumption_channel` = 'direct'
WHERE `consumption_channel` IS NULL;
