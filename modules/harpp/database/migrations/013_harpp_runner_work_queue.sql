CREATE TABLE IF NOT EXISTS `harpp_runners` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `runner_key` VARCHAR(191) NOT NULL,
    `display_name` VARCHAR(255) NOT NULL,
    `status` ENUM('online','offline') NOT NULL DEFAULT 'online',
    `capabilities_json` JSON NOT NULL,
    `last_heartbeat_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_harpp_runners_key` (`runner_key`),
    KEY `idx_harpp_runners_status_heartbeat` (`status`,`last_heartbeat_at`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `harpp_work_runs` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `source_message_id` INT UNSIGNED NOT NULL,
    `conversation_id` INT UNSIGNED NOT NULL,
    `state` ENUM('QUEUED','WAITING_FOR_RUNNER','CLAIMED','RUNNING','STALLED','SUCCEEDED','FAILED','CANCELLED') NOT NULL DEFAULT 'QUEUED',
    `report_state` ENUM('PENDING','DELIVERED','DEAD_LETTER') NOT NULL DEFAULT 'PENDING',
    `required_capabilities_json` JSON NOT NULL,
    `claim_token` CHAR(36) NULL,
    `runner_key` VARCHAR(191) NULL,
    `lease_expires_at` DATETIME(6) NULL,
    `started_at` DATETIME(6) NULL,
    `finished_at` DATETIME(6) NULL,
    `last_status` VARCHAR(2000) NULL,
    `result_json` JSON NULL,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_harpp_work_runs_source_message` (`source_message_id`),
    KEY `idx_harpp_work_runs_claimable` (`state`,`lease_expires_at`,`id`),
    KEY `idx_harpp_work_runs_conversation` (`conversation_id`,`id`),
    KEY `idx_harpp_work_runs_runner` (`runner_key`,`state`,`id`),
    CONSTRAINT `fk_harpp_work_runs_message` FOREIGN KEY (`source_message_id`) REFERENCES `harpp_messages` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_harpp_work_runs_conversation` FOREIGN KEY (`conversation_id`) REFERENCES `harpp_conversations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
