-- 016_create_dc_inventory_progress.sql
-- Per-session inventory progress save/resume.
-- Cashiers can save partial inventory counts during session and resume later.
-- Mirrors legacy saveInventoryProgress()/getInventoryData() functionality.
-- @mysql57-compat: InnoDB, utf8mb4.

CREATE TABLE IF NOT EXISTS `dc_inventory_progress` (
  `progress_id` INT NOT NULL AUTO_INCREMENT,
  `session_id` INT NOT NULL,
  `product_id` INT NOT NULL,
  `beginning_qty` DECIMAL(10,2) DEFAULT 0.00,
  `production_qty` DECIMAL(10,2) DEFAULT 0.00,
  `pullout_qty` DECIMAL(10,2) DEFAULT 0.00,
  `ending_qty` DECIMAL(10,2) DEFAULT 0.00,
  `sold_qty` DECIMAL(10,2) DEFAULT 0.00,
  `notes` TEXT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`progress_id`),
  UNIQUE KEY `uk_dc_inv_progress_session_product` (`session_id`, `product_id`),
  KEY `idx_dc_inv_progress_session` (`session_id`),
  CONSTRAINT `fk_dc_inv_progress_session` FOREIGN KEY (`session_id`) REFERENCES `dc_sessions` (`session_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
