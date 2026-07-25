-- 003_create_dc_categories.sql
-- Product categories for organizing the menu.
-- @mysql57-compat: InnoDB, utf8mb4.

CREATE TABLE IF NOT EXISTS `dc_categories` (
  `category_id` INT NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(100) NOT NULL,
  `sort_order` INT NOT NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`category_id`),
  UNIQUE KEY `uk_dc_categories_name` (`name`),
  KEY `idx_dc_categories_sort` (`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed categories matching DC Blu menu structure
INSERT INTO `dc_categories` (`name`, `sort_order`) VALUES
('Soft Serve', 1),
('FroYo', 2),
('Beverages', 3),
('Pastries', 4),
('Add-ons', 5);
