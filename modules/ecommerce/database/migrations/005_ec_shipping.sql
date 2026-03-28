-- ============================================================
-- Ecommerce Module — Shipping Zones and Rates
-- Tables: ec_shipping_zones, ec_shipping_rates
-- This migration is idempotent and safe to re-run.
-- ============================================================

CREATE TABLE IF NOT EXISTS ec_shipping_zones (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(100) NOT NULL,
    countries   JSON         NULL DEFAULT NULL COMMENT 'Array of ISO country codes; NULL = all',
    is_active   TINYINT(1)   NOT NULL DEFAULT 1,
    sort_order  INT          NOT NULL DEFAULT 0,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_ec_shipping_zones_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ec_shipping_rates (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    zone_id         INT UNSIGNED NOT NULL,
    label           VARCHAR(100) NOT NULL COMMENT 'e.g. Standard Shipping',
    carrier         VARCHAR(100) NULL DEFAULT NULL COMMENT 'e.g. FedEx, Local Courier',
    rate            DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT '0 = free',
    free_above      DECIMAL(10,2) NULL DEFAULT NULL COMMENT 'Min order for free shipping; NULL = never free',
    estimated_days  VARCHAR(50)   NULL DEFAULT NULL COMMENT 'e.g. 3-5 business days',
    is_active       TINYINT(1)   NOT NULL DEFAULT 1,
    sort_order      INT          NOT NULL DEFAULT 0,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_ec_shipping_rates_zone FOREIGN KEY (zone_id)
        REFERENCES ec_shipping_zones (id) ON DELETE CASCADE,
    INDEX idx_ec_shipping_rates_zone   (zone_id),
    INDEX idx_ec_shipping_rates_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed: one default "Everywhere" zone with a single flat-rate
INSERT IGNORE INTO ec_shipping_zones (id, name, countries, is_active, sort_order)
VALUES (1, 'Everywhere', NULL, 1, 0);

INSERT IGNORE INTO ec_shipping_rates (id, zone_id, label, rate, free_above, estimated_days, is_active, sort_order)
VALUES (1, 1, 'Standard Shipping', 0.00, NULL, NULL, 1, 0);
