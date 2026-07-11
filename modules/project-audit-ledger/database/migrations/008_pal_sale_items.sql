SET FOREIGN_KEY_CHECKS = 0;

-- 33. Sale items (line items for sales invoices)
CREATE TABLE IF NOT EXISTS pal_sale_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT UNSIGNED NOT NULL,
    sale_id INT UNSIGNED NOT NULL,
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
    INDEX idx_pal_si_sale (sale_id),
    INDEX idx_pal_si_material (material_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add new columns to pal_sales (ZAP-ARTS fields)
-- Note: net_amount is a GENERATED ALWAYS AS (gross_amount - discount_amount + tax_amount) STORED column
-- We need to drop and re-add it to include the extra charges
-- MySQL 5.7 safe: drop the generated column first, add new columns, re-add generated column

ALTER TABLE pal_sales
    ADD COLUMN quotation_id INT UNSIGNED DEFAULT NULL AFTER client_id,
    ADD COLUMN scope_of_work ENUM('new','refurbish','warranty_claim','labor_only','print_only') DEFAULT NULL AFTER due_date,
    ADD COLUMN with_installation TINYINT(1) NOT NULL DEFAULT 0 AFTER scope_of_work,
    ADD COLUMN installation_charge DECIMAL(18,2) NOT NULL DEFAULT 0.00 AFTER tax_amount,
    ADD COLUMN mobilization_charge DECIMAL(18,2) NOT NULL DEFAULT 0.00 AFTER installation_charge,
    ADD COLUMN other_charges DECIMAL(18,2) NOT NULL DEFAULT 0.00 AFTER mobilization_charge,
    ADD COLUMN down_payment DECIMAL(18,2) DEFAULT NULL AFTER other_charges,
    ADD COLUMN down_payment_type ENUM('down_payment','full_payment') DEFAULT NULL AFTER down_payment,
    ADD COLUMN mode_of_payment ENUM('cash','check','bank_transfer','gcash') DEFAULT NULL AFTER down_payment_type;

-- Rebuild the generated column: drop existing, add back with new formula
-- Note: this requires dropping `net_amount` as a generated column requires a full table rebuild in MySQL 5.7
-- We use a safe ALTER TABLE sequence
ALTER TABLE pal_sales
    DROP COLUMN net_amount,
    ADD COLUMN net_amount DECIMAL(18,2) GENERATED ALWAYS AS (gross_amount - discount_amount + tax_amount + installation_charge + mobilization_charge + other_charges) STORED AFTER other_charges;

SET FOREIGN_KEY_CHECKS = 1;
