-- Phase 5: Production & Assembly Integration

-- 1. Recipes / BOM
CREATE TABLE IF NOT EXISTS `wms_recipes` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `product_id` INT UNSIGNED NOT NULL COMMENT 'The finished good',
    `name` VARCHAR(255) NOT NULL,
    `expected_yield` DECIMAL(14,4) NOT NULL DEFAULT 1.0000,
    `instructions` TEXT NULL DEFAULT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `deleted_at` DATETIME NULL DEFAULT NULL,
    INDEX `idx_wms_recipes_product` (`product_id`),
    CONSTRAINT `fk_wms_recipes_product` FOREIGN KEY (`product_id`) REFERENCES `wms_products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `wms_recipe_items` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `recipe_id` INT UNSIGNED NOT NULL,
    `material_product_id` INT UNSIGNED NOT NULL COMMENT 'The raw material',
    `qty_required` DECIMAL(14,4) NOT NULL,
    `is_substitutable` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_wms_recipe_items_recipe` (`recipe_id`),
    INDEX `idx_wms_recipe_items_material` (`material_product_id`),
    CONSTRAINT `fk_wms_recipe_items_recipe` FOREIGN KEY (`recipe_id`) REFERENCES `wms_recipes` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_wms_recipe_items_material` FOREIGN KEY (`material_product_id`) REFERENCES `wms_products` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Production Orders
CREATE TABLE IF NOT EXISTS `wms_production_orders` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `reference_no` VARCHAR(100) NOT NULL,
    `recipe_id` INT UNSIGNED NOT NULL,
    `warehouse_id` INT UNSIGNED NOT NULL,
    `status` ENUM('pending', 'picking', 'in_production', 'completed', 'cancelled') NOT NULL DEFAULT 'pending',
    `target_qty` DECIMAL(14,4) NOT NULL,
    `actual_yield` DECIMAL(14,4) NULL DEFAULT NULL,
    `started_at` DATETIME NULL DEFAULT NULL,
    `completed_at` DATETIME NULL DEFAULT NULL,
    `notes` TEXT NULL DEFAULT NULL,
    `actor_user_id` INT UNSIGNED NULL DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `deleted_at` DATETIME NULL DEFAULT NULL,
    UNIQUE KEY `uniq_wms_prod_order_ref` (`reference_no`),
    INDEX `idx_wms_prod_order_status` (`status`),
    INDEX `idx_wms_prod_order_warehouse` (`warehouse_id`),
    CONSTRAINT `fk_wms_prod_order_recipe` FOREIGN KEY (`recipe_id`) REFERENCES `wms_recipes` (`id`),
    CONSTRAINT `fk_wms_prod_order_warehouse` FOREIGN KEY (`warehouse_id`) REFERENCES `wms_warehouses` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `wms_production_materials` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `production_order_id` INT UNSIGNED NOT NULL,
    `material_product_id` INT UNSIGNED NOT NULL,
    `location_id` INT UNSIGNED NULL DEFAULT NULL COMMENT 'Where to pick from',
    `batch_id` INT UNSIGNED NULL DEFAULT NULL,
    `qty_required` DECIMAL(14,4) NOT NULL,
    `qty_consumed` DECIMAL(14,4) NOT NULL DEFAULT 0.0000,
    INDEX `idx_wms_prod_mat_order` (`production_order_id`),
    CONSTRAINT `fk_wms_prod_mat_order` FOREIGN KEY (`production_order_id`) REFERENCES `wms_production_orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
