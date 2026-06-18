-- Migration 015: Password reset tokens for attendance-wage
CREATE TABLE IF NOT EXISTS `attendance_wage_password_resets` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL,
    `token_hash` CHAR(64) NOT NULL,
    `requester_ip` VARCHAR(45) DEFAULT NULL,
    `expires_at` DATETIME NOT NULL,
    `used_at` DATETIME DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_aw_password_resets_token_hash` (`token_hash`),
    KEY `idx_aw_password_resets_user_id` (`user_id`),
    KEY `idx_aw_password_resets_expires_at` (`expires_at`),
    CONSTRAINT `fk_aw_password_resets_user`
        FOREIGN KEY (`user_id`) REFERENCES `attendance_wage_users`(`id`)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
