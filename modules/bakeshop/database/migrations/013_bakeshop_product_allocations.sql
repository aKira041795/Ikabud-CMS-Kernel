-- Commissary product-level allocation tracking
-- Records how many production-days worth of pre-packed ingredients
-- were delivered per product per branch, independent of ingredient SKUs.

CREATE TABLE IF NOT EXISTS `bakeshop_product_allocations` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `branch_id` INT UNSIGNED NOT NULL,
    `product_id` INT UNSIGNED NOT NULL,
    `allocated_date` DATE NOT NULL,
    `days_worth` DECIMAL(14,4) NOT NULL DEFAULT 1.0000,
    `notes` TEXT NULL,
    `created_by` VARCHAR(255) NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_bakeshop_product_alloc_branch_date` (`branch_id`, `allocated_date`),
    KEY `idx_bakeshop_product_alloc_product` (`product_id`),
    KEY `idx_bakeshop_product_alloc_branch_prod_date` (`branch_id`, `product_id`, `allocated_date`),
    CONSTRAINT `fk_bakeshop_product_alloc_branch` FOREIGN KEY (`branch_id`) REFERENCES `bakeshop_branches` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_bakeshop_product_alloc_product` FOREIGN KEY (`product_id`) REFERENCES `bakeshop_products` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
