CREATE TABLE IF NOT EXISTS `wms_task_exceptions` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `task_id` BIGINT UNSIGNED NOT NULL,
    `exception_type` VARCHAR(80) NOT NULL,
    `status` ENUM('open', 'resolved') NOT NULL DEFAULT 'open',
    `message` VARCHAR(500) NOT NULL,
    `scan_payload` JSON NULL,
    `resolution_note` VARCHAR(500) NULL,
    `created_by` INT UNSIGNED NULL,
    `resolved_by` INT UNSIGNED NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `resolved_at` DATETIME NULL,
    PRIMARY KEY (`id`),
    KEY `idx_wms_task_exceptions_task_status` (`task_id`, `status`),
    KEY `idx_wms_task_exceptions_status_created` (`status`, `created_at`),
    CONSTRAINT `fk_wms_task_exceptions_task` FOREIGN KEY (`task_id`) REFERENCES `wms_tasks` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;