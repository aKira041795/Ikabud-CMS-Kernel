CREATE TABLE IF NOT EXISTS `wms_users` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `username` VARCHAR(100) NOT NULL,
    `email` VARCHAR(255) NOT NULL,
    `password_hash` VARCHAR(255) NOT NULL,
    `full_name` VARCHAR(255) NOT NULL,
    `role` ENUM('admin', 'supervisor', 'viewer') NOT NULL DEFAULT 'viewer',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_wms_users_username` (`username`),
    UNIQUE KEY `uq_wms_users_email` (`email`),
    KEY `idx_wms_users_role_active` (`role`, `is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `wms_users` (`username`, `email`, `password_hash`, `full_name`, `role`, `is_active`)
VALUES ('wmsadmin', 'admin@wms.local', '$2y$10$MpYxDIlYvs1xuzfEDFxxyuxMgyMtotMy8zfak9eDa2EVa..IBNTuW', 'WMS Admin', 'admin', 1)
ON DUPLICATE KEY UPDATE `updated_at` = NOW();
