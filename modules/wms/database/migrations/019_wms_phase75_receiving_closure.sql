-- Migration: 019_wms_phase75_receiving_closure
-- Purpose: Separate inbound receipt from final availability by introducing staging-aware receiving and delivery putaway progress.

DROP PROCEDURE IF EXISTS wms_phase75_receiving_closure;

CREATE PROCEDURE wms_phase75_receiving_closure()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'wms_locations'
          AND COLUMN_NAME = 'is_staging'
    ) THEN
        ALTER TABLE `wms_locations`
            ADD COLUMN `is_staging` TINYINT(1) NOT NULL DEFAULT 0 AFTER `is_active`;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'wms_locations'
          AND INDEX_NAME = 'idx_wms_locations_staging'
    ) THEN
        ALTER TABLE `wms_locations`
            ADD KEY `idx_wms_locations_staging` (`warehouse_id`, `is_staging`, `is_active`);
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'wms_stocks'
          AND COLUMN_NAME = 'qty_staged'
    ) THEN
        ALTER TABLE `wms_stocks`
            ADD COLUMN `qty_staged` DECIMAL(14,4) NOT NULL DEFAULT 0.0000 AFTER `qty_reserved`;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'wms_stocks'
          AND INDEX_NAME = 'idx_wms_stocks_staged'
    ) THEN
        ALTER TABLE `wms_stocks`
            ADD KEY `idx_wms_stocks_staged` (`qty_staged`);
    END IF;

    ALTER TABLE `wms_stocks`
        MODIFY COLUMN `qty_available` DECIMAL(14,4) GENERATED ALWAYS AS (`qty_on_hand` - `qty_reserved` - `qty_staged`) STORED;

    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'wms_delivery_items'
          AND COLUMN_NAME = 'staging_location_id'
    ) THEN
        ALTER TABLE `wms_delivery_items`
            ADD COLUMN `staging_location_id` INT UNSIGNED NULL AFTER `location_id`;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'wms_delivery_items'
          AND INDEX_NAME = 'idx_wms_delivery_items_staging'
    ) THEN
        ALTER TABLE `wms_delivery_items`
            ADD KEY `idx_wms_delivery_items_staging` (`staging_location_id`);
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'wms_delivery_items'
          AND CONSTRAINT_NAME = 'fk_wms_delivery_items_staging_location'
    ) THEN
        ALTER TABLE `wms_delivery_items`
            ADD CONSTRAINT `fk_wms_delivery_items_staging_location`
                FOREIGN KEY (`staging_location_id`) REFERENCES `wms_locations` (`id`) ON DELETE SET NULL;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'wms_delivery_items'
          AND COLUMN_NAME = 'qty_put_away'
    ) THEN
        ALTER TABLE `wms_delivery_items`
            ADD COLUMN `qty_put_away` DECIMAL(14,4) NOT NULL DEFAULT 0.0000 AFTER `qty_received`;
    END IF;
END;
CALL wms_phase75_receiving_closure();
DROP PROCEDURE IF EXISTS wms_phase75_receiving_closure;

UPDATE `wms_stocks` s
INNER JOIN `wms_locations` l ON l.id = s.location_id
SET s.qty_staged = CASE WHEN COALESCE(l.is_staging, 0) = 1 THEN s.qty_on_hand ELSE 0 END;

UPDATE `wms_delivery_items`
SET qty_put_away = qty_received
WHERE qty_received > 0
  AND qty_put_away = 0;

UPDATE `wms_deliveries` d
LEFT JOIN (
    SELECT
        delivery_id,
        COALESCE(SUM(qty_expected), 0) AS qty_expected_total,
        COALESCE(SUM(qty_received), 0) AS qty_received_total,
        COALESCE(SUM(qty_put_away), 0) AS qty_put_away_total
    FROM `wms_delivery_items`
    GROUP BY delivery_id
) totals ON totals.delivery_id = d.id
SET d.status = CASE
        WHEN d.status = 'cancelled' THEN d.status
        WHEN COALESCE(totals.qty_received_total, 0) <= 0 THEN 'pending'
        WHEN COALESCE(totals.qty_received_total, 0) < COALESCE(totals.qty_expected_total, 0) THEN 'partial'
        WHEN COALESCE(totals.qty_put_away_total, 0) < COALESCE(totals.qty_received_total, 0) THEN 'staged'
        ELSE 'received'
    END,
    d.received_at = CASE
        WHEN COALESCE(totals.qty_received_total, 0) > 0 AND d.received_at IS NULL THEN NOW()
        ELSE d.received_at
    END,
    d.updated_at = NOW()
WHERE d.deleted_at IS NULL;