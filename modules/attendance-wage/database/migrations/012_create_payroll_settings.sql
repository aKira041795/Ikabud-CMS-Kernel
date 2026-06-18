-- Migration 012: Payroll settings (tenant-level configuration)
CREATE TABLE IF NOT EXISTS `payroll_settings` (
    `setting_id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`              VARCHAR(36) NOT NULL,
    `working_days_per_month` INT DEFAULT 22,
    `working_hours_per_day`  DECIMAL(4,2) DEFAULT 8.00,
    `overtime_calculation`   ENUM('daily','weekly','both') DEFAULT 'both',
    `round_hours_to`         ENUM('none','0.25','0.5','1.0') DEFAULT '0.25',
    `pay_frequency`          ENUM('semi_monthly','monthly','weekly','bi_weekly') DEFAULT 'semi_monthly',
    `default_rest_day`       VARCHAR(20) DEFAULT 'sunday',
    `rest_day_rate`          DECIMAL(4,2) DEFAULT 1.30,
    `night_diff_rate`        DECIMAL(5,2) DEFAULT 0.10,
    `max_cash_advance_pct`   DECIMAL(5,2) DEFAULT 50.00 COMMENT 'Max % of monthly salary',
    `max_active_advances`    INT DEFAULT 2,
    `created_at`             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`setting_id`),
    UNIQUE INDEX `idx_tenant` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
