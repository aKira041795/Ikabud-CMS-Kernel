-- WMS Products, Warehouses, Locations, Batches, Suppliers
-- Phase 2: Product Catalog + Warehouse Structure

CREATE TABLE IF NOT EXISTS wms_products (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sku              VARCHAR(100) NOT NULL,
    barcode          VARCHAR(100) NULL DEFAULT NULL COMMENT 'EAN-13/QR/custom',
    name             VARCHAR(255) NOT NULL,
    description      TEXT NULL DEFAULT NULL,
    unit             VARCHAR(50) NOT NULL DEFAULT 'pcs' COMMENT 'pcs, kg, L, box, etc.',
    product_type     VARCHAR(50) NOT NULL DEFAULT 'physical',
    weight           DECIMAL(10,4) NULL DEFAULT NULL,
    dimensions       JSON NULL DEFAULT NULL COMMENT '{length, width, height, unit}',
    is_batch_tracked TINYINT(1) NOT NULL DEFAULT 0,
    reorder_point    DECIMAL(14,4) NULL DEFAULT NULL COMMENT 'Trigger for replenishment',
    safety_stock     DECIMAL(14,4) NULL DEFAULT NULL COMMENT 'Minimum buffer before stockout',
    meta             JSON NULL DEFAULT NULL,
    is_active        TINYINT(1) NOT NULL DEFAULT 1,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_wms_sku (sku),
    INDEX idx_wms_product_barcode (barcode),
    INDEX idx_wms_product_type (product_type),
    INDEX idx_wms_product_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS wms_warehouses (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code                VARCHAR(50) NOT NULL,
    name                VARCHAR(255) NOT NULL,
    address             TEXT NULL DEFAULT NULL,
    quarantine_location_id INT UNSIGNED NULL DEFAULT NULL COMMENT 'Default location for returns inspection',
    contact_info        JSON NULL DEFAULT NULL COMMENT '{phone, email, manager}',
    is_active           TINYINT(1) NOT NULL DEFAULT 1,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_wms_warehouse_code (code),
    INDEX idx_wms_warehouse_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS wms_locations (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    warehouse_id   INT UNSIGNED NOT NULL,
    parent_id      INT UNSIGNED NULL DEFAULT NULL COMMENT 'Self-ref for hierarchy',
    code           VARCHAR(100) NOT NULL COMMENT 'e.g. A-01-02-03',
    name           VARCHAR(255) NOT NULL,
    type           ENUM('zone','rack','shelf','bin') NOT NULL DEFAULT 'bin',
    capacity       DECIMAL(14,4) NULL DEFAULT NULL,
    capacity_unit  VARCHAR(50) NULL DEFAULT NULL,
    sort_order     INT UNSIGNED NOT NULL DEFAULT 0,
    is_active      TINYINT(1) NOT NULL DEFAULT 1,
    is_staging     TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Inbound staging — stock unavailable until putaway',
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_wms_location_code (warehouse_id, code),
    INDEX idx_wms_location_warehouse (warehouse_id),
    INDEX idx_wms_location_parent (parent_id),
    INDEX idx_wms_location_type (type),
    INDEX idx_wms_location_staging (warehouse_id, is_staging, is_active),
    CONSTRAINT fk_wms_location_warehouse FOREIGN KEY (warehouse_id)
        REFERENCES wms_warehouses (id) ON DELETE CASCADE,
    CONSTRAINT fk_wms_location_parent FOREIGN KEY (parent_id)
        REFERENCES wms_locations (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS wms_batches (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id     INT UNSIGNED NOT NULL,
    batch_number   VARCHAR(100) NOT NULL,
    lot_number     VARCHAR(100) NULL DEFAULT NULL,
    manufactured_at DATE NULL DEFAULT NULL,
    expires_at     DATE NULL DEFAULT NULL,
    meta           JSON NULL DEFAULT NULL,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_wms_batch (product_id, batch_number),
    INDEX idx_wms_batch_product (product_id),
    INDEX idx_wms_batch_expires (expires_at),
    CONSTRAINT fk_wms_batch_product FOREIGN KEY (product_id)
        REFERENCES wms_products (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS wms_suppliers (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    code            VARCHAR(50) NOT NULL,
    name            VARCHAR(255) NOT NULL,
    contact_person  VARCHAR(255) DEFAULT NULL,
    email           VARCHAR(255) DEFAULT NULL,
    phone           VARCHAR(50) DEFAULT NULL,
    address         TEXT DEFAULT NULL,
    lead_time_days  SMALLINT UNSIGNED DEFAULT NULL,
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    meta            JSON DEFAULT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_wms_suppliers_code (code),
    KEY idx_wms_suppliers_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
