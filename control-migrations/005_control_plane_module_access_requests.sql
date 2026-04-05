-- Control plane migration: tenant module access requests
-- This migration must be run against the CONTROL DB.

CREATE TABLE IF NOT EXISTS `kernel_tenant_module_access_requests` (
    `id`                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`             INT UNSIGNED NOT NULL,
    `module_id`             VARCHAR(100) NOT NULL,
    `requested_mode`        VARCHAR(20) NOT NULL DEFAULT 'paid',
    `status`                VARCHAR(20) NOT NULL DEFAULT 'pending',
    `request_notes`         TEXT DEFAULT NULL,
    `license_ref`           VARCHAR(80) DEFAULT NULL,
    `license_key_ciphertext` LONGTEXT DEFAULT NULL,
    `license_key_iv`        VARCHAR(255) DEFAULT NULL,
    `license_key_tag`       VARCHAR(255) DEFAULT NULL,
    `requested_by_user_id`  INT UNSIGNED DEFAULT NULL,
    `reviewed_by_user_id`   INT UNSIGNED DEFAULT NULL,
    `review_notes`          TEXT DEFAULT NULL,
    `metadata_json`         LONGTEXT DEFAULT NULL,
    `reviewed_at`           DATETIME DEFAULT NULL,
    `created_at`            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_tenant_module_request` (`tenant_id`, `module_id`),
    KEY `idx_access_request_status` (`status`),
    KEY `idx_access_request_module_status` (`module_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;