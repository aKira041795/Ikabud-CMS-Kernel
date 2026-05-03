-- ============================================================
-- Daily Ledger Module — Per-branch per-product supply overrides
--
-- A branch may be commissary_supplied/self_managed/hybrid by default,
-- but individual products may override that source. Example:
--   Branch C + Pandesal       = commissary
--   Branch C + Cake Slice     = local_production
--   Branch C + Softdrinks     = direct_purchase
-- ============================================================

CREATE TABLE IF NOT EXISTS dl_branch_product_supply_rules (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    branch_id INT UNSIGNED NOT NULL,
    product_id INT UNSIGNED NOT NULL,
    supply_source_type ENUM('commissary','local_production','direct_purchase','manual') NOT NULL,
    source_id INT UNSIGNED NULL DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by INT UNSIGNED NULL DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_dl_bpsr (branch_id, product_id),
    CONSTRAINT fk_dl_bpsr_branch FOREIGN KEY (branch_id) REFERENCES dl_branches(id) ON DELETE CASCADE,
    CONSTRAINT fk_dl_bpsr_product FOREIGN KEY (product_id) REFERENCES dl_products(id) ON DELETE CASCADE,
    INDEX idx_dl_bpsr_branch_active (branch_id, is_active),
    INDEX idx_dl_bpsr_source_type (supply_source_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
