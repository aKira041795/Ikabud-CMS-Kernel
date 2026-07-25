-- 008_create_dc_order_items.sql
-- Individual line items within an order. Links to products.
-- customizations JSON stores soft-serve config matching legacy format:
--   {"base":{"id":1,"name":"FROYO"},"baseWeight":"170","sauces":[{"id":131,"name":"CARAMEL"}],"toppings":[{"id":118,"name":"CARAMEL CRUMBLE"}],"addons":[{"id":5,"name":"Extra Spoon","price":5,"type":"spoon"}],"is_blu":true}
-- parent_item_id links addon line items to their parent product (e.g., extra spoon on a Cuddly).
-- @mysql57-compat: InnoDB, utf8mb4.

CREATE TABLE IF NOT EXISTS `dc_order_items` (
  `item_id` INT NOT NULL AUTO_INCREMENT,
  `order_id` INT NOT NULL,
  `product_id` INT NOT NULL,
  `quantity` INT NOT NULL DEFAULT 1,
  `unit_price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `total_price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `customizations` TEXT DEFAULT NULL,
  `notes` VARCHAR(500) DEFAULT NULL,
  `parent_item_id` INT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`item_id`),
  KEY `idx_dc_order_items_order` (`order_id`),
  KEY `idx_dc_order_items_product` (`product_id`),
  KEY `idx_dc_order_items_parent` (`parent_item_id`),
  CONSTRAINT `fk_dc_order_items_order` FOREIGN KEY (`order_id`) REFERENCES `dc_orders` (`order_id`),
  CONSTRAINT `fk_dc_order_items_product` FOREIGN KEY (`product_id`) REFERENCES `dc_products` (`product_id`),
  CONSTRAINT `fk_dc_order_items_parent` FOREIGN KEY (`parent_item_id`) REFERENCES `dc_order_items` (`item_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
