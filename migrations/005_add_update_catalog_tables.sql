CREATE TABLE IF NOT EXISTS `kernel_update_catalog` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `release_type` ENUM('kernel', 'module') NOT NULL,
    `item_id` VARCHAR(120) NOT NULL,
    `version` VARCHAR(64) NOT NULL,
    `channel` VARCHAR(30) NOT NULL DEFAULT 'stable',
    `source_repo` VARCHAR(190) NOT NULL,
    `source_tag` VARCHAR(190) DEFAULT NULL,
    `title` VARCHAR(255) DEFAULT NULL,
    `release_url` VARCHAR(500) DEFAULT NULL,
    `summary` TEXT DEFAULT NULL,
    `published_at` DATETIME DEFAULT NULL,
    `payload_json` JSON DEFAULT NULL,
    `is_latest` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_kernel_update_item_version` (`release_type`, `item_id`, `version`),
    KEY `idx_kernel_update_lookup` (`release_type`, `item_id`, `channel`, `is_latest`),
    KEY `idx_kernel_update_published` (`published_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `kernel_update_sync_state` (
    `state_key` VARCHAR(120) NOT NULL,
    `state_value` TEXT DEFAULT NULL,
    `state_json` JSON DEFAULT NULL,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`state_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;