-- HARPP deploy capability (R-FTP MVP): phone GUI queues deploys from the live
-- host; the operator's local client claims and executes the FTP/SFTP upload and
-- reports back. FTP credentials never enter these tables (profiles carry only
-- non-secret metadata: name/host/transport/root/ops). MySQL 5.7 compatible,
-- additive only.

CREATE TABLE IF NOT EXISTS `harpp_deploy_jobs` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `requested_by` INT UNSIGNED NOT NULL,
    `package_name` VARCHAR(255) NOT NULL,
    `profile_name` VARCHAR(128) NOT NULL,
    `status` VARCHAR(24) NOT NULL DEFAULT 'QUEUED',
    `claim_token` VARCHAR(64) NULL,
    `claimed_at` DATETIME(6) NULL,
    `heartbeat_at` DATETIME(6) NULL,
    `request_hash` CHAR(64) NULL,
    `receipt_json` MEDIUMTEXT NULL,
    `error` VARCHAR(2000) NULL,
    `version` INT UNSIGNED NOT NULL DEFAULT 1,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id`),
    KEY `idx_harpp_deploy_status` (`status`,`id`),
    KEY `idx_harpp_deploy_created` (`created_at`,`id`),
    CONSTRAINT `fk_harpp_deploy_requestor` FOREIGN KEY (`requested_by`) REFERENCES `harpp_users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `harpp_deploy_inventory` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `scope_key` VARCHAR(24) NOT NULL,
    `item_key` VARCHAR(255) NOT NULL,
    `payload_json` TEXT NULL,
    `publisher` VARCHAR(64) NOT NULL DEFAULT 'local-client',
    `last_seen_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_harpp_deploy_inventory` (`scope_key`,`item_key`),
    KEY `idx_harpp_deploy_inventory_seen` (`last_seen_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
