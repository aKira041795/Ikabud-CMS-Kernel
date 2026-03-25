-- ============================================================
-- Ecommerce Module — Product Variants and Wishlist
-- Tables: ec_product_variants, ec_wishlist
-- This migration is idempotent and safe to re-run.
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS ec_product_variants (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id        INT UNSIGNED NOT NULL COMMENT 'cms_content.id',
    sku               VARCHAR(100) NULL DEFAULT NULL,
    attributes_json   JSON         NOT NULL COMMENT 'e.g. {"color":"Red","size":"L"}',
    label             VARCHAR(255) NULL DEFAULT NULL COMMENT 'Auto-generated display label',
    price_override    DECIMAL(10,2) NULL DEFAULT NULL COMMENT 'NULL = inherit parent price',
    stock_qty         INT          NOT NULL DEFAULT 0,
    is_active         TINYINT(1)  NOT NULL DEFAULT 1,
    sort_order        INT         NOT NULL DEFAULT 0,
    created_at        DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_ec_variants_product (product_id),
    INDEX idx_ec_variants_active  (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ec_wishlist (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED NOT NULL COMMENT 'cms_users.id',
    product_id  INT UNSIGNED NOT NULL COMMENT 'cms_content.id',
    added_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_ec_wishlist (user_id, product_id),
    INDEX idx_ec_wishlist_user    (user_id),
    INDEX idx_ec_wishlist_product (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
