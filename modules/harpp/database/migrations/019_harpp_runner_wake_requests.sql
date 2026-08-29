CREATE TABLE IF NOT EXISTS `harpp_runner_wake_requests` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `runner_key` VARCHAR(191) NOT NULL,
    `status` ENUM('pending','claimed','delivered','failed','expired') NOT NULL DEFAULT 'pending',
    `requested_by` INT UNSIGNED NULL,
    `idempotency_key` VARCHAR(191) NULL,
    `requested_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `available_at` DATETIME(6) NULL,
    `claimed_at` DATETIME(6) NULL,
    `claim_token` CHAR(36) NULL,
    `delivered_at` DATETIME(6) NULL,
    `attempts` INT UNSIGNED NOT NULL DEFAULT 0,
    `last_error` VARCHAR(2000) NULL,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id`),
    KEY `idx_harpp_wake_claimable` (`status`,`runner_key`,`id`),
    KEY `idx_harpp_wake_runner` (`runner_key`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;