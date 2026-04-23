CREATE TABLE IF NOT EXISTS `moodle_enrollment_requests` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id` BIGINT UNSIGNED NOT NULL,
    `user_id` BIGINT UNSIGNED NOT NULL,
    `moodle_course_id` BIGINT UNSIGNED NOT NULL,
    `status` VARCHAR(50) NOT NULL DEFAULT 'pending_review',
    `review_notes` TEXT DEFAULT NULL,
    `requested_by_source` VARCHAR(50) NOT NULL DEFAULT 'cms',
    `reviewed_by_user_id` BIGINT UNSIGNED DEFAULT NULL,
    `sync_queue_id` BIGINT UNSIGNED DEFAULT NULL,
    `requested_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `reviewed_at` DATETIME DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_moodle_enrollment_requests_tenant_user_course` (`tenant_id`, `user_id`, `moodle_course_id`),
    KEY `idx_moodle_enrollment_requests_tenant_status` (`tenant_id`, `status`),
    KEY `idx_moodle_enrollment_requests_tenant_user` (`tenant_id`, `user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;