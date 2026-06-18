-- Migration 001: Attendance records
CREATE TABLE IF NOT EXISTS `attendance_records` (
    `attendance_id`   INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`       VARCHAR(36) NOT NULL,
    `user_id`         INT UNSIGNED NOT NULL,
    `store_id`        INT UNSIGNED DEFAULT NULL,
    `clock_in`        DATETIME NOT NULL,
    `clock_out`       DATETIME DEFAULT NULL,
    `photo_in`        VARCHAR(255) DEFAULT NULL COMMENT 'Filename of clock-in photo',
    `photo_out`       VARCHAR(255) DEFAULT NULL COMMENT 'Filename of clock-out photo',
    `location_in`     VARCHAR(255) DEFAULT NULL,
    `location_out`    VARCHAR(255) DEFAULT NULL,
    `status`          ENUM('active','completed','edited') DEFAULT 'active',
    `notes`           TEXT DEFAULT NULL,
    `last_edited_by`  INT UNSIGNED DEFAULT NULL,
    `last_edited_at`  DATETIME DEFAULT NULL,
    `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`attendance_id`),
    INDEX `idx_tenant_user` (`tenant_id`, `user_id`),
    INDEX `idx_clock_in_date` (`tenant_id`, `clock_in`),
    INDEX `idx_store` (`store_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
