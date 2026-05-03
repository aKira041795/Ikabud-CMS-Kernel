-- ============================================================
-- Daily Ledger Module — Formal delivery & branch receiving workflow
--
-- Introduces:
--   dl_deliveries           Header for outbound stock movement (DR-tracked).
--   dl_delivery_items       Line items for a delivery (with price snapshot).
--   dl_branch_receivings    Header for branch-side receipt of a delivery.
--   dl_branch_receiving_items  Line items received (qty + variance fodder).
--   dl_delivery_variance_flags   Sent-vs-received variance tracking.
--
-- Status values: draft | posted | voided.
-- Only POSTED branch_receivings affect dl_daily_ledger.addtl.
-- Only POSTED deliveries to a selling_account affect the
-- selling-account ledger (selling accounts have no separate
-- receiving step — see migration 029).
-- ============================================================

CREATE TABLE IF NOT EXISTS dl_deliveries (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    origin_type ENUM('commissary','branch','supplier','manual') NOT NULL,
    origin_id INT UNSIGNED NULL DEFAULT NULL,
    destination_type ENUM('branch','selling_account','own_account','reseller','customer','event','wastage','internal_use','adjustment') NOT NULL,
    destination_id INT UNSIGNED NULL DEFAULT NULL,
    dr_number VARCHAR(100) NULL DEFAULT NULL,
    delivery_date DATE NOT NULL,
    status ENUM('draft','posted','voided') NOT NULL DEFAULT 'draft',
    created_by INT UNSIGNED NULL DEFAULT NULL,
    posted_by INT UNSIGNED NULL DEFAULT NULL,
    posted_at DATETIME NULL DEFAULT NULL,
    voided_by INT UNSIGNED NULL DEFAULT NULL,
    voided_at DATETIME NULL DEFAULT NULL,
    remarks TEXT NULL DEFAULT NULL,
    legacy_cashier_withdrawal_id BIGINT UNSIGNED NULL DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_dl_deliveries_dest (destination_type, destination_id, status),
    INDEX idx_dl_deliveries_origin (origin_type, origin_id),
    INDEX idx_dl_deliveries_date (delivery_date),
    INDEX idx_dl_deliveries_dr (dr_number),
    INDEX idx_dl_deliveries_legacy (legacy_cashier_withdrawal_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dl_delivery_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    delivery_id BIGINT UNSIGNED NOT NULL,
    product_id INT UNSIGNED NOT NULL,
    quantity INT NOT NULL DEFAULT 0,
    unit VARCHAR(30) NOT NULL DEFAULT 'pcs',
    unit_cost_snapshot DECIMAL(10,2) NULL DEFAULT NULL,
    price_snapshot DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    price_group_id INT UNSIGNED NULL DEFAULT NULL,
    remarks TEXT NULL DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_dl_di_delivery FOREIGN KEY (delivery_id) REFERENCES dl_deliveries(id) ON DELETE CASCADE,
    CONSTRAINT fk_dl_di_product FOREIGN KEY (product_id) REFERENCES dl_products(id) ON DELETE RESTRICT,
    INDEX idx_dl_di_product (product_id),
    INDEX idx_dl_di_delivery (delivery_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dl_branch_receivings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    branch_id INT UNSIGNED NOT NULL,
    origin_type ENUM('commissary','branch','supplier','local_production','manual_adjustment','selling_account_return') NOT NULL,
    origin_id INT UNSIGNED NULL DEFAULT NULL,
    delivery_id BIGINT UNSIGNED NULL DEFAULT NULL,
    dr_number VARCHAR(100) NULL DEFAULT NULL,
    received_by INT UNSIGNED NULL DEFAULT NULL,
    received_at DATETIME NULL DEFAULT NULL,
    received_ledger_date DATE NOT NULL,
    status ENUM('draft','posted','voided') NOT NULL DEFAULT 'draft',
    posted_by INT UNSIGNED NULL DEFAULT NULL,
    posted_at DATETIME NULL DEFAULT NULL,
    voided_by INT UNSIGNED NULL DEFAULT NULL,
    voided_at DATETIME NULL DEFAULT NULL,
    remarks TEXT NULL DEFAULT NULL,
    legacy_cashier_withdrawal_id BIGINT UNSIGNED NULL DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_dl_brcv_branch FOREIGN KEY (branch_id) REFERENCES dl_branches(id) ON DELETE CASCADE,
    CONSTRAINT fk_dl_brcv_delivery FOREIGN KEY (delivery_id) REFERENCES dl_deliveries(id) ON DELETE SET NULL,
    INDEX idx_dl_brcv_branch_status (branch_id, status, received_ledger_date),
    INDEX idx_dl_brcv_origin (origin_type, origin_id),
    INDEX idx_dl_brcv_dr (dr_number),
    INDEX idx_dl_brcv_legacy (legacy_cashier_withdrawal_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dl_branch_receiving_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    receiving_id BIGINT UNSIGNED NOT NULL,
    delivery_item_id BIGINT UNSIGNED NULL DEFAULT NULL,
    product_id INT UNSIGNED NOT NULL,
    quantity_received INT NOT NULL DEFAULT 0,
    unit VARCHAR(30) NOT NULL DEFAULT 'pcs',
    unit_cost_snapshot DECIMAL(10,2) NULL DEFAULT NULL,
    selling_price_snapshot DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    remarks TEXT NULL DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_dl_brcvi_receiving FOREIGN KEY (receiving_id) REFERENCES dl_branch_receivings(id) ON DELETE CASCADE,
    CONSTRAINT fk_dl_brcvi_product FOREIGN KEY (product_id) REFERENCES dl_products(id) ON DELETE RESTRICT,
    CONSTRAINT fk_dl_brcvi_di FOREIGN KEY (delivery_item_id) REFERENCES dl_delivery_items(id) ON DELETE SET NULL,
    INDEX idx_dl_brcvi_product (product_id),
    INDEX idx_dl_brcvi_receiving (receiving_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dl_delivery_variance_flags (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    delivery_id BIGINT UNSIGNED NOT NULL,
    receiving_id BIGINT UNSIGNED NULL DEFAULT NULL,
    product_id INT UNSIGNED NOT NULL,
    sent_qty INT NOT NULL DEFAULT 0,
    received_qty INT NOT NULL DEFAULT 0,
    variance INT NOT NULL DEFAULT 0,
    is_reviewed TINYINT(1) NOT NULL DEFAULT 0,
    reviewed_by INT UNSIGNED NULL DEFAULT NULL,
    reviewed_at DATETIME NULL DEFAULT NULL,
    review_note TEXT NULL DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_dl_dvf_delivery FOREIGN KEY (delivery_id) REFERENCES dl_deliveries(id) ON DELETE CASCADE,
    CONSTRAINT fk_dl_dvf_receiving FOREIGN KEY (receiving_id) REFERENCES dl_branch_receivings(id) ON DELETE SET NULL,
    CONSTRAINT fk_dl_dvf_product FOREIGN KEY (product_id) REFERENCES dl_products(id) ON DELETE CASCADE,
    UNIQUE KEY uq_dl_dvf (delivery_id, product_id),
    INDEX idx_dl_dvf_unreviewed (is_reviewed, delivery_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
