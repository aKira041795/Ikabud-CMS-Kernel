-- ============================================================
-- Ecommerce Module — Core Schema
-- Tables: ec_carts, ec_cart_items, ec_orders, ec_order_items, ec_order_meta
-- This migration is idempotent and safe to re-run.
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;

-- ── Carts (guest: session_id; customer: user_id) ────────────────────

CREATE TABLE IF NOT EXISTS ec_carts (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_id   VARCHAR(128)          NULL DEFAULT NULL COMMENT 'Guest cart session key',
    user_id      INT UNSIGNED          NULL DEFAULT NULL COMMENT 'cms_users.id for logged-in customer',
    coupon_code  VARCHAR(50)           NULL DEFAULT NULL,
    coupon_discount DECIMAL(10,2)      NOT NULL DEFAULT 0.00,
    expires_at   DATETIME              NULL DEFAULT NULL,
    created_at   DATETIME              NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME              NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_ec_carts_session  (session_id),
    INDEX idx_ec_carts_user     (user_id),
    INDEX idx_ec_carts_expires  (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ec_cart_items (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cart_id         INT UNSIGNED NOT NULL,
    product_id      INT UNSIGNED NOT NULL COMMENT 'cms_content.id',
    variant_id      INT UNSIGNED NULL DEFAULT NULL COMMENT 'ec_product_variants.id',
    qty             SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    price_snapshot  DECIMAL(10,2) NOT NULL COMMENT 'Unit price locked at time of add',
    product_title   VARCHAR(500) NOT NULL DEFAULT '',
    sku             VARCHAR(100) NOT NULL DEFAULT '',
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_ec_cart_items_cart FOREIGN KEY (cart_id)
        REFERENCES ec_carts (id) ON DELETE CASCADE,
    INDEX idx_ec_cart_items_cart    (cart_id),
    INDEX idx_ec_cart_items_product (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Orders ──────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS ec_orders (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_number        VARCHAR(30) NOT NULL UNIQUE,
    customer_id         INT UNSIGNED NULL DEFAULT NULL COMMENT 'cms_users.id; NULL = guest',
    guest_email         VARCHAR(255) NULL DEFAULT NULL,
    guest_name          VARCHAR(255) NULL DEFAULT NULL,
    source              ENUM('web','pos','api') NOT NULL DEFAULT 'web',
    status              ENUM('pending','processing','shipped','delivered','cancelled','refunded') NOT NULL DEFAULT 'pending',
    payment_status      ENUM('pending','paid','failed','refunded') NOT NULL DEFAULT 'pending',
    subtotal            DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    discount_amount     DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    tax_amount          DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    shipping_amount     DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    total               DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    currency            VARCHAR(3)    NOT NULL DEFAULT 'USD',
    coupon_code         VARCHAR(50)   NULL DEFAULT NULL,
    notes               TEXT          NULL DEFAULT NULL COMMENT 'Admin notes',
    customer_note       TEXT          NULL DEFAULT NULL COMMENT 'Customer note at checkout',
    confirmation_token  CHAR(64)      NOT NULL UNIQUE COMMENT 'Guest order confirmation URL token',
    placed_by_user_id   INT UNSIGNED  NULL DEFAULT NULL COMMENT 'For POS: cashier user id',
    created_at          DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_ec_orders_customer    (customer_id),
    INDEX idx_ec_orders_status      (status),
    INDEX idx_ec_orders_payment     (payment_status),
    INDEX idx_ec_orders_source      (source),
    INDEX idx_ec_orders_created     (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ec_order_items (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id        INT UNSIGNED NOT NULL,
    product_id      INT UNSIGNED NOT NULL COMMENT 'cms_content.id snapshot reference',
    variant_id      INT UNSIGNED NULL DEFAULT NULL,
    product_title   VARCHAR(500) NOT NULL DEFAULT '' COMMENT 'Snapshot at order time',
    sku             VARCHAR(100) NOT NULL DEFAULT '' COMMENT 'Snapshot at order time',
    unit_price      DECIMAL(10,2) NOT NULL COMMENT 'Price at time of order',
    qty             SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    line_total      DECIMAL(10,2) NOT NULL COMMENT 'unit_price * qty',
    variant_label   VARCHAR(255) NULL DEFAULT NULL COMMENT 'e.g. Color: Red, Size: L',
    CONSTRAINT fk_ec_order_items_order FOREIGN KEY (order_id)
        REFERENCES ec_orders (id) ON DELETE CASCADE,
    INDEX idx_ec_order_items_order   (order_id),
    INDEX idx_ec_order_items_product (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ec_order_meta (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id        INT UNSIGNED NOT NULL,
    meta_key        VARCHAR(100) NOT NULL,
    meta_value      TEXT NULL DEFAULT NULL,
    UNIQUE KEY uniq_ec_order_meta (order_id, meta_key),
    CONSTRAINT fk_ec_order_meta_order FOREIGN KEY (order_id)
        REFERENCES ec_orders (id) ON DELETE CASCADE,
    INDEX idx_ec_order_meta_order (order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
