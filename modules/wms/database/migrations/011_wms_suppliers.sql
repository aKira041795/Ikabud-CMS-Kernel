CREATE TABLE IF NOT EXISTS `wms_suppliers` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `code` VARCHAR(50) NOT NULL,
    `name` VARCHAR(255) NOT NULL,
    `contact_person` VARCHAR(255) DEFAULT NULL,
    `email` VARCHAR(255) DEFAULT NULL,
    `phone` VARCHAR(50) DEFAULT NULL,
    `address` TEXT DEFAULT NULL,
    `lead_time_days` SMALLINT UNSIGNED DEFAULT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `meta` JSON DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `deleted_at` DATETIME DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_wms_suppliers_code` (`code`),
    KEY `idx_wms_suppliers_active` (`is_active`, `deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP PROCEDURE IF EXISTS wms_add_supplier_id;

CREATE PROCEDURE wms_add_supplier_id()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'wms_deliveries'
          AND COLUMN_NAME  = 'supplier_id'
    ) THEN
        ALTER TABLE `wms_deliveries`
            ADD COLUMN `supplier_id` INT UNSIGNED DEFAULT NULL AFTER `supplier_name`,
            ADD KEY `idx_wms_deliveries_supplier_id` (`supplier_id`);
    END IF;
END;
CALL wms_add_supplier_id();
DROP PROCEDURE IF EXISTS wms_add_supplier_id;

INSERT INTO `wms_suppliers` (`code`, `name`, `contact_person`, `email`, `phone`, `lead_time_days`, `is_active`)
VALUES ('TECHSUP', 'TechSupplier Inc.', 'John Dela Cruz', 'orders@techsupplier.example', '+63 2 8123 4567', 5, 1)
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);
