-- 011_create_dc_product_ingredients.sql
-- Bill of Materials: which ingredients go into each product and in what quantity.
-- Enables automated stock deduction and COGS calculation on order completion.
-- @mysql57-compat: InnoDB, utf8mb4.

CREATE TABLE IF NOT EXISTS `dc_product_ingredients` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `product_id` INT NOT NULL,
  `ingredient_id` INT NOT NULL,
  `quantity` DECIMAL(10,3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_dc_product_ingredient` (`product_id`, `ingredient_id`),
  KEY `idx_dc_pi_ingredient` (`ingredient_id`),
  CONSTRAINT `fk_dc_pi_product` FOREIGN KEY (`product_id`) REFERENCES `dc_products` (`product_id`),
  CONSTRAINT `fk_dc_pi_ingredient` FOREIGN KEY (`ingredient_id`) REFERENCES `dc_ingredients` (`ingredient_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
