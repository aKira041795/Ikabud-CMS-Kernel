-- HARPP reviewable artifact bundles (chair-approved contract, incl. downloadable file kind).
-- Auto-derived at approval time (CLOSED/DECIDED decisions, SUCCEEDED runs) from canonical
-- harpp_adrs + harpp_decisions + harpp_work_runs, plus owner-attachable downloadable files.
-- Additive; tenant/workspace-scoped; MySQL 5.7-safe.

CREATE TABLE IF NOT EXISTS `harpp_artifact_bundles` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `aggregate_type` ENUM('decision','run') NOT NULL,
    `aggregate_id` INT UNSIGNED NOT NULL,
    `workspace_id` INT UNSIGNED NULL,
    `status` ENUM('pending','ready') NOT NULL DEFAULT 'pending',
    `created_by` INT UNSIGNED NULL,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_harpp_artifact_bundle_agg` (`aggregate_type`,`aggregate_id`),
    KEY `idx_harpp_artifact_bundle_workspace` (`workspace_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `harpp_artifacts` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `bundle_id` BIGINT UNSIGNED NOT NULL,
    `artifact_type` ENUM('adr','decision','contract','stage_result','file') NOT NULL,
    `source_ref` VARCHAR(255) NULL,
    `filename` VARCHAR(255) NULL,
    `mime` VARCHAR(120) NULL,
    `payload` LONGTEXT NULL,
    `file_size` INT UNSIGNED NULL,
    `created_by` INT UNSIGNED NULL,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id`),
    KEY `idx_harpp_artifacts_bundle` (`bundle_id`),
    CONSTRAINT `fk_harpp_artifacts_bundle` FOREIGN KEY (`bundle_id`) REFERENCES `harpp_artifact_bundles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `harpp_artifact_shares` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `bundle_id` BIGINT UNSIGNED NOT NULL,
    `reviewer_user_id` INT UNSIGNED NOT NULL,
    `token_hash` CHAR(64) NOT NULL,
    `expires_at` DATETIME(6) NULL,
    `revoked_at` DATETIME(6) NULL,
    `created_by` INT UNSIGNED NULL,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_harpp_artifact_shares_token` (`token_hash`),
    KEY `idx_harpp_artifact_shares_bundle` (`bundle_id`),
    KEY `idx_harpp_artifact_shares_reviewer` (`reviewer_user_id`),
    CONSTRAINT `fk_harpp_artifact_shares_bundle` FOREIGN KEY (`bundle_id`) REFERENCES `harpp_artifact_bundles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
