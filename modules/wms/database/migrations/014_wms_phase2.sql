-- Phase 2: Operational Intelligence Layer

-- 1. Demand & Stock Intelligence
DROP PROCEDURE IF EXISTS wms_add_product_replenishment_cols;
DELIMITER //
CREATE PROCEDURE wms_add_product_replenishment_cols()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'wms_products'
          AND COLUMN_NAME  = 'reorder_point'
    ) THEN
        ALTER TABLE `wms_products`
            ADD COLUMN `reorder_point` DECIMAL(14,4) NOT NULL DEFAULT 0.0000 AFTER `weight`,
            ADD COLUMN `safety_stock` DECIMAL(14,4) NOT NULL DEFAULT 0.0000 AFTER `reorder_point`;
    END IF;
END //
DELIMITER ;
CALL wms_add_product_replenishment_cols();
DROP PROCEDURE IF EXISTS wms_add_product_replenishment_cols;

-- 2. Task System (Explicit Operations)
CREATE TABLE IF NOT EXISTS `wms_tasks` (
    `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `warehouse_id`    INT UNSIGNED NOT NULL,
    `task_type`       ENUM('putaway', 'pick', 'transfer', 'count', 'replenish') NOT NULL,
    `status`          ENUM('pending', 'in_progress', 'completed', 'cancelled') NOT NULL DEFAULT 'pending',
    `priority`        TINYINT UNSIGNED NOT NULL DEFAULT 50 COMMENT 'Lower number = higher priority',
    `reference_type`  VARCHAR(50) NULL DEFAULT NULL COMMENT 'order, delivery, cycle_count',
    `reference_id`    BIGINT UNSIGNED NULL DEFAULT NULL,
    `assigned_to`     INT UNSIGNED NULL DEFAULT NULL COMMENT 'User ID of assigned warehouse worker',
    `due_at`          DATETIME NULL DEFAULT NULL,
    `started_at`      DATETIME NULL DEFAULT NULL,
    `completed_at`    DATETIME NULL DEFAULT NULL,
    `notes`           TEXT NULL DEFAULT NULL,
    `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_wms_tasks_warehouse` (`warehouse_id`),
    KEY `idx_wms_tasks_type_status` (`task_type`, `status`),
    KEY `idx_wms_tasks_assigned` (`assigned_to`),
    CONSTRAINT `fk_wms_tasks_warehouse` FOREIGN KEY (`warehouse_id`) REFERENCES `wms_warehouses` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

