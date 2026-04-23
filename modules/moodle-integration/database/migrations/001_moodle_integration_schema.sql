CREATE TABLE IF NOT EXISTS `moodle_courses_cache` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id` BIGINT UNSIGNED NOT NULL,
    `moodle_course_id` BIGINT UNSIGNED NOT NULL,
    `title` VARCHAR(255) NOT NULL,
    `summary` MEDIUMTEXT DEFAULT NULL,
    `image` TEXT DEFAULT NULL,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_moodle_courses_cache_tenant_course` (`tenant_id`, `moodle_course_id`),
    KEY `idx_moodle_courses_cache_tenant_updated` (`tenant_id`, `updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `moodle_user_progress` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id` BIGINT UNSIGNED NOT NULL,
    `user_id` BIGINT UNSIGNED NOT NULL,
    `course_id` BIGINT UNSIGNED NOT NULL,
    `progress_percent` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    `grade` DECIMAL(8,2) DEFAULT NULL,
    `status` VARCHAR(50) NOT NULL DEFAULT 'not_started',
    `last_synced` DATETIME DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_moodle_user_progress_tenant_user_course` (`tenant_id`, `user_id`, `course_id`),
    KEY `idx_moodle_user_progress_tenant_user` (`tenant_id`, `user_id`),
    KEY `idx_moodle_user_progress_tenant_course` (`tenant_id`, `course_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `moodle_sync_queue` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id` BIGINT UNSIGNED NOT NULL,
    `type` VARCHAR(100) NOT NULL,
    `payload_json` LONGTEXT NOT NULL,
    `status` VARCHAR(50) NOT NULL DEFAULT 'pending',
    `retries` INT UNSIGNED NOT NULL DEFAULT 0,
    `last_error` TEXT DEFAULT NULL,
    `available_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `processed_at` DATETIME DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_moodle_sync_queue_tenant_status` (`tenant_id`, `status`),
    KEY `idx_moodle_sync_queue_available` (`tenant_id`, `available_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;