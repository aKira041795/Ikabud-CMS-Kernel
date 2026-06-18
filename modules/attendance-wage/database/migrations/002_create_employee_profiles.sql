-- Migration 002: Employee profiles
CREATE TABLE IF NOT EXISTS `employee_profiles` (
    `profile_id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`                VARCHAR(36) NOT NULL,
    `user_id`                  INT UNSIGNED NOT NULL,
    `employee_number`          VARCHAR(20) DEFAULT NULL,
    `position`                 VARCHAR(100) DEFAULT NULL,
    `department`               VARCHAR(100) DEFAULT NULL,
    `hire_date`                DATE DEFAULT NULL,
    `employment_status`        ENUM('probationary','regular','contractual','part_time','terminated') DEFAULT 'probationary',
    `salary_type`              ENUM('hourly','daily','monthly','fixed') DEFAULT 'daily',
    `basic_salary`             DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `hourly_rate`              DECIMAL(10,2) DEFAULT 0.00,
    `daily_rate`               DECIMAL(10,2) DEFAULT 0.00,
    `monthly_rate`             DECIMAL(12,2) DEFAULT 0.00,
    -- Overtime policy
    `overtime_allowed`         TINYINT(1) DEFAULT 1,
    `overtime_rate`            DECIMAL(4,2) DEFAULT 1.25 COMMENT 'Standard OT multiplier',
    `double_overtime_rate`     DECIMAL(4,2) DEFAULT 1.50 COMMENT 'Double OT multiplier (beyond 10h or weekly cap)',
    `overtime_requires_approval` TINYINT(1) DEFAULT 0,
    `max_daily_hours`          DECIMAL(4,2) DEFAULT 8.00,
    `max_weekly_hours`         DECIMAL(5,2) DEFAULT 40.00,
    -- Holiday pay
    `holiday_pay_enabled`      TINYINT(1) DEFAULT 1,
    -- Rest day
    `rest_day_schedule`        VARCHAR(20) DEFAULT 'sunday',
    `rest_day_pay_enabled`     TINYINT(1) DEFAULT 1,
    `rest_day_rate`            DECIMAL(4,2) DEFAULT 1.30,
    -- Night differential
    `night_diff_enabled`       TINYINT(1) DEFAULT 1,
    `night_diff_rate`          DECIMAL(5,2) DEFAULT 0.10,
    -- Government IDs
    `sss_number`               VARCHAR(20) DEFAULT NULL,
    `sss_applicable`           TINYINT(1) DEFAULT 1,
    `philhealth_number`        VARCHAR(20) DEFAULT NULL,
    `philhealth_applicable`    TINYINT(1) DEFAULT 1,
    `pagibig_number`           VARCHAR(20) DEFAULT NULL,
    `pagibig_applicable`       TINYINT(1) DEFAULT 1,
    `tin_number`               VARCHAR(20) DEFAULT NULL,
    `tax_exemption_status`     ENUM('single','married','head_of_family') DEFAULT 'single',
    `dependents_count`         INT DEFAULT 0,
    `is_government_employee`   TINYINT(1) DEFAULT 0,
    -- Cash advance eligibility
    `cash_advance_allowed`     TINYINT(1) DEFAULT 1,
    -- Status
    `is_active`                TINYINT(1) DEFAULT 1,
    `created_at`               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`profile_id`),
    UNIQUE INDEX `idx_tenant_user` (`tenant_id`, `user_id`),
    INDEX `idx_employee_number` (`employee_number`),
    INDEX `idx_department` (`department`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
