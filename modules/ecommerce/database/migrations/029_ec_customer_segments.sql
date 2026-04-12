-- ============================================================
-- Ecommerce Module — Customer Segmentation & Tier Pricing
-- Creates ec_customer_segments, ec_customer_segment_members,
-- ec_segment_product_prices.
-- Safe to re-run (idempotent).
-- ============================================================

-- ── 1. Segment definitions ────────────────────────────────

CREATE TABLE IF NOT EXISTS ec_customer_segments (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code           VARCHAR(50)   NOT NULL                          COMMENT 'Machine-readable identifier, e.g. wholesale, vip, staff',
    name           VARCHAR(255)  NOT NULL,
    description    TEXT          NULL,
    discount_type  ENUM('percent','fixed','price_list') NOT NULL DEFAULT 'price_list'
                                                                  COMMENT 'percent = % off list; fixed = flat amount off; price_list = per-product rows in ec_segment_product_prices',
    discount_value DECIMAL(10,4) NULL DEFAULT NULL                COMMENT 'Percent (0-100) or fixed currency amount; null for price_list type',
    priority       INT           NOT NULL DEFAULT 0               COMMENT 'Higher = evaluated first when multiple segments apply',
    is_active      TINYINT(1)    NOT NULL DEFAULT 1,
    created_at     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_ec_cs_code (code),
    KEY idx_ec_cs_active_priority (is_active, priority)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 2. Segment membership ─────────────────────────────────

CREATE TABLE IF NOT EXISTS ec_customer_segment_members (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    segment_id INT UNSIGNED NOT NULL,
    user_id    INT UNSIGNED NOT NULL,
    added_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_ec_csm_seg_user (segment_id, user_id),
    KEY idx_ec_csm_user (user_id),
    CONSTRAINT fk_ec_csm_segment
        FOREIGN KEY (segment_id) REFERENCES ec_customer_segments (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 3. Per-segment product prices ─────────────────────────

CREATE TABLE IF NOT EXISTS ec_segment_product_prices (
    id         INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
    segment_id INT UNSIGNED  NOT NULL,
    product_id INT UNSIGNED  NOT NULL  COMMENT 'cms_content.id',
    price      DECIMAL(12,2) NOT NULL,
    sale_price DECIMAL(12,2) NULL DEFAULT NULL,
    created_at DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_ec_spp_seg_product (segment_id, product_id),
    KEY idx_ec_spp_product (product_id),
    CONSTRAINT fk_ec_spp_segment
        FOREIGN KEY (segment_id) REFERENCES ec_customer_segments (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
