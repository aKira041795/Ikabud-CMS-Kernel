-- 007_create_dc_orders.sql
-- Single orders table replaces legacy dcmain_transactions + dcmain_sales split.
-- Each order has one session, one cashier, one payment, many items.
-- Includes store_id and cashier_id for multi-store reporting.
-- original_amount preserves pre-discount total for audit trail.
-- @mysql57-compat: InnoDB, utf8mb4.
-- FK_CHECKS=0: dc_customers referenced here is created in migration 009.
-- The FK constraint is validated at migration end when all tables exist.

SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS `dc_orders` (
  `order_id` INT NOT NULL AUTO_INCREMENT,
  `session_id` INT NOT NULL,
  `store_id` INT NOT NULL,
  `cashier_id` INT NOT NULL,
  `customer_id` INT DEFAULT NULL,
  `total_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `original_amount` DECIMAL(10,2) DEFAULT NULL,
  `discount_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `discount_reason` VARCHAR(255) DEFAULT NULL,
  `payment_method_id` INT NOT NULL,
  `amount_tendered` DECIMAL(10,2) DEFAULT NULL,
  `change_amount` DECIMAL(10,2) DEFAULT NULL,
  `transaction_date` DATETIME NOT NULL,
  `status` ENUM('draft','completed','voided') NOT NULL DEFAULT 'draft',
  `reference_id` VARCHAR(50) DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`order_id`),
  KEY `idx_dc_orders_session` (`session_id`),
  KEY `idx_dc_orders_store` (`store_id`),
  KEY `idx_dc_orders_cashier` (`cashier_id`),
  KEY `idx_dc_orders_customer` (`customer_id`),
  KEY `idx_dc_orders_status` (`status`),
  KEY `idx_dc_orders_date` (`transaction_date`),
  KEY `idx_dc_orders_payment` (`payment_method_id`),
  UNIQUE KEY `uk_dc_orders_reference` (`reference_id`),
  CONSTRAINT `fk_dc_orders_session` FOREIGN KEY (`session_id`) REFERENCES `dc_sessions` (`session_id`),
  CONSTRAINT `fk_dc_orders_store` FOREIGN KEY (`store_id`) REFERENCES `dc_stores` (`store_id`),
  CONSTRAINT `fk_dc_orders_cashier` FOREIGN KEY (`cashier_id`) REFERENCES `dc_users` (`user_id`),
  CONSTRAINT `fk_dc_orders_customer` FOREIGN KEY (`customer_id`) REFERENCES `dc_customers` (`customer_id`),
  CONSTRAINT `fk_dc_orders_payment` FOREIGN KEY (`payment_method_id`) REFERENCES `dc_payment_methods` (`payment_method_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
