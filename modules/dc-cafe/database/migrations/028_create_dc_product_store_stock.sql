-- 028_create_dc_product_store_stock.sql
-- Branch-owned finished-product stock table.
-- Decouples product catalog (dc_products) from location inventory.
-- Each (product_id, store_id) row tracks on-hand, reserved, and reorder level.
-- `version` column enables optimistic locking for guarded concurrent updates.
-- @mysql57-compat: InnoDB, utf8mb4.

CREATE TABLE IF NOT EXISTS `dc_product_store_stock` (
  `product_id` INT NOT NULL,
  `store_id` INT NOT NULL,
  `on_hand_qty` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `reserved_qty` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `reorder_level` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `version` INT NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`product_id`, `store_id`),
  KEY `idx_dc_pss_store` (`store_id`),
  KEY `idx_dc_pss_product` (`product_id`),
  CONSTRAINT `fk_dc_pss_product` FOREIGN KEY (`product_id`) REFERENCES `dc_products` (`product_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_dc_pss_store` FOREIGN KEY (`store_id`) REFERENCES `dc_stores` (`store_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Backfill: create branch stock rows for each (product.store_id, product.product_id)
-- using the existing shared current_stock as the initial on-hand quantity.
INSERT INTO `dc_product_store_stock` (`product_id`, `store_id`, `on_hand_qty`, `reorder_level`, `version`)
SELECT p.`product_id`, p.`store_id`, p.`current_stock`, p.`reorder_level`, 1
FROM `dc_products` p
WHERE p.`has_stock` = 1
ON DUPLICATE KEY UPDATE
  `on_hand_qty` = VALUES(`on_hand_qty`),
  `reorder_level` = VALUES(`reorder_level`),
  `version` = 1;
