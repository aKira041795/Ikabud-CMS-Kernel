-- ============================================================
-- Ecommerce Module — Payment Transactions
-- Table: ec_payment_transactions
-- This migration is idempotent and safe to re-run.
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS ec_payment_transactions (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id        INT UNSIGNED NOT NULL,
    gateway         VARCHAR(50)  NOT NULL DEFAULT 'manual' COMMENT 'manual, stripe, paypal, etc.',
    gateway_txn_id  VARCHAR(255) NULL DEFAULT NULL COMMENT 'External transaction reference',
    amount          DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    currency        VARCHAR(3)    NOT NULL DEFAULT 'USD',
    status          ENUM('pending','processing','succeeded','failed','refunded') NOT NULL DEFAULT 'pending',
    gateway_response TEXT         NULL DEFAULT NULL COMMENT 'Raw gateway response JSON',
    notes           TEXT         NULL DEFAULT NULL,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_ec_payment_order FOREIGN KEY (order_id)
        REFERENCES ec_orders (id) ON DELETE CASCADE,
    INDEX idx_ec_payment_order  (order_id),
    INDEX idx_ec_payment_status (status),
    INDEX idx_ec_payment_gw_txn (gateway_txn_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
