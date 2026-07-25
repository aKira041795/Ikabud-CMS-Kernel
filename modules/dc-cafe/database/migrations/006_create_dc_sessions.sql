-- 006_create_dc_sessions.sql
-- Cashier shift sessions. Matches legacy dcmain_cashier_sessions structure.
-- Includes store_id for multi-store, is_late_report from legacy data.
-- @mysql57-compat: InnoDB, utf8mb4.

CREATE TABLE IF NOT EXISTS `dc_sessions` (
  `session_id` INT NOT NULL AUTO_INCREMENT,
  `user_id` INT NOT NULL,
  `store_id` INT NOT NULL,
  `starting_cash` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `ending_cash` DECIMAL(10,2) DEFAULT 0.00,
  `shift_type` ENUM('morning','afternoon','night') NOT NULL,
  `shift_start` DATETIME NOT NULL,
  `shift_end` DATETIME DEFAULT NULL,
  `status` ENUM('active','closed') NOT NULL DEFAULT 'active',
  `is_late_report` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`session_id`),
  KEY `idx_dc_sessions_user` (`user_id`),
  KEY `idx_dc_sessions_store` (`store_id`),
  KEY `idx_dc_sessions_status` (`status`),
  CONSTRAINT `fk_dc_sessions_user` FOREIGN KEY (`user_id`) REFERENCES `dc_users` (`user_id`),
  CONSTRAINT `fk_dc_sessions_store` FOREIGN KEY (`store_id`) REFERENCES `dc_stores` (`store_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
