-- ============================================================
-- Ecommerce Module — Coupons
-- Table: ec_coupons
-- This migration is idempotent and safe to re-run.
-- ============================================================

CREATE TABLE IF NOT EXISTS ec_coupons (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code                VARCHAR(50)  NOT NULL UNIQUE,
    description         VARCHAR(255) NULL DEFAULT NULL,
    type                ENUM('percent','fixed') NOT NULL DEFAULT 'percent',
    value               DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Percent (0-100) or fixed amount',
    min_order_amount    DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Minimum cart subtotal required',
    max_uses            INT UNSIGNED  NULL DEFAULT NULL COMMENT 'NULL = unlimited',
    uses_count          INT UNSIGNED  NOT NULL DEFAULT 0,
    is_active           TINYINT(1)   NOT NULL DEFAULT 1,
    expires_at          DATETIME     NULL DEFAULT NULL,
    created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_ec_coupons_active  (is_active),
    INDEX idx_ec_coupons_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
