-- 004_create_dc_products.sql
-- DC Cafe product catalog. Products belong to a category and store.
-- `is_variable` flags soft-serve items with customizable base/sauce/toppings.
-- @mysql57-compat: InnoDB, utf8mb4.

CREATE TABLE IF NOT EXISTS `dc_products` (
  `product_id` INT NOT NULL AUTO_INCREMENT,
  `store_id` INT NOT NULL,
  `category_id` INT NOT NULL,
  `name` VARCHAR(255) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `base_price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `is_variable` TINYINT(1) NOT NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`product_id`),
  KEY `idx_dc_products_store` (`store_id`),
  KEY `idx_dc_products_category` (`category_id`),
  KEY `idx_dc_products_active` (`is_active`),
  CONSTRAINT `fk_dc_products_store` FOREIGN KEY (`store_id`) REFERENCES `dc_stores` (`store_id`),
  CONSTRAINT `fk_dc_products_category` FOREIGN KEY (`category_id`) REFERENCES `dc_categories` (`category_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
