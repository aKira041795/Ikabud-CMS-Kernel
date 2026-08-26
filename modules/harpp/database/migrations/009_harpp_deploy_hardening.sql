-- HARPP deploy hardening (2026-08-27, module 2.2.0):
--   * claim leases + stale reclaim recovery (a crashed worker's CLAIMED job can
--     be reclaimed after lease expiry instead of sticking forever);
--   * atomic in-progress dedup via a nullable unique active_dedup_key
--     (NULL values never collide in a unique index, so terminal jobs free the
--     slot; concurrent duplicate inserts are rejected at the DB level);
--   * a dedicated atomic bridge-auth rate-limit bucket table.
-- MySQL 5.7 compatible, additive only.

-- Claim lease on deploy jobs. The worker refreshes this lease on every
-- progress report; pending() and claim() treat an expired lease as reclaimable.
ALTER TABLE `harpp_deploy_jobs`
    ADD COLUMN `claim_expires_at` DATETIME(6) NULL AFTER `heartbeat_at`,
    ADD KEY `idx_harpp_deploy_claim_expiry` (`status`,`claim_expires_at`);

-- Atomic in-progress dedup key: '<package>:<profile>' while non-terminal,
-- NULL once terminal. Unique index enforces a single in-progress deploy per
-- package+profile atomically (fixes the select-then-insert race).
ALTER TABLE `harpp_deploy_jobs`
    ADD COLUMN `active_dedup_key` VARCHAR(400) NULL AFTER `request_hash`,
    ADD UNIQUE KEY `uq_harpp_deploy_active_dedup` (`active_dedup_key`);

-- Atomic bridge-auth rate bucket: failures + window_start as integers so the
-- increment can be performed in a single INSERT ... ON DUPLICATE KEY UPDATE
-- (row-locked, no read/modify/upsert race that can lose concurrent failures).
CREATE TABLE IF NOT EXISTS `harpp_bridge_rate_limit` (
    `bucket` VARCHAR(64) NOT NULL,
    `failures` INT UNSIGNED NOT NULL DEFAULT 0,
    `window_start` INT UNSIGNED NOT NULL,
    `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`bucket`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
