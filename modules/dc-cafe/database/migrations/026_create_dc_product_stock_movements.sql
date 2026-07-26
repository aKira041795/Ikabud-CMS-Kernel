-- 026_create_dc_product_stock_movements.sql
-- Product stock movement journal.
-- Separate from dc_inventory_movements (which tracks ingredients).
-- Tracks every change to dc_products.current_stock: receive, sale, void, adjustment.
-- @mysql57-compat: InnoDB, utf8mb4.

CREATE TABLE IF NOT EXISTS `dc_product_stock_movements` (
  `movement_id` INT NOT NULL AUTO_INCREMENT,
  `product_id` INT NOT NULL,
  `quantity_change` DECIMAL(10,2) NOT NULL,
  `movement_type` ENUM('purchase','sale','adjustment','void_restore') NOT NULL,
  `reference_type` VARCHAR(50) DEFAULT NULL,
  `reference_id` INT DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `created_by` INT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`movement_id`),
  KEY `idx_dc_prod_stock_mov_product` (`product_id`),
  KEY `idx_dc_prod_stock_mov_type` (`movement_type`),
  KEY `idx_dc_prod_stock_mov_reference` (`reference_type`, `reference_id`),
  CONSTRAINT `fk_dc_prod_stock_mov_product` FOREIGN KEY (`product_id`) REFERENCES `dc_products` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
