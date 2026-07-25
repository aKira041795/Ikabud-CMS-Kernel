-- 005_create_dc_payment_methods.sql
-- Normalized payment methods — replaces ad-hoc string storage from legacy system.
-- @mysql57-compat: InnoDB, utf8mb4.

CREATE TABLE IF NOT EXISTS `dc_payment_methods` (
  `payment_method_id` INT NOT NULL AUTO_INCREMENT,
  `code` VARCHAR(20) NOT NULL,
  `name` VARCHAR(100) NOT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `sort_order` INT NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`payment_method_id`),
  UNIQUE KEY `uk_dc_payment_methods_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed standard payment methods. Matches legacy values for migration mapping.
INSERT INTO `dc_payment_methods` (`code`, `name`, `sort_order`) VALUES
('cash', 'Cash', 1),
('gcash', 'GCash', 2),
('card', 'Card', 3);
