CREATE TABLE IF NOT EXISTS `bakeshop_branches` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `code` VARCHAR(50) NOT NULL,
    `name` VARCHAR(255) NOT NULL,
    `address` VARCHAR(255) NULL,
    `external_store_id` INT UNSIGNED NULL,
    `external_warehouse_id` INT UNSIGNED NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_bakeshop_branches_code` (`code`),
    KEY `idx_bakeshop_branches_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `bakeshop_units` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `code` VARCHAR(20) NOT NULL,
    `name` VARCHAR(100) NOT NULL,
    `dimension` ENUM('mass', 'volume', 'count') NOT NULL,
    `base_unit_id` INT UNSIGNED NULL,
    `factor_to_base` DECIMAL(14,6) NOT NULL DEFAULT 1.000000,
    `sort_order` INT UNSIGNED NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_bakeshop_units_code` (`code`),
    KEY `idx_bakeshop_units_dimension` (`dimension`),
    CONSTRAINT `fk_bakeshop_units_base_unit` FOREIGN KEY (`base_unit_id`) REFERENCES `bakeshop_units` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `bakeshop_ingredients` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `sku` VARCHAR(100) NULL,
    `name` VARCHAR(255) NOT NULL,
    `default_unit_id` INT UNSIGNED NOT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_bakeshop_ingredients_sku` (`sku`),
    KEY `idx_bakeshop_ingredients_name` (`name`),
    CONSTRAINT `fk_bakeshop_ingredients_default_unit` FOREIGN KEY (`default_unit_id`) REFERENCES `bakeshop_units` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `bakeshop_products` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `sku` VARCHAR(100) NULL,
    `name` VARCHAR(255) NOT NULL,
    `category` VARCHAR(100) NULL,
    `default_yield_qty` DECIMAL(14,4) NOT NULL DEFAULT 1.0000,
    `default_yield_unit_id` INT UNSIGNED NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_bakeshop_products_sku` (`sku`),
    KEY `idx_bakeshop_products_name` (`name`),
    CONSTRAINT `fk_bakeshop_products_default_yield_unit` FOREIGN KEY (`default_yield_unit_id`) REFERENCES `bakeshop_units` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `bakeshop_product_recipe` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `product_id` INT UNSIGNED NOT NULL,
    `ingredient_id` INT UNSIGNED NOT NULL,
    `qty` DECIMAL(14,4) NOT NULL,
    `unit_id` INT UNSIGNED NOT NULL,
    `notes` VARCHAR(255) NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_bakeshop_product_recipe` (`product_id`, `ingredient_id`, `unit_id`),
    KEY `idx_bakeshop_recipe_product` (`product_id`),
    KEY `idx_bakeshop_recipe_ingredient` (`ingredient_id`),
    CONSTRAINT `fk_bakeshop_recipe_product` FOREIGN KEY (`product_id`) REFERENCES `bakeshop_products` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_bakeshop_recipe_ingredient` FOREIGN KEY (`ingredient_id`) REFERENCES `bakeshop_ingredients` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_bakeshop_recipe_unit` FOREIGN KEY (`unit_id`) REFERENCES `bakeshop_units` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `bakeshop_deliveries` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `branch_id` INT UNSIGNED NOT NULL,
    `delivered_at` DATETIME NOT NULL,
    `reference` VARCHAR(100) NULL,
    `received_by` VARCHAR(255) NULL,
    `notes` TEXT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_bakeshop_deliveries_branch_date` (`branch_id`, `delivered_at`),
    CONSTRAINT `fk_bakeshop_deliveries_branch` FOREIGN KEY (`branch_id`) REFERENCES `bakeshop_branches` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `bakeshop_delivery_items` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `delivery_id` INT UNSIGNED NOT NULL,
    `ingredient_id` INT UNSIGNED NOT NULL,
    `qty` DECIMAL(14,4) NOT NULL,
    `unit_id` INT UNSIGNED NOT NULL,
    `unit_cost` DECIMAL(14,4) NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_bakeshop_delivery_items_delivery` (`delivery_id`),
    KEY `idx_bakeshop_delivery_items_ingredient` (`ingredient_id`),
    CONSTRAINT `fk_bakeshop_delivery_items_delivery` FOREIGN KEY (`delivery_id`) REFERENCES `bakeshop_deliveries` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_bakeshop_delivery_items_ingredient` FOREIGN KEY (`ingredient_id`) REFERENCES `bakeshop_ingredients` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_bakeshop_delivery_items_unit` FOREIGN KEY (`unit_id`) REFERENCES `bakeshop_units` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `bakeshop_production_runs` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `branch_id` INT UNSIGNED NOT NULL,
    `product_id` INT UNSIGNED NOT NULL,
    `produced_at` DATETIME NOT NULL,
    `qty_produced` DECIMAL(14,4) NOT NULL,
    `produced_by` VARCHAR(255) NULL,
    `notes` TEXT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_bakeshop_production_runs_branch_date` (`branch_id`, `produced_at`),
    KEY `idx_bakeshop_production_runs_product` (`product_id`),
    CONSTRAINT `fk_bakeshop_production_runs_branch` FOREIGN KEY (`branch_id`) REFERENCES `bakeshop_branches` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_bakeshop_production_runs_product` FOREIGN KEY (`product_id`) REFERENCES `bakeshop_products` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `bakeshop_production_items` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `run_id` INT UNSIGNED NOT NULL,
    `ingredient_id` INT UNSIGNED NOT NULL,
    `qty_used` DECIMAL(14,4) NOT NULL,
    `unit_id` INT UNSIGNED NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_bakeshop_production_items_run` (`run_id`),
    KEY `idx_bakeshop_production_items_ingredient` (`ingredient_id`),
    CONSTRAINT `fk_bakeshop_production_items_run` FOREIGN KEY (`run_id`) REFERENCES `bakeshop_production_runs` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_bakeshop_production_items_ingredient` FOREIGN KEY (`ingredient_id`) REFERENCES `bakeshop_ingredients` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_bakeshop_production_items_unit` FOREIGN KEY (`unit_id`) REFERENCES `bakeshop_units` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `bakeshop_units` (`code`, `name`, `dimension`, `base_unit_id`, `factor_to_base`, `sort_order`)
SELECT * FROM (
    SELECT 'kg', 'Kilogram', 'mass', NULL, 1.000000, 10
    UNION ALL SELECT 'g', 'Gram', 'mass', 1, 0.001000, 20
    UNION ALL SELECT 'L', 'Liter', 'volume', NULL, 1.000000, 30
    UNION ALL SELECT 'mL', 'Milliliter', 'volume', 3, 0.001000, 40
    UNION ALL SELECT 'pc', 'Piece', 'count', NULL, 1.000000, 50
    UNION ALL SELECT 'pack', 'Pack', 'count', 5, 1.000000, 60
) AS seed (`code`, `name`, `dimension`, `base_unit_id`, `factor_to_base`, `sort_order`)
WHERE NOT EXISTS (
    SELECT 1 FROM `bakeshop_units` existing WHERE existing.`code` = seed.`code`
);

CREATE OR REPLACE VIEW `bakeshop_ingredient_usage` AS
SELECT
    branch_rows.`branch_id` AS `branch_id`,
    branches.`name` AS `branch_name`,
    branch_rows.`ingredient_id` AS `ingredient_id`,
    ingredients.`name` AS `ingredient_name`,
    branch_rows.`dimension` AS `dimension`,
    branch_rows.`period_date` AS `period_date`,
    SUM(branch_rows.`delivered_qty_base`) AS `delivered_qty_base`,
    SUM(branch_rows.`consumed_qty_base`) AS `consumed_qty_base`,
    SUM(branch_rows.`delivered_qty_base`) - SUM(branch_rows.`consumed_qty_base`) AS `variance_qty_base`
FROM (
    SELECT
        d.`branch_id` AS `branch_id`,
        di.`ingredient_id` AS `ingredient_id`,
        DATE(d.`delivered_at`) AS `period_date`,
        u.`dimension` AS `dimension`,
        di.`qty` * u.`factor_to_base` AS `delivered_qty_base`,
        0.0000 AS `consumed_qty_base`
    FROM `bakeshop_deliveries` d
    INNER JOIN `bakeshop_delivery_items` di ON di.`delivery_id` = d.`id`
    INNER JOIN `bakeshop_units` u ON u.`id` = di.`unit_id`

    UNION ALL

    SELECT
        pr.`branch_id` AS `branch_id`,
        pi.`ingredient_id` AS `ingredient_id`,
        DATE(pr.`produced_at`) AS `period_date`,
        u.`dimension` AS `dimension`,
        0.0000 AS `delivered_qty_base`,
        pi.`qty_used` * u.`factor_to_base` AS `consumed_qty_base`
    FROM `bakeshop_production_runs` pr
    INNER JOIN `bakeshop_production_items` pi ON pi.`run_id` = pr.`id`
    INNER JOIN `bakeshop_units` u ON u.`id` = pi.`unit_id`
) AS branch_rows
INNER JOIN `bakeshop_branches` branches ON branches.`id` = branch_rows.`branch_id`
INNER JOIN `bakeshop_ingredients` ingredients ON ingredients.`id` = branch_rows.`ingredient_id`
GROUP BY
    branch_rows.`branch_id`,
    branches.`name`,
    branch_rows.`ingredient_id`,
    ingredients.`name`,
    branch_rows.`dimension`,
    branch_rows.`period_date`;