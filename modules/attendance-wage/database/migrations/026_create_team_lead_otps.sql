-- Tenant-scoped, single-use storage for team-lead OTP verification.
-- Codes are encrypted at rest; only a password hash is used for verification.

CREATE TABLE IF NOT EXISTS `attendance_team_lead_otps` (
    `otp_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id` VARCHAR(36) NOT NULL,
    `group_id` INT UNSIGNED NOT NULL,
    `email` VARCHAR(255) NOT NULL,
    `code_hash` VARCHAR(255) NOT NULL,
    `code_ciphertext` TEXT NOT NULL,
    `expires_at` DATETIME NOT NULL,
    `consumed_at` DATETIME DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`otp_id`),
    INDEX `idx_aw_tl_otp_scope` (`tenant_id`, `email`, `group_id`, `consumed_at`, `expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
