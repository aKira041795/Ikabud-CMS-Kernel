SET FOREIGN_KEY_CHECKS = 0;

-- Add quotation-like fields to pal_projects (JO merge)
ALTER TABLE pal_projects
    ADD COLUMN scope_of_work ENUM('new','refurbish','warranty_claim','labor_only','print_only') DEFAULT NULL AFTER job_order_number,
    ADD COLUMN with_installation TINYINT(1) NOT NULL DEFAULT 0 AFTER scope_of_work,
    ADD COLUMN installation_charge DECIMAL(18,2) NOT NULL DEFAULT 0.00 AFTER estimated_cost,
    ADD COLUMN mobilization_charge DECIMAL(18,2) NOT NULL DEFAULT 0.00 AFTER installation_charge,
    ADD COLUMN other_charges DECIMAL(18,2) NOT NULL DEFAULT 0.00 AFTER mobilization_charge,
    ADD COLUMN mode_of_payment ENUM('cash','check','bank_transfer','gcash') DEFAULT NULL AFTER other_charges,
    ADD COLUMN down_payment DECIMAL(18,2) DEFAULT NULL AFTER mode_of_payment,
    ADD COLUMN down_payment_type ENUM('down_payment','full_payment') DEFAULT NULL AFTER down_payment;

-- Update statuses to JO flow
ALTER TABLE pal_projects
    MODIFY COLUMN status ENUM('draft','pending','approved','started','ongoing','completed','cancelled','closed') NOT NULL DEFAULT 'draft';

-- Project/Job Order line items (replaces pal_quotation_items)
CREATE TABLE IF NOT EXISTS pal_project_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT UNSIGNED NOT NULL,
    project_id INT UNSIGNED NOT NULL,
    material_id INT UNSIGNED DEFAULT NULL,
    particulars VARCHAR(255) NOT NULL,
    width DECIMAL(10,2) DEFAULT NULL,
    height DECIMAL(10,2) DEFAULT NULL,
    uom VARCHAR(20) DEFAULT NULL,
    quantity DECIMAL(10,2) NOT NULL DEFAULT 1,
    price_per_unit DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    price_per_sqft DECIMAL(18,2) DEFAULT NULL,
    line_total DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    sort_order INT UNSIGNED NOT NULL DEFAULT 0,
    INDEX idx_pal_pi_project (project_id),
    INDEX idx_pal_pi_material (material_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
