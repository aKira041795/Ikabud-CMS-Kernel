CREATE TABLE IF NOT EXISTS `wms_warehouses` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `code` VARCHAR(50) NOT NULL,
    `name` VARCHAR(255) NOT NULL,
    `address` TEXT NULL,
    `contact_info` JSON NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `deleted_at` DATETIME NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_wms_warehouses_code` (`code`),
    KEY `idx_wms_warehouses_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `wms_locations` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `warehouse_id` INT UNSIGNED NOT NULL,
    `parent_id` INT UNSIGNED NULL,
    `code` VARCHAR(100) NOT NULL,
    `name` VARCHAR(255) NOT NULL,
    `type` VARCHAR(20) NOT NULL DEFAULT 'bin',
    `capacity` DECIMAL(14,4) NULL,
    `capacity_unit` VARCHAR(50) NULL,
    `sort_order` INT NOT NULL DEFAULT 0,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `deleted_at` DATETIME NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_wms_locations_warehouse_code` (`warehouse_id`, `code`),
    KEY `idx_wms_locations_parent` (`parent_id`),
    KEY `idx_wms_locations_type` (`warehouse_id`, `type`, `is_active`),
    CONSTRAINT `fk_wms_locations_warehouse` FOREIGN KEY (`warehouse_id`) REFERENCES `wms_warehouses` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_wms_locations_parent` FOREIGN KEY (`parent_id`) REFERENCES `wms_locations` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
