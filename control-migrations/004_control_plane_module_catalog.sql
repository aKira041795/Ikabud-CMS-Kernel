-- Control plane migration: approved module catalog + tenant entitlements
-- This migration must be run against the CONTROL DB.

CREATE TABLE IF NOT EXISTS `kernel_module_catalog` (
    `id`                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `module_id`          VARCHAR(100) NOT NULL,
    `module_name`        VARCHAR(190) DEFAULT NULL,
    `approved_version`   VARCHAR(60) DEFAULT NULL,
    `checksum_sha256`    CHAR(64) DEFAULT NULL,
    `install_path`       VARCHAR(255) DEFAULT NULL,
    `source`             VARCHAR(40) NOT NULL DEFAULT 'admin_install',
    `approval_status`    VARCHAR(20) NOT NULL DEFAULT 'pending',
    `commercial_mode`    VARCHAR(20) NOT NULL DEFAULT 'free',
    `origin_tenant_id`   INT UNSIGNED DEFAULT NULL,
    `approved_by_user_id` INT UNSIGNED DEFAULT NULL,
    `approved_at`        DATETIME DEFAULT NULL,
    `created_at`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_module_id` (`module_id`),
    KEY `idx_approval_status` (`approval_status`),
    KEY `idx_commercial_mode` (`commercial_mode`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `kernel_tenant_module_entitlements` (
    `id`                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`          INT UNSIGNED NOT NULL,
    `module_id`          VARCHAR(100) NOT NULL,
    `status`             VARCHAR(20) NOT NULL DEFAULT 'active',
    `tier`               VARCHAR(40) NOT NULL DEFAULT 'free',
    `source`             VARCHAR(40) NOT NULL DEFAULT 'superadmin',
    `granted_by_user_id` INT UNSIGNED DEFAULT NULL,
    `expires_at`         DATETIME DEFAULT NULL,
    `metadata_json`      LONGTEXT DEFAULT NULL,
    `granted_at`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `created_at`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_tenant_module` (`tenant_id`, `module_id`),
    KEY `idx_module_status` (`module_id`, `status`),
    KEY `idx_tenant_status` (`tenant_id`, `status`),
    KEY `idx_expires_at` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;