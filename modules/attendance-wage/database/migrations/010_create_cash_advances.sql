-- Migration 010: Cash advances
CREATE TABLE IF NOT EXISTS `cash_advances` (
    `advance_id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`          VARCHAR(36) NOT NULL,
    `user_id`            INT UNSIGNED NOT NULL,
    `amount`             DECIMAL(12,2) NOT NULL,
    `balance`            DECIMAL(12,2) NOT NULL COMMENT 'Remaining amount to repay',
    `request_date`       DATETIME NOT NULL,
    `repayment_type`     ENUM('full_next_payroll','installment','lumpsum_date') DEFAULT 'full_next_payroll',
    `installment_amount` DECIMAL(12,2) DEFAULT NULL,
    `total_installments` INT DEFAULT NULL,
    `paid_installments`  INT DEFAULT 0,
    `target_repay_date`  DATE DEFAULT NULL,
    `status`             ENUM('pending','approved','active','completed','denied','cancelled') DEFAULT 'pending',
    `requested_by`       INT UNSIGNED NOT NULL,
    `approved_by`        INT UNSIGNED DEFAULT NULL,
    `notes`              TEXT DEFAULT NULL,
    `created_at`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`advance_id`),
    INDEX `idx_tenant_user` (`tenant_id`, `user_id`),
    INDEX `idx_status` (`tenant_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
