-- Migration 007: Employee deductions (store-level: shortages, advances, etc.)
CREATE TABLE IF NOT EXISTS `employee_deductions` (
    `deduction_id`     INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`        VARCHAR(36) NOT NULL,
    `user_id`          INT UNSIGNED NOT NULL,
    `employee_name`    VARCHAR(255) DEFAULT NULL,
    `store_id`         INT UNSIGNED DEFAULT NULL,
    `transaction_id`   VARCHAR(100) DEFAULT NULL COMMENT 'Reference to POS transaction or other source',
    `amount`           DECIMAL(12,2) NOT NULL,
    `description`      VARCHAR(500) DEFAULT NULL,
    `deduction_date`   DATE DEFAULT NULL,
    `processed_by`     INT UNSIGNED DEFAULT NULL,
    `status`           ENUM('pending','approved','processed','cancelled') DEFAULT 'pending',
    `notes`            TEXT DEFAULT NULL,
    `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`deduction_id`),
    INDEX `idx_tenant_user` (`tenant_id`, `user_id`),
    INDEX `idx_store` (`store_id`),
    INDEX `idx_status` (`tenant_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
