-- Migration: 017_wms_phase6_financials
-- Purpose: Adds Purchase Orders and configuration for Costing/Valuation

CREATE TABLE IF NOT EXISTS wms_purchase_orders (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    supplier_id INT UNSIGNED NOT NULL,
    warehouse_id INT UNSIGNED NOT NULL,
    status ENUM('draft', 'submitted', 'received', 'cancelled') NOT NULL DEFAULT 'draft',
    expected_delivery_date DATE DEFAULT NULL,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL DEFAULT NULL,
    KEY idx_wms_po_supplier (supplier_id),
    KEY idx_wms_po_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS wms_purchase_order_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    purchase_order_id INT UNSIGNED NOT NULL,
    product_id INT UNSIGNED NOT NULL,
    qty DECIMAL(10,4) NOT NULL DEFAULT 0.0000,
    unit_cost DECIMAL(10,4) NOT NULL DEFAULT 0.0000,
    KEY idx_wms_po_items_po (purchase_order_id),
    KEY idx_wms_po_items_product (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add Financial / Costing Configuration Defaults
INSERT IGNORE INTO wms_configs (config_key, config_value, description) VALUES
    ('financial.costing_method', '"FIFO"', 'Inventory valuation method: FIFO or MAC (Moving Average Cost)'),
    ('financial.default_currency', '"USD"', 'Default currency code for valuation reporting');
