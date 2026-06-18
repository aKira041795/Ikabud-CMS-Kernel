-- Migration 019: Office locations with geo-fence for attendance
CREATE TABLE IF NOT EXISTS `office_locations` (
    `location_id`   INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`     VARCHAR(36) NOT NULL,
    `name`          VARCHAR(200) NOT NULL COMMENT 'Office/branch name',
    `address`       TEXT DEFAULT NULL COMMENT 'Physical address',
    `latitude`      DECIMAL(10,7) NOT NULL,
    `longitude`     DECIMAL(10,7) NOT NULL,
    `radius_meters` INT UNSIGNED NOT NULL DEFAULT 100 COMMENT 'Geo-fence radius in meters',
    `is_active`     TINYINT(1) NOT NULL DEFAULT 1,
    `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`location_id`),
    INDEX `idx_tenant_active` (`tenant_id`, `is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
