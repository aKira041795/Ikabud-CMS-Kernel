-- 001_create_dc_users.sql
-- Module-owned users table per daily-ledger auth_owned pattern.
-- @mysql57-compat: InnoDB, utf8mb4, no window functions.

CREATE TABLE IF NOT EXISTS `dc_users` (
  `user_id` INT NOT NULL AUTO_INCREMENT,
  `username` VARCHAR(100) NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `email` VARCHAR(255) DEFAULT NULL,
  `full_name` VARCHAR(255) NOT NULL,
  `role` ENUM('admin','cashier') NOT NULL DEFAULT 'cashier',
  `store_id` INT DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `deleted_at` DATETIME DEFAULT NULL,
  `last_login_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`),
  UNIQUE KEY `uk_dc_users_username` (`username`),
  UNIQUE KEY `uk_dc_users_email` (`email`),
  KEY `idx_dc_users_store` (`store_id`),
  KEY `idx_dc_users_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
