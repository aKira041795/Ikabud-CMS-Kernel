-- 015_create_dc_vouchers.sql
-- Voucher/discount code system. Supports percentage and fixed-amount discounts.
-- Used at POS via validateVoucher() endpoint.
-- @mysql57-compat: InnoDB, utf8mb4.

CREATE TABLE IF NOT EXISTS `dc_vouchers` (
  `voucher_id` INT NOT NULL AUTO_INCREMENT,
  `code` VARCHAR(50) NOT NULL,
  `discount_type` ENUM('percentage','fixed') NOT NULL,
  `discount_value` DECIMAL(10,2) NOT NULL,
  `min_order_amount` DECIMAL(10,2) DEFAULT NULL,
  `max_uses` INT DEFAULT NULL,
  `used_count` INT NOT NULL DEFAULT 0,
  `valid_from` DATETIME NOT NULL,
  `valid_until` DATETIME NOT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`voucher_id`),
  UNIQUE KEY `uk_dc_vouchers_code` (`code`),
  KEY `idx_dc_vouchers_active` (`is_active`),
  KEY `idx_dc_vouchers_valid` (`valid_from`, `valid_until`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `dc_voucher_usages` (
  `usage_id` INT NOT NULL AUTO_INCREMENT,
  `voucher_id` INT NOT NULL,
  `order_id` INT NOT NULL,
  `discount_amount` DECIMAL(10,2) NOT NULL,
  `used_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`usage_id`),
  KEY `idx_dc_voucher_usages_voucher` (`voucher_id`),
  KEY `idx_dc_voucher_usages_order` (`order_id`),
  CONSTRAINT `fk_dc_vu_voucher` FOREIGN KEY (`voucher_id`) REFERENCES `dc_vouchers` (`voucher_id`),
  CONSTRAINT `fk_dc_vu_order` FOREIGN KEY (`order_id`) REFERENCES `dc_orders` (`order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
