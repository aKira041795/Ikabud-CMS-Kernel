-- Migration 008: Benefits contribution rates (SSS, PhilHealth, Pag-IBIG)
CREATE TABLE IF NOT EXISTS `benefits_contribution_rates` (
    `rate_id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`         VARCHAR(36) NOT NULL,
    `benefit_type`      ENUM('sss','philhealth','pagibig','other') NOT NULL,
    `effective_date`    DATE NOT NULL,
    `salary_from`       DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `salary_to`         DECIMAL(12,2) DEFAULT NULL COMMENT 'NULL = unlimited upper bound',
    `employee_share_pct` DECIMAL(5,4) DEFAULT 0.0000 COMMENT 'e.g. 0.0450 = 4.5%',
    `employer_share_pct` DECIMAL(5,4) DEFAULT 0.0000,
    `employee_fixed`    DECIMAL(10,2) DEFAULT NULL COMMENT 'Fixed amount override',
    `employer_fixed`    DECIMAL(10,2) DEFAULT NULL,
    `min_contribution`  DECIMAL(10,2) DEFAULT NULL,
    `max_contribution`  DECIMAL(10,2) DEFAULT NULL,
    `description`       VARCHAR(500) DEFAULT NULL,
    `is_active`         TINYINT(1) DEFAULT 1,
    `created_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`rate_id`),
    INDEX `idx_benefit_active` (`tenant_id`, `benefit_type`, `is_active`),
    INDEX `idx_effective` (`tenant_id`, `benefit_type`, `effective_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
