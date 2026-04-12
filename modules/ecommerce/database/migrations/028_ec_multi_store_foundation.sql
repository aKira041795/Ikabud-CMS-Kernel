-- ============================================================
-- Ecommerce Module — Multi-Store Foundation
-- Creates ec_stores, ec_store_product_overrides,
-- ec_store_inventory_sources; adds store_id to ec_orders.
-- Safe to re-run (idempotent).
-- ============================================================

-- ── 1. Store registry ─────────────────────────────────────

CREATE TABLE IF NOT EXISTS ec_stores (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code          VARCHAR(50)   NOT NULL                    COMMENT 'Short machine-readable identifier, e.g. main, branch-a',
    name          VARCHAR(255)  NOT NULL,
    slug          VARCHAR(100)  NOT NULL,
    description   TEXT          NULL,
    is_active     TINYINT(1)    NOT NULL DEFAULT 1,
    is_default    TINYINT(1)    NOT NULL DEFAULT 0          COMMENT 'Exactly one store should have is_default = 1',
    settings_json JSON          NULL                        COMMENT 'Store-level overrides: shipping, tax, currency, checkout options',
    created_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_ec_stores_code (code),
    UNIQUE KEY uq_ec_stores_slug (slug),
    KEY           idx_ec_stores_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 2. Per-store product overrides ────────────────────────

CREATE TABLE IF NOT EXISTS ec_store_product_overrides (
    id                  INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
    store_id            INT UNSIGNED  NOT NULL,
    product_id          INT UNSIGNED  NOT NULL  COMMENT 'cms_content.id of the product',
    is_visible          TINYINT(1)    NOT NULL DEFAULT 1  COMMENT '0 = hidden in this store',
    price_override      DECIMAL(12,2) NULL DEFAULT NULL   COMMENT 'Replaces base price when non-null',
    sale_price_override DECIMAL(12,2) NULL DEFAULT NULL   COMMENT 'Replaces sale price when non-null',
    sort_override       INT           NULL DEFAULT NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_ec_spo_store_product (store_id, product_id),
    KEY idx_ec_spo_product (product_id),
    CONSTRAINT fk_ec_spo_store
        FOREIGN KEY (store_id) REFERENCES ec_stores (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 3. Per-store inventory sources ────────────────────────

CREATE TABLE IF NOT EXISTS ec_store_inventory_sources (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    store_id      INT UNSIGNED NOT NULL,
    source_type   VARCHAR(20)  NOT NULL DEFAULT 'local'  COMMENT 'local | wms',
    warehouse_id  INT UNSIGNED NULL                      COMMENT 'wms_warehouses.id when source_type = wms',
    is_active     TINYINT(1)   NOT NULL DEFAULT 1,
    priority      INT          NOT NULL DEFAULT 0        COMMENT 'Lower = preferred when multiple sources',
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_ec_sis_store (store_id),
    CONSTRAINT fk_ec_sis_store
        FOREIGN KEY (store_id) REFERENCES ec_stores (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 4. Add store_id to ec_orders (idempotent) ─────────────

SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'ec_orders'
      AND COLUMN_NAME  = 'store_id'
);

SET @sql_col = IF(@col_exists = 0,
    'ALTER TABLE ec_orders
         ADD COLUMN store_id INT UNSIGNED NULL DEFAULT NULL AFTER source,
         ADD KEY idx_ec_orders_store_id (store_id)',
    'SELECT 1');

PREPARE stmt FROM @sql_col;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ── 5. Default store seed (only when table was just created) ──

INSERT INTO ec_stores (code, name, slug, is_active, is_default, created_at, updated_at)
SELECT 'default', 'Default Store', 'default', 1, 1, NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM ec_stores LIMIT 1);
