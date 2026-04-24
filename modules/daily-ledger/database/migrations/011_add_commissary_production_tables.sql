-- 011_add_commissary_production_tables.sql
-- Adds tables to support the physical commissary production sheets.

CREATE TABLE IF NOT EXISTS `dl_raw_materials` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL,
    `unit_of_measure` VARCHAR(20) NOT NULL DEFAULT 'kg',
    `category` ENUM('flour', 'sugar', 'fats', 'liquids', 'dry_ingredients', 'packaging', 'other') NOT NULL DEFAULT 'other',
    `sort_order` INT NOT NULL DEFAULT 0,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `dl_commissary_ledger` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `ledger_date` DATE NOT NULL,
    `raw_material_id` INT UNSIGNED NOT NULL,
    `beg_bal` DECIMAL(10,3) NOT NULL DEFAULT 0.000,
    `delivery_qty` DECIMAL(10,3) NOT NULL DEFAULT 0.000,
    `used_qty` DECIMAL(10,3) NOT NULL DEFAULT 0.000,
    `actual_end_bal` DECIMAL(10,3) NOT NULL DEFAULT 0.000,
    `calc_variance` DECIMAL(10,3) GENERATED ALWAYS AS (actual_end_bal - (beg_bal + delivery_qty - used_qty)) STORED,
    `recorded_by` INT UNSIGNED NULL, -- Actor mapping
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `dl_cl_date_material` (`ledger_date`, `raw_material_id`),
    CONSTRAINT `fk_dl_cl_raw_material` FOREIGN KEY (`raw_material_id`) REFERENCES `dl_raw_materials` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `dl_production_runs` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `ledger_date` DATE NOT NULL,
    `product_id` INT UNSIGNED NOT NULL,
    `baker_name` VARCHAR(100) NOT NULL,
    `run_type` ENUM('regular', 'additional') NOT NULL DEFAULT 'regular',
    `primary_input_qty` DECIMAL(10,3) NOT NULL DEFAULT 0.000, -- The "Kilo" or "Egg"
    `primary_input_type` ENUM('kilo', 'egg') NOT NULL DEFAULT 'kilo',
    `yield_qty` INT NOT NULL DEFAULT 0,
    `destination_branch_id` INT UNSIGNED NULL,
    `recorded_by` INT UNSIGNED NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_dl_pr_product` FOREIGN KEY (`product_id`) REFERENCES `dl_products` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed initial basic raw materials from the photos
INSERT IGNORE INTO `dl_raw_materials` (`id`, `name`, `unit_of_measure`, `category`, `sort_order`) VALUES
(1, '1st Class Flour', 'kg', 'flour', 10),
(2, '3rd Class Flour', 'kg', 'flour', 20),
(3, 'Wheat Flour', 'kg', 'flour', 30),
(4, 'Cake Flour', 'kg', 'flour', 40),
(5, 'All Purpose Flour', 'kg', 'flour', 50),
(6, 'Refined Sugar', 'kg', 'sugar', 60),
(7, 'Brown Sugar', 'kg', 'sugar', 70),
(8, 'Lard', 'kg', 'fats', 80),
(9, 'Margarine', 'kg', 'fats', 90),
(10, 'Central', 'kg', 'fats', 100),
(11, 'Baker''s Best', 'kg', 'fats', 110),
(12, 'Yeast', 'kg', 'dry_ingredients', 120),
(13, 'Calumet', 'kg', 'dry_ingredients', 130),
(14, 'Dobrim', 'kg', 'dry_ingredients', 140),
(15, 'Polbos', 'kg', 'dry_ingredients', 150),
(16, 'Lecitex', 'kg', 'dry_ingredients', 160),
(17, 'Eggs', 'pcs', 'other', 170),
(18, 'Powdered Milk', 'kg', 'dry_ingredients', 180),
(19, 'Evap', 'cans', 'liquids', 190),
(20, 'Condensada', 'cans', 'liquids', 200),
(21, 'Corn Oil', 'kg', 'liquids', 210),
(22, 'Minola Oil', 'kg', 'liquids', 220),
(23, 'Cocoa', 'kg', 'dry_ingredients', 230),
(24, 'Cheese', 'kg', 'other', 240);
