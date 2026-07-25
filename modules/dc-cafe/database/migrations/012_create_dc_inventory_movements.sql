-- 012_create_dc_inventory_movements.sql
-- Inventory movement audit trail. Every stock change is recorded.
-- movement_type: purchase (inbound from supplier), consumption (deducted by order),
--   adjustment (manual count correction), waste (spoiled/discarded).
-- reference_id links to source: order_id for consumption, supplier delivery for purchase.
-- @mysql57-compat: InnoDB, utf8mb4.

CREATE TABLE IF NOT EXISTS `dc_inventory_movements` (
  `movement_id` INT NOT NULL AUTO_INCREMENT,
  `ingredient_id` INT NOT NULL,
  `quantity_change` DECIMAL(10,3) NOT NULL,
  `movement_type` ENUM('purchase','consumption','adjustment','waste') NOT NULL,
  `reference_type` VARCHAR(50) DEFAULT NULL,
  `reference_id` INT DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `created_by` INT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`movement_id`),
  KEY `idx_dc_inv_mov_ingredient` (`ingredient_id`),
  KEY `idx_dc_inv_mov_type` (`movement_type`),
  KEY `idx_dc_inv_mov_reference` (`reference_type`, `reference_id`),
  CONSTRAINT `fk_dc_inv_mov_ingredient` FOREIGN KEY (`ingredient_id`) REFERENCES `dc_ingredients` (`ingredient_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
