CREATE TABLE IF NOT EXISTS `ehr_users` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `username` VARCHAR(100) NOT NULL,
    `email` VARCHAR(190) NOT NULL,
    `password_hash` VARCHAR(255) NOT NULL,
    `full_name` VARCHAR(150) NOT NULL DEFAULT '',
    `role` VARCHAR(50) NOT NULL DEFAULT 'admin',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `token_version` INT NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_ehr_users_username` (`username`),
    UNIQUE KEY `uq_ehr_users_email` (`email`),
    KEY `idx_ehr_users_role_active` (`role`, `is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `ehr_users` (`username`, `email`, `password_hash`, `full_name`, `role`, `is_active`, `token_version`, `created_at`, `updated_at`)
SELECT 'ehradmin', 'admin@ehr.local', '!ehr-bootstrap-password-reset-required!', 'EHR Admin', 'admin', 1, 0, NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM `ehr_users` WHERE `username` = 'ehradmin'
);
