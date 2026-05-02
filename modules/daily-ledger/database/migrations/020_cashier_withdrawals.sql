-- ============================================================
-- Daily Ledger Module — Cashier Withdrawals
--
-- Tracks granular withdrawals by cashiers, which sum up into
-- dl_daily_ledger.withdraw.
-- ============================================================

CREATE TABLE IF NOT EXISTS dl_cashier_withdrawals (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    branch_id INT UNSIGNED NOT NULL,
    product_id INT UNSIGNED NOT NULL,
    ledger_date DATE NOT NULL,
    withdrawal_type VARCHAR(30) NOT NULL COMMENT 'charge, pullout, delivery',
    dr_number VARCHAR(100) DEFAULT NULL,
    target_branch_id INT UNSIGNED DEFAULT NULL,
    quantity INT UNSIGNED NOT NULL DEFAULT 0,
    encoded_by INT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_dl_cw_branch_date (branch_id, ledger_date),
    INDEX idx_dl_cw_product (product_id),
    CONSTRAINT fk_dl_cw_branch FOREIGN KEY (branch_id) REFERENCES dl_branches(id) ON DELETE CASCADE,
    CONSTRAINT fk_dl_cw_target_branch FOREIGN KEY (target_branch_id) REFERENCES dl_branches(id) ON DELETE SET NULL,
    CONSTRAINT fk_dl_cw_product FOREIGN KEY (product_id) REFERENCES dl_products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
