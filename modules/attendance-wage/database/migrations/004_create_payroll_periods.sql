-- Migration 004: Payroll periods
CREATE TABLE IF NOT EXISTS `payroll_periods` (
    `period_id`        INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`        VARCHAR(36) NOT NULL,
    `period_name`      VARCHAR(100) NOT NULL,
    `period_type`      ENUM('weekly','bi_weekly','semi_monthly','monthly') NOT NULL DEFAULT 'semi_monthly',
    `start_date`       DATE NOT NULL,
    `end_date`         DATE NOT NULL,
    `pay_date`         DATE DEFAULT NULL,
    `cutoff_date`      DATE DEFAULT NULL,
    `status`           ENUM('draft','processing','completed','cancelled') DEFAULT 'draft',
    `total_employees`  INT DEFAULT 0,
    `total_gross_pay`  DECIMAL(14,2) DEFAULT 0.00,
    `total_deductions` DECIMAL(14,2) DEFAULT 0.00,
    `total_net_pay`    DECIMAL(14,2) DEFAULT 0.00,
    `created_by`       INT UNSIGNED DEFAULT NULL,
    `processed_by`     INT UNSIGNED DEFAULT NULL,
    `processed_at`     DATETIME DEFAULT NULL,
    `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`period_id`),
    INDEX `idx_tenant_dates` (`tenant_id`, `start_date`, `end_date`),
    INDEX `idx_status` (`tenant_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
