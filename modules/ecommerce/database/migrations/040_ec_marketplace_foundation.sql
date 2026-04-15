-- Tier 4.1: Multi-Store Marketplace Foundation
-- Extends the existing ec_stores table with marketplace/vendor fields.
-- Requires the ecommerce module to be installed first.

-- Vendor / marketplace seller profiles
CREATE TABLE IF NOT EXISTS ec_marketplace_vendors (
    id              INTEGER PRIMARY KEY AUTO_INCREMENT,
    store_id        INT NOT NULL,
    user_id         INT DEFAULT NULL,
    vendor_name     VARCHAR(200) NOT NULL,
    vendor_slug     VARCHAR(200) NOT NULL,
    description     TEXT DEFAULT NULL,
    logo_url        VARCHAR(500) DEFAULT NULL,
    commission_rate DECIMAL(5,2) DEFAULT 0.00,        -- platform commission %
    payout_method   ENUM('manual','bank','paypal','stripe') DEFAULT 'manual',
    payout_details  TEXT DEFAULT NULL,                 -- JSON (encrypted)
    status          ENUM('pending','active','suspended','closed') DEFAULT 'pending',
    approved_at     DATETIME DEFAULT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_vendor_slug (vendor_slug),
    INDEX idx_vendor_store (store_id),
    INDEX idx_vendor_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Vendor payout ledger
CREATE TABLE IF NOT EXISTS ec_marketplace_payouts (
    id          INTEGER PRIMARY KEY AUTO_INCREMENT,
    vendor_id   INT NOT NULL,
    order_id    INT DEFAULT NULL,
    amount      DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    currency    VARCHAR(3) NOT NULL DEFAULT 'USD',
    type        ENUM('sale','refund','adjustment','payout') NOT NULL DEFAULT 'sale',
    status      ENUM('pending','processing','completed','failed') DEFAULT 'pending',
    reference   VARCHAR(200) DEFAULT NULL,
    note        TEXT DEFAULT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_payout_vendor (vendor_id),
    INDEX idx_payout_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Product-vendor assignment (many-to-one for now, extendable)
CREATE TABLE IF NOT EXISTS ec_marketplace_product_vendors (
    product_id  INT NOT NULL,
    vendor_id   INT NOT NULL,
    PRIMARY KEY (product_id),
    INDEX idx_mpv_vendor (vendor_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
