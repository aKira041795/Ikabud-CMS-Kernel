CREATE TABLE IF NOT EXISTS `learning_resources` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id` BIGINT UNSIGNED NOT NULL,
    `provider` VARCHAR(50) NOT NULL,
    `provider_id` VARCHAR(191) NOT NULL,
    `title` VARCHAR(255) NOT NULL,
    `metadata_json` LONGTEXT DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_learning_resources_tenant_provider` (`tenant_id`, `provider`, `provider_id`),
    KEY `idx_learning_resources_tenant_provider` (`tenant_id`, `provider`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `moodle_courses_cache`
    ADD COLUMN `resource_id` BIGINT UNSIGNED DEFAULT NULL AFTER `tenant_id`,
    ADD COLUMN `moodle_category_id` BIGINT UNSIGNED DEFAULT NULL AFTER `moodle_course_id`,
    ADD COLUMN `moodle_category_key` VARCHAR(191) DEFAULT NULL AFTER `moodle_category_id`,
    ADD KEY `idx_moodle_courses_cache_resource` (`tenant_id`, `resource_id`),
    ADD KEY `idx_moodle_courses_cache_category` (`tenant_id`, `moodle_category_id`),
    ADD KEY `idx_moodle_courses_cache_category_key` (`tenant_id`, `moodle_category_key`);

INSERT INTO `learning_resources` (`tenant_id`, `provider`, `provider_id`, `title`, `metadata_json`, `created_at`, `updated_at`)
SELECT c.`tenant_id`, 'moodle', CAST(c.`moodle_course_id` AS CHAR), c.`title`, NULL, c.`created_at`, c.`updated_at`
FROM `moodle_courses_cache` c
LEFT JOIN `learning_resources` lr
    ON lr.`tenant_id` = c.`tenant_id`
   AND lr.`provider` = 'moodle'
    AND lr.`provider_id` = (CAST(c.`moodle_course_id` AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci)
WHERE lr.`id` IS NULL;

UPDATE `moodle_courses_cache` c
JOIN `learning_resources` lr
    ON lr.`tenant_id` = c.`tenant_id`
   AND lr.`provider` = 'moodle'
    AND lr.`provider_id` = (CAST(c.`moodle_course_id` AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci)
SET c.`resource_id` = lr.`id`
WHERE c.`resource_id` IS NULL;

ALTER TABLE `moodle_enrollment_requests`
    ADD COLUMN `learning_resource_id` BIGINT UNSIGNED DEFAULT NULL AFTER `user_id`,
    ADD COLUMN `enrollment_mode` VARCHAR(50) NOT NULL DEFAULT 'manual_review' AFTER `status`,
    ADD KEY `idx_moodle_enrollment_requests_resource` (`tenant_id`, `learning_resource_id`);

UPDATE `moodle_enrollment_requests` r
LEFT JOIN `moodle_courses_cache` c
    ON c.`tenant_id` = r.`tenant_id`
   AND c.`moodle_course_id` = r.`moodle_course_id`
SET r.`learning_resource_id` = c.`resource_id`
WHERE r.`learning_resource_id` IS NULL;

CREATE TABLE IF NOT EXISTS `moodle_sso_tokens` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id` BIGINT UNSIGNED NOT NULL,
    `user_id` BIGINT UNSIGNED NOT NULL,
    `learning_resource_id` BIGINT UNSIGNED DEFAULT NULL,
    `token_hash` CHAR(64) NOT NULL,
    `expires_at` DATETIME NOT NULL,
    `used_at` DATETIME DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_moodle_sso_tokens_hash` (`token_hash`),
    KEY `idx_moodle_sso_tokens_tenant_expiry` (`tenant_id`, `expires_at`),
    KEY `idx_moodle_sso_tokens_tenant_used` (`tenant_id`, `used_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `moodle_sync_metrics` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id` BIGINT UNSIGNED NOT NULL,
    `sync_type` VARCHAR(100) NOT NULL,
    `success_count` INT UNSIGNED NOT NULL DEFAULT 0,
    `failure_count` INT UNSIGNED NOT NULL DEFAULT 0,
    `avg_duration_ms` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `last_run` DATETIME DEFAULT NULL,
    `last_error` TEXT DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_moodle_sync_metrics_tenant_type` (`tenant_id`, `sync_type`),
    KEY `idx_moodle_sync_metrics_last_run` (`tenant_id`, `last_run`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;