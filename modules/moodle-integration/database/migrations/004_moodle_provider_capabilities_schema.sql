-- Provider capability registry: one row per LMS provider.
-- Capabilities are declared as a JSON object so the system can degrade
-- gracefully when a future provider does not support grades, SCORM, etc.
CREATE TABLE IF NOT EXISTS `learning_providers` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `slug` VARCHAR(100) NOT NULL,
    `name` VARCHAR(255) NOT NULL,
    `capabilities_json` LONGTEXT DEFAULT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_learning_providers_slug` (`slug`),
    KEY `idx_learning_providers_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed the Moodle provider. Use INSERT IGNORE so re-running the migration
-- is always safe; capabilities can be updated by the operator if needed.
INSERT IGNORE INTO `learning_providers` (`slug`, `name`, `capabilities_json`, `is_active`) VALUES (
    'moodle',
    'Moodle LMS',
    '{"supports_courses":true,"supports_progress":true,"supports_grades":true,"supports_sso":true,"supports_scorm":false,"supports_enrollment_api":true}',
    1
);

-- Soft-deactivation lifecycle for learning resources.
-- 'active' = course is available in the upstream provider.
-- 'inactive' = course disappeared, moved, or the tenant disconnected the provider.
-- Historical progress rows still reference inactive resources so reports remain intact.
ALTER TABLE `learning_resources`
    ADD COLUMN `status` VARCHAR(50) NOT NULL DEFAULT 'active' AFTER `title`,
    ADD KEY `idx_learning_resources_tenant_status` (`tenant_id`, `status`);

-- Outbound throttle tracking. One row per (tenant_id, minute-window).
-- Used by MoodleService to enforce max_requests_per_minute.
CREATE TABLE IF NOT EXISTS `moodle_rate_limit` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id` BIGINT UNSIGNED NOT NULL,
    `window_start` DATETIME NOT NULL,
    `request_count` INT UNSIGNED NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_moodle_rate_limit_tenant_window` (`tenant_id`, `window_start`),
    KEY `idx_moodle_rate_limit_window` (`window_start`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
