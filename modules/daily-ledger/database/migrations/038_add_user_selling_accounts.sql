-- Migration 038: User-to-selling-account assignment
-- Allows cashiers to be assigned to selling accounts for dedicated ledger access.

CREATE TABLE IF NOT EXISTS `dl_user_selling_accounts` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED NOT NULL,
    `selling_account_id` INT UNSIGNED NOT NULL,
    UNIQUE KEY `uq_dl_usa_user_account` (`user_id`, `selling_account_id`),
    CONSTRAINT `fk_dl_usa_user` FOREIGN KEY (`user_id`) REFERENCES `dl_users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_dl_usa_account` FOREIGN KEY (`selling_account_id`) REFERENCES `dl_selling_accounts` (`id`) ON DELETE CASCADE,
    INDEX `idx_dl_usa_user` (`user_id`),
    INDEX `idx_dl_usa_account` (`selling_account_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
