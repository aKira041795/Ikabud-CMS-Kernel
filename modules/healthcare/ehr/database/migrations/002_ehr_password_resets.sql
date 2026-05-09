CREATE TABLE IF NOT EXISTS `ehr_password_resets` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` BIGINT UNSIGNED NOT NULL,
    `token_hash` CHAR(64) NOT NULL,
    `requester_ip` VARCHAR(64) NOT NULL DEFAULT '',
    `expires_at` DATETIME NOT NULL,
    `used_at` DATETIME NULL DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_ehr_password_resets_token_hash` (`token_hash`),
    KEY `idx_ehr_password_resets_user_id` (`user_id`),
    KEY `idx_ehr_password_resets_expires_at` (`expires_at`),
    CONSTRAINT `fk_ehr_password_resets_user_id` FOREIGN KEY (`user_id`) REFERENCES `ehr_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
