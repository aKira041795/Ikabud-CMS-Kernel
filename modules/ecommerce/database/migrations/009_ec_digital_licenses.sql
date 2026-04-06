CREATE TABLE IF NOT EXISTS `ec_order_licenses` (
    `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    `order_id` bigint(20) unsigned NOT NULL,
    `order_item_id` bigint(20) unsigned NOT NULL,
    `customer_email` varchar(255) DEFAULT NULL,
    `target_module` varchar(100) NOT NULL,
    `target_tier` varchar(50) NOT NULL,
    `license_key` text NOT NULL,
    `status` varchar(50) NOT NULL DEFAULT 'active',
    `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `ec_ol_order_idx` (`order_id`),
    KEY `ec_ol_email_idx` (`customer_email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
