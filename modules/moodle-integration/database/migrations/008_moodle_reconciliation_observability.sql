-- Reconciliation observability schema additions for moodle-integration.
--
-- This migration adds two new observability features:
--
-- 1) moodle_sync_discrepancies table: audit table recording meaningful discrepancies
--    detected when the scheduled sync finds different progress values than what the
--    webhook previously wrote (delta >= 1% or status change).
--    Created first so it is always present even if the ALTER TABLE statements below
--    fail with error 1060 (Duplicate column name) on environments where moodle_sync_metrics
--    was already created with these columns by a prior partial migration run.
--    The migration runner treats error 1060 as idempotent but exits the migration on the
--    first such error, so safe tables must come before potentially-idempotent ones.
--
-- 2) moodle_sync_metrics columns last_full_sync_at and last_progress_sync_at:
--    Updated ONLY on successful sync completions (not on every attempt like last_run).
--    Used for staleness SLA checks and admin dashboard freshness indicators.
--    Fresh install: CREATE TABLE IF NOT EXISTS creates the table with all columns.
--    Upgrade path: ALTER TABLE adds the columns; error 1060 is silently ignored by the runner.
CREATE TABLE IF NOT EXISTS `moodle_sync_discrepancies` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id` BIGINT UNSIGNED NOT NULL,
    `user_id` BIGINT UNSIGNED NOT NULL,
    `learning_resource_id` BIGINT UNSIGNED NOT NULL,
    `source` VARCHAR(50) NOT NULL DEFAULT 'scheduled_sync',
    `cached_progress` DECIMAL(5,2) DEFAULT NULL,
    `actual_progress` DECIMAL(5,2) DEFAULT NULL,
    `cached_status` VARCHAR(50) DEFAULT NULL,
    `actual_status` VARCHAR(50) DEFAULT NULL,
    `delta_percent` DECIMAL(6,2) DEFAULT NULL,
    `detected_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `resolved_at` DATETIME DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_sync_discrepancies_tenant_user` (`tenant_id`, `user_id`),
    KEY `idx_sync_discrepancies_tenant_resource` (`tenant_id`, `learning_resource_id`),
    KEY `idx_sync_discrepancies_detected` (`tenant_id`, `detected_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `moodle_sync_metrics` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id` BIGINT UNSIGNED NOT NULL,
    `sync_type` VARCHAR(100) NOT NULL,
    `success_count` INT UNSIGNED NOT NULL DEFAULT 0,
    `failure_count` INT UNSIGNED NOT NULL DEFAULT 0,
    `avg_duration_ms` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `last_run` DATETIME DEFAULT NULL,
    `last_full_sync_at` DATETIME DEFAULT NULL,
    `last_progress_sync_at` DATETIME DEFAULT NULL,
    `last_error` TEXT DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_moodle_sync_metrics_tenant_type` (`tenant_id`, `sync_type`),
    KEY `idx_moodle_sync_metrics_last_run` (`tenant_id`, `last_run`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- For environments where moodle_sync_metrics already existed without these columns.
-- Error 1060 (Duplicate column name) is treated as idempotent by the migration runner.
ALTER TABLE `moodle_sync_metrics`
    ADD COLUMN `last_full_sync_at` DATETIME DEFAULT NULL AFTER `last_run`;

ALTER TABLE `moodle_sync_metrics`
    ADD COLUMN `last_progress_sync_at` DATETIME DEFAULT NULL AFTER `last_full_sync_at`;
