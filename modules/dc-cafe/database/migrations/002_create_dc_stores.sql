-- 002_create_dc_stores.sql
-- DC Cafe store locations. Multi-store ready from day one.
-- @mysql57-compat: InnoDB, utf8mb4.

CREATE TABLE IF NOT EXISTS `dc_stores` (
  `store_id` INT NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(255) NOT NULL,
  `code` VARCHAR(20) NOT NULL,
  `address` TEXT DEFAULT NULL,
  `contact_number` VARCHAR(50) DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`store_id`),
  UNIQUE KEY `uk_dc_stores_code` (`code`),
  KEY `idx_dc_stores_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed DC Blu as the primary active store
INSERT INTO `dc_stores` (`name`, `code`, `address`) VALUES
('DC Blu', 'DC-BLU', 'DC Blu branch'),
('DC Main', 'DC-MAIN', 'DC Main branch'),
('DC City Mall', 'DC-CITY', 'DC City Mall outlet');
