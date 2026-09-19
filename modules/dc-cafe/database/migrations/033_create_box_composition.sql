-- 033_create_box_composition.sql
-- Variable "box" products: a box is priced as a whole, and consumes N component
-- products (doughnut flavours) from a price band.
--
-- Example: DC DELIGHTS COMBO (6pcs) costs PHP 255.00 regardless of which six
-- doughnuts go in it. The box itself is an offer, not a stocked SKU, so it must
-- NOT hold its own stock (`has_stock = 0`) or the components would be deducted
-- twice.
--
-- dc_products.slot_count > 0 marks a box. The PER-BOX price band restricts which
-- flavours may go in: a premium flavour cannot be placed in a cheap box, and a
-- cheap flavour cannot be placed in a premium box.
--
-- dc_product_components is the OPTIONAL standard set: the flavours normally used
-- for that box. When a standard flavour is out of stock the cashier swaps it for
-- another flavour inside the band. A box with no standard set falls back to free
-- cashier choice (still band- and category-filtered).
--
-- @mysql57-compat: ALTER/CREATE via information_schema guards, InnoDB, utf8mb4.

-- ── dc_products: box configuration ────────────────────────────────

SET @slot_count_exists := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'dc_products'
      AND column_name = 'slot_count'
);

SET @sql := IF(@slot_count_exists = 0,
    'ALTER TABLE `dc_products`
     ADD COLUMN `slot_count` TINYINT UNSIGNED DEFAULT NULL AFTER `is_variable`,
     ADD COLUMN `component_category_id` INT DEFAULT NULL AFTER `slot_count`,
     ADD COLUMN `component_min_price` DECIMAL(10,2) DEFAULT NULL AFTER `component_category_id`,
     ADD COLUMN `component_max_price` DECIMAL(10,2) DEFAULT NULL AFTER `component_min_price`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @cc_fk_exists := (
    SELECT COUNT(*)
    FROM information_schema.table_constraints
    WHERE table_schema = DATABASE()
      AND table_name = 'dc_products'
      AND constraint_name = 'fk_dc_products_component_category'
);

SET @sql2 := IF(@cc_fk_exists = 0,
    'ALTER TABLE `dc_products`
     ADD CONSTRAINT `fk_dc_products_component_category`
     FOREIGN KEY (`component_category_id`) REFERENCES `dc_categories` (`category_id`)',
    'SELECT 1'
);
PREPARE stmt2 FROM @sql2; EXECUTE stmt2; DEALLOCATE PREPARE stmt2;

-- ── dc_product_components: the standard set per box ───────────────

CREATE TABLE IF NOT EXISTS `dc_product_components` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `product_id` INT NOT NULL,
  `slot_no` TINYINT UNSIGNED NOT NULL,
  `component_product_id` INT NOT NULL,
  `quantity` DECIMAL(10,2) NOT NULL DEFAULT 1.00,
  `substitutable` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_dc_pc_slot` (`product_id`, `slot_no`),
  KEY `idx_dc_pc_component` (`component_product_id`),
  CONSTRAINT `fk_dc_pc_product` FOREIGN KEY (`product_id`) REFERENCES `dc_products` (`product_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_dc_pc_component` FOREIGN KEY (`component_product_id`) REFERENCES `dc_products` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
