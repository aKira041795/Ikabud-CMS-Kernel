-- Migration 013: Attendance & Wage — module-owned users table (entry module auth)
CREATE TABLE IF NOT EXISTS `attendance_wage_users` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `username`      VARCHAR(100) NOT NULL,
    `email`         VARCHAR(255) NOT NULL,
    `phone`         VARCHAR(50) DEFAULT NULL,
    `password_hash` VARCHAR(255) NOT NULL,
    `full_name`     VARCHAR(255) NOT NULL,
    `role`          ENUM('admin','supervisor','employee') NOT NULL DEFAULT 'employee',
    `is_active`     TINYINT(1) NOT NULL DEFAULT 1,
    `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_aw_users_username` (`username`),
    UNIQUE KEY `uq_aw_users_email` (`email`),
    KEY `idx_aw_users_role_active` (`role`, `is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
