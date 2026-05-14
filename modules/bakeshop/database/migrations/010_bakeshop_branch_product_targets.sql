CREATE TABLE IF NOT EXISTS `bakeshop_branch_product_targets` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `branch_id` INT UNSIGNED NOT NULL,
    `product_id` INT UNSIGNED NOT NULL,
    `daily_qty` DECIMAL(14,4) NOT NULL,
    `unit_id` INT UNSIGNED NOT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_bakeshop_branch_product_targets_branch_product` (`branch_id`, `product_id`),
    KEY `idx_bakeshop_branch_product_targets_branch` (`branch_id`),
    KEY `idx_bakeshop_branch_product_targets_product` (`product_id`),
    KEY `idx_bakeshop_branch_product_targets_active` (`is_active`),
    CONSTRAINT `fk_bakeshop_branch_product_targets_branch` FOREIGN KEY (`branch_id`) REFERENCES `bakeshop_branches` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_bakeshop_branch_product_targets_product` FOREIGN KEY (`product_id`) REFERENCES `bakeshop_products` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_bakeshop_branch_product_targets_unit` FOREIGN KEY (`unit_id`) REFERENCES `bakeshop_units` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
