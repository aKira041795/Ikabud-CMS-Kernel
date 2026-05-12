-- ============================================================
-- Daily Ledger Module — email-aware auth + password resets
--
-- Adds an optional email column to dl_users so accounts can sign in via
-- email and receive password reset links, then creates the token table
-- used by the Daily Ledger forgot/reset password flow.
-- ============================================================

SET @dl_users_email_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'dl_users' AND column_name = 'email'
);
SET @sql := IF(@dl_users_email_exists = 0,
    'ALTER TABLE dl_users ADD COLUMN email VARCHAR(190) NULL DEFAULT NULL AFTER username',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

UPDATE dl_users
   SET email = LOWER(username)
 WHERE email IS NULL
   AND username LIKE '%@%';

SET @dl_users_email_idx_exists := (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'dl_users' AND index_name = 'uq_dl_users_email'
);
SET @sql := IF(@dl_users_email_idx_exists = 0,
    'ALTER TABLE dl_users ADD UNIQUE KEY uq_dl_users_email (email)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS dl_password_resets (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL,
    requester_ip VARCHAR(64) NULL DEFAULT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_dl_password_resets_token_hash (token_hash),
    INDEX idx_dl_password_resets_user (user_id),
    INDEX idx_dl_password_resets_expires (expires_at),
    INDEX idx_dl_password_resets_used (used_at),
    CONSTRAINT fk_dl_password_resets_user FOREIGN KEY (user_id) REFERENCES dl_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;