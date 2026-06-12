-- Add reorder planning fields to ingredients
ALTER TABLE `bakeshop_ingredients`
    ADD COLUMN `par_level` DECIMAL(14,4) NULL AFTER `pack_unit_id`,
    ADD COLUMN `par_level_unit_id` INT UNSIGNED NULL AFTER `par_level`,
    ADD CONSTRAINT `fk_bakeshop_ingredients_par_level_unit` FOREIGN KEY (`par_level_unit_id`) REFERENCES `bakeshop_units` (`id`) ON DELETE SET NULL;

-- Inventory adjustments (waste, spoilage, stocktake, transfers)
CREATE TABLE IF NOT EXISTS `bakeshop_inventory_adjustments` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `branch_id` INT UNSIGNED NOT NULL,
    `ingredient_id` INT UNSIGNED NOT NULL,
    `adjustment_date` DATETIME NOT NULL,
    `qty` DECIMAL(14,4) NOT NULL,
    `unit_id` INT UNSIGNED NOT NULL,
    `adjustment_type` ENUM('waste','spoilage','stocktake','transfer_in','transfer_out','other') NOT NULL DEFAULT 'other',
    `reference` VARCHAR(100) NULL,
    `notes` TEXT NULL,
    `created_by` VARCHAR(255) NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_bakeshop_inventory_adj_branch_date` (`branch_id`, `adjustment_date`),
    KEY `idx_bakeshop_inventory_adj_ingredient` (`ingredient_id`),
    KEY `idx_bakeshop_inventory_adj_type` (`adjustment_type`),
    CONSTRAINT `fk_bakeshop_inventory_adj_branch` FOREIGN KEY (`branch_id`) REFERENCES `bakeshop_branches` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_bakeshop_inventory_adj_ingredient` FOREIGN KEY (`ingredient_id`) REFERENCES `bakeshop_ingredients` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_bakeshop_inventory_adj_unit` FOREIGN KEY (`unit_id`) REFERENCES `bakeshop_units` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Snapshot unit cost on production items for cost-of-usage tracking
ALTER TABLE `bakeshop_production_items`
    ADD COLUMN `unit_cost` DECIMAL(14,4) NULL AFTER `unit_id`;

-- Rebuild the ingredient usage view to include adjustments
DROP VIEW IF EXISTS `bakeshop_ingredient_usage`;

CREATE VIEW `bakeshop_ingredient_usage` AS
SELECT
    branch_rows.`branch_id` AS `branch_id`,
    branches.`name` AS `branch_name`,
    branch_rows.`ingredient_id` AS `ingredient_id`,
    ingredients.`name` AS `ingredient_name`,
    branch_rows.`dimension` AS `dimension`,
    branch_rows.`period_date` AS `period_date`,
    SUM(branch_rows.`delivered_qty_base`) AS `delivered_qty_base`,
    SUM(branch_rows.`consumed_qty_base`) AS `consumed_qty_base`,
    SUM(branch_rows.`adjusted_qty_base`) AS `adjusted_qty_base`,
    SUM(branch_rows.`delivered_qty_base`) - SUM(branch_rows.`consumed_qty_base`) + SUM(branch_rows.`adjusted_qty_base`) AS `variance_qty_base`
FROM (
    -- Deliveries (inflow)
    SELECT
        d.`branch_id`,
        di.`ingredient_id`,
        DATE(d.`delivered_at`) AS `period_date`,
        u.`dimension` AS `dimension`,
        di.`qty` * u.`factor_to_base` AS `delivered_qty_base`,
        0.0000 AS `consumed_qty_base`,
        0.0000 AS `adjusted_qty_base`
    FROM `bakeshop_deliveries` d
    INNER JOIN `bakeshop_delivery_items` di ON di.`delivery_id` = d.`id`
    INNER JOIN `bakeshop_units` u ON u.`id` = di.`unit_id`

    UNION ALL

    -- Production consumption (outflow)
    SELECT
        pr.`branch_id`,
        pi.`ingredient_id`,
        DATE(pr.`produced_at`) AS `period_date`,
        u.`dimension` AS `dimension`,
        0.0000 AS `delivered_qty_base`,
        pi.`qty_used` * u.`factor_to_base` AS `consumed_qty_base`,
        0.0000 AS `adjusted_qty_base`
    FROM `bakeshop_production_runs` pr
    INNER JOIN `bakeshop_production_items` pi ON pi.`run_id` = pr.`id`
    INNER JOIN `bakeshop_units` u ON u.`id` = pi.`unit_id`
    WHERE pr.`voided_at` IS NULL

    UNION ALL

    -- Inventory adjustments (inflow / outflow)
    SELECT
        a.`branch_id`,
        a.`ingredient_id`,
        DATE(a.`adjustment_date`) AS `period_date`,
        u.`dimension` AS `dimension`,
        0.0000 AS `delivered_qty_base`,
        0.0000 AS `consumed_qty_base`,
        a.`qty` * u.`factor_to_base` AS `adjusted_qty_base`
    FROM `bakeshop_inventory_adjustments` a
    INNER JOIN `bakeshop_units` u ON u.`id` = a.`unit_id`
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
