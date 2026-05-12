CREATE TABLE IF NOT EXISTS `wms_password_resets` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL,
    `token_hash` CHAR(64) NOT NULL,
    `requester_ip` VARCHAR(45) NULL,
    `expires_at` DATETIME NOT NULL,
    `used_at` DATETIME NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_wms_password_resets_token_hash` (`token_hash`),
    KEY `idx_wms_password_resets_user_id` (`user_id`),
    KEY `idx_wms_password_resets_expires_at` (`expires_at`),
    CONSTRAINT `fk_wms_password_resets_user`
        FOREIGN KEY (`user_id`) REFERENCES `wms_users`(`id`)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;