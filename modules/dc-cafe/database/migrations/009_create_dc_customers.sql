-- 009_create_dc_customers.sql
-- Customer profiles with loyalty points. Phone is the primary lookup key.
-- member_tier enables differential pricing for VIP/repeat customers.
-- @mysql57-compat: InnoDB, utf8mb4.

CREATE TABLE IF NOT EXISTS `dc_customers` (
  `customer_id` INT NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(255) NOT NULL,
  `phone` VARCHAR(20) NOT NULL,
  `email` VARCHAR(255) DEFAULT NULL,
  `points_balance` INT NOT NULL DEFAULT 0,
  `total_points_earned` INT NOT NULL DEFAULT 0,
  `member_tier` ENUM('regular','vip') NOT NULL DEFAULT 'regular',
  `notes` TEXT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`customer_id`),
  UNIQUE KEY `uk_dc_customers_phone` (`phone`),
  KEY `idx_dc_customers_tier` (`member_tier`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
