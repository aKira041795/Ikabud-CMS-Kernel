-- 010_create_dc_ingredients.sql
-- Ingredient master for inventory tracking and recipe costing.
-- Triggered from the legacy dcmain_ingredients table (currently 0 rows — empty by design,
-- waiting for activation). This is the real implementation.
-- @mysql57-compat: InnoDB, utf8mb4.
-- FK_CHECKS=0: dc_suppliers referenced here is created in migration 013.

SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS `dc_ingredients` (
  `ingredient_id` INT NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(100) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `unit` VARCHAR(20) NOT NULL,
  `cost_per_unit` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `current_stock` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `reorder_level` DECIMAL(10,2) DEFAULT 0.00,
  `supplier_id` INT DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`ingredient_id`),
  KEY `idx_dc_ingredients_supplier` (`supplier_id`),
  KEY `idx_dc_ingredients_reorder` (`reorder_level`),
  CONSTRAINT `fk_dc_ingredients_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `dc_suppliers` (`supplier_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
