CREATE TABLE IF NOT EXISTS `wms_batches` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `product_id` INT UNSIGNED NOT NULL,
    `batch_number` VARCHAR(100) NOT NULL,
    `lot_number` VARCHAR(100) NULL,
    `manufactured_at` DATE NULL,
    `expires_at` DATE NULL,
    `meta` JSON NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_wms_batches_product_batch` (`product_id`, `batch_number`),
    KEY `idx_wms_batches_expiry` (`expires_at`),
    CONSTRAINT `fk_wms_batches_product` FOREIGN KEY (`product_id`) REFERENCES `wms_products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
