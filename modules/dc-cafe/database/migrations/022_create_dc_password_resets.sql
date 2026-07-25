-- 022_create_dc_password_resets.sql
-- Token table for forgot/reset password flow.
-- @mysql57-compat: InnoDB, utf8mb4.

CREATE TABLE IF NOT EXISTS `dc_password_resets` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `token_hash` CHAR(64) NOT NULL,
    `requester_ip` VARCHAR(64) NULL DEFAULT NULL,
    `expires_at` DATETIME NOT NULL,
    `used_at` DATETIME NULL DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_dc_password_resets_token_hash` (`token_hash`),
    KEY `idx_dc_password_resets_user_id` (`user_id`),
    KEY `idx_dc_password_resets_expires_at` (`expires_at`),
    KEY `idx_dc_password_resets_used_at` (`used_at`),
    CONSTRAINT `fk_dc_password_resets_user` FOREIGN KEY (`user_id`) REFERENCES `dc_users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
