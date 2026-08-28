CREATE TABLE IF NOT EXISTS `harpp_daemon_status` (
    `runner_key` VARCHAR(191) NOT NULL,
    `last_seen_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `daemon_version` VARCHAR(64) NULL,
    `workflow_counts_json` JSON NULL,
    `recent_workflows_json` JSON NULL,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`runner_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;