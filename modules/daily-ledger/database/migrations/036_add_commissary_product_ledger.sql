-- Migration 036: Commissary finished-goods product ledger
-- Tracks per-commissary per-product per-day inventory of finished goods.
-- produced_qty  = what was baked (from production output)
-- dispatched_qty = what was sent to branches (via deliveries)
-- remaining_qty = produced_qty - dispatched_qty (computed column)

CREATE TABLE IF NOT EXISTS `dl_commissary_product_ledger` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `commissary_branch_id` INT UNSIGNED NOT NULL,
    `product_id` INT UNSIGNED NOT NULL,
    `ledger_date` DATE NOT NULL,
    `produced_qty` INT NOT NULL DEFAULT 0,
    `dispatched_qty` INT NOT NULL DEFAULT 0,
    `remaining_qty` INT GENERATED ALWAYS AS (produced_qty - dispatched_qty) STORED,
    `updated_by` INT UNSIGNED NULL,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `dl_cpl_date_product_commissary` (`commissary_branch_id`, `product_id`, `ledger_date`),
    CONSTRAINT `fk_dl_cpl_commissary_branch` FOREIGN KEY (`commissary_branch_id`) REFERENCES `dl_branches` (`id`),
    CONSTRAINT `fk_dl_cpl_product` FOREIGN KEY (`product_id`) REFERENCES `dl_products` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
