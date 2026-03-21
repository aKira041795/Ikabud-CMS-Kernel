-- Control plane migration: tenant registry tables
-- This migration must be run against the CONTROL DB.

CREATE TABLE IF NOT EXISTS `kernel_tenants` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_key`    VARCHAR(80) NOT NULL,
    `status`        VARCHAR(30) NOT NULL DEFAULT 'active',
    `entry_module_id` VARCHAR(100) DEFAULT NULL,
    `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_tenant_key` (`tenant_key`),
    KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `kernel_tenant_domains` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`  INT UNSIGNED NOT NULL,
    `domain`     VARCHAR(255) NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_domain` (`domain`),
    KEY `idx_tenant` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `kernel_tenant_db_connections` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`  INT UNSIGNED NOT NULL,
    `db_driver`  VARCHAR(20) NOT NULL DEFAULT 'mysql',
    `db_host`    VARCHAR(255) NOT NULL,
    `db_port`    VARCHAR(10) NOT NULL DEFAULT '3306',
    `db_name`    VARCHAR(255) NOT NULL,
    `db_user`    VARCHAR(255) NOT NULL,
    `db_pass`    TEXT DEFAULT NULL,
    `db_charset` VARCHAR(30) NOT NULL DEFAULT 'utf8mb4',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_tenant_conn` (`tenant_id`),
    KEY `idx_tenant` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
