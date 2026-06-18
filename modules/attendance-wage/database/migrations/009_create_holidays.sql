-- Migration 009: Holidays calendar
CREATE TABLE IF NOT EXISTS `holidays` (
    `holiday_id`       INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`        VARCHAR(36) NOT NULL,
    `holiday_name`     VARCHAR(200) NOT NULL,
    `holiday_date`     DATE NOT NULL,
    `holiday_type`     ENUM('regular','special_non_working','special_working') DEFAULT 'regular',
    `pay_multiplier`   DECIMAL(4,2) DEFAULT 2.00 COMMENT 'e.g. 2.00 = double pay',
    `is_recurring`     TINYINT(1) DEFAULT 0 COMMENT 'Repeats yearly',
    `is_active`        TINYINT(1) DEFAULT 1,
    `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`holiday_id`),
    UNIQUE INDEX `idx_tenant_date` (`tenant_id`, `holiday_date`),
    INDEX `idx_year` (`tenant_id`, `holiday_date`, `is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
