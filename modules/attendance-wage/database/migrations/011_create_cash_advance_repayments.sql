-- Migration 011: Cash advance repayments
CREATE TABLE IF NOT EXISTS `cash_advance_repayments` (
    `repayment_id`      INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `advance_id`        INT UNSIGNED NOT NULL,
    `payroll_period_id` INT UNSIGNED NOT NULL,
    `amount`            DECIMAL(12,2) NOT NULL,
    `deduction_method`  ENUM('salary_deduction','manual_payment') DEFAULT 'salary_deduction',
    `status`            ENUM('pending','deducted','paid') DEFAULT 'pending',
    `created_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`repayment_id`),
    INDEX `idx_advance` (`advance_id`),
    INDEX `idx_period` (`payroll_period_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
