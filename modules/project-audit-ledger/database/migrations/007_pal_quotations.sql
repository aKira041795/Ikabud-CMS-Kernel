SET FOREIGN_KEY_CHECKS = 0;

-- 31. Quotations
CREATE TABLE IF NOT EXISTS pal_quotations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT UNSIGNED NOT NULL,
    quotation_number VARCHAR(50) NOT NULL,
    project_id INT UNSIGNED DEFAULT NULL,
    client_id INT UNSIGNED DEFAULT NULL,
    quotation_date DATE NOT NULL,
    scope_of_work ENUM('new','refurbish','warranty_claim','labor_only','print_only') DEFAULT NULL,
    with_installation TINYINT(1) NOT NULL DEFAULT 0,
    mode_of_payment ENUM('cash','check','bank_transfer','gcash') DEFAULT NULL,
    installation_charge DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    mobilization_charge DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    other_charges DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    down_payment DECIMAL(18,2) DEFAULT NULL,
    down_payment_type ENUM('down_payment','full_payment') DEFAULT NULL,
    subtotal DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    total_amount DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    notes TEXT DEFAULT NULL,
    status ENUM('draft','sent','approved','rejected','converted','expired') NOT NULL DEFAULT 'draft',
    converted_to_sale_id INT UNSIGNED DEFAULT NULL,
    created_by INT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_by INT UNSIGNED DEFAULT NULL,
    updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    INDEX idx_pal_quot_tenant (tenant_id),
    INDEX idx_pal_quot_project (project_id),
    INDEX idx_pal_quot_client (client_id),
    INDEX idx_pal_quot_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 32. Quotation items
CREATE TABLE IF NOT EXISTS pal_quotation_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT UNSIGNED NOT NULL,
    quotation_id INT UNSIGNED NOT NULL,
    material_id INT UNSIGNED DEFAULT NULL,
    particulars VARCHAR(255) NOT NULL,
    width DECIMAL(10,2) DEFAULT NULL,
    height DECIMAL(10,2) DEFAULT NULL,
    uom VARCHAR(20) DEFAULT NULL COMMENT 'e.g. ft, m, in',
    quantity DECIMAL(10,2) NOT NULL DEFAULT 1,
    price_per_unit DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    price_per_sqft DECIMAL(18,2) DEFAULT NULL,
    line_total DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    sort_order INT UNSIGNED NOT NULL DEFAULT 0,
    INDEX idx_pal_qi_quotation (quotation_id),
    INDEX idx_pal_qi_material (material_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
