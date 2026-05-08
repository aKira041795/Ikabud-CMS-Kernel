-- ============================================================
-- Daily Ledger Module — Price groups (Phase D)
--
-- Channel-specific pricing. Mall counters, kiosks, resellers, and
-- bazaar booths can charge different prices for the same product
-- without polluting the regular branch price.
--
-- A default seed row "Regular Branch Pricing" is inserted so that
-- existing branch flows have a target group when the new helper
-- dl_resolveProductPrice() is wired in.
--
-- dl_products.current_price is preserved as a fallback for code
-- that has not yet been migrated.
-- ============================================================

CREATE TABLE IF NOT EXISTS dl_price_groups (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    type ENUM('branch','mall','reseller','event','wholesale','kiosk','other') NOT NULL DEFAULT 'other',
    is_default TINYINT(1) NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_dl_pg_name (name),
    INDEX idx_dl_pg_active (is_active, is_default)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO dl_price_groups (name, type, is_default, is_active)
SELECT 'Regular Branch Pricing', 'branch', 1, 1
  FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM dl_price_groups WHERE is_default = 1);

CREATE TABLE IF NOT EXISTS dl_product_prices (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id INT UNSIGNED NOT NULL,
    price_group_id INT UNSIGNED NOT NULL,
    selling_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    effective_from DATE NOT NULL,
    effective_to DATE NULL DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by INT UNSIGNED NULL DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_dl_pp_window (product_id, price_group_id, effective_from),
    CONSTRAINT fk_dl_pp_product FOREIGN KEY (product_id) REFERENCES dl_products(id) ON DELETE CASCADE,
    CONSTRAINT fk_dl_pp_group FOREIGN KEY (price_group_id) REFERENCES dl_price_groups(id) ON DELETE CASCADE,
    INDEX idx_dl_pp_lookup (product_id, price_group_id, is_active, effective_from)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed default-group prices from current dl_products.current_price.
INSERT IGNORE INTO dl_product_prices
    (product_id, price_group_id, selling_price, effective_from, is_active)
SELECT p.id, pg.id, p.current_price, '1970-01-01', 1
  FROM dl_products p
 CROSS JOIN dl_price_groups pg
 WHERE pg.is_default = 1;
