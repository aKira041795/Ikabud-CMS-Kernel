CREATE TABLE IF NOT EXISTS `wms_idempotency_keys` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `idempotency_key` VARCHAR(100) NOT NULL,
    `movement_id` BIGINT UNSIGNED NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_wms_idempotency_key` (`idempotency_key`),
    KEY `idx_wms_idempotency_movement` (`movement_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP PROCEDURE IF EXISTS wms_add_quarantine_location;

CREATE PROCEDURE wms_add_quarantine_location()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'wms_warehouses'
          AND COLUMN_NAME  = 'quarantine_location_id'
    ) THEN
        ALTER TABLE `wms_warehouses`
            ADD COLUMN `quarantine_location_id` INT UNSIGNED DEFAULT NULL AFTER `address`,
            ADD KEY `idx_wms_warehouses_quarantine` (`quarantine_location_id`);
    END IF;
END;
CALL wms_add_quarantine_location();
DROP PROCEDURE IF EXISTS wms_add_quarantine_location;
