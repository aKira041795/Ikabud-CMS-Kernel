-- Migration 003: Employee schedules (weekly shift assignments)
CREATE TABLE IF NOT EXISTS `employee_schedules` (
    `schedule_id`    INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`      VARCHAR(36) NOT NULL,
    `user_id`        INT UNSIGNED NOT NULL,
    `store_id`       INT UNSIGNED DEFAULT NULL,
    `day_of_week`    ENUM('monday','tuesday','wednesday','thursday','friday','saturday','sunday') NOT NULL,
    `week_number`    TINYINT UNSIGNED DEFAULT 1 COMMENT 'Week 1-4 of month',
    `shift_type`     ENUM('day','night','rotating') DEFAULT 'day',
    `start_time`     TIME DEFAULT NULL,
    `end_time`       TIME DEFAULT NULL,
    `is_dayoff`      TINYINT(1) DEFAULT 0,
    `created_by`     INT UNSIGNED DEFAULT NULL,
    `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`schedule_id`),
    UNIQUE INDEX `idx_tenant_user_day_week` (`tenant_id`, `user_id`, `store_id`, `day_of_week`, `week_number`),
    INDEX `idx_store_day` (`store_id`, `day_of_week`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
