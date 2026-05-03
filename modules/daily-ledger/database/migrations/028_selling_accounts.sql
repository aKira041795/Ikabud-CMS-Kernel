-- ============================================================
-- Daily Ledger Module — Selling accounts (Phase E)
--
-- Mall counters, kiosks, event booths, bazaars, school canteens,
-- resellers, and direct-customer accounts. They are NOT branches:
--   - pricing differs (price_group_id)
--   - inventory may come directly from a commissary
--   - sales accountability is per-account
-- A selling account may be assigned to a branch for supervision.
-- ============================================================

CREATE TABLE IF NOT EXISTS dl_selling_accounts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(30) NOT NULL,
    name VARCHAR(150) NOT NULL,
    account_type ENUM('mall','kiosk','event_booth','bazaar','school_canteen','reseller','direct_customer','other') NOT NULL DEFAULT 'other',
    assigned_branch_id INT UNSIGNED NULL DEFAULT NULL,
    supply_source_type ENUM('commissary','branch','supplier','manual') NOT NULL DEFAULT 'commissary',
    supply_source_id INT UNSIGNED NULL DEFAULT NULL,
    price_group_id INT UNSIGNED NULL DEFAULT NULL,
    ledger_type VARCHAR(50) NOT NULL DEFAULT 'mall_account',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_dl_sa_code (code),
    CONSTRAINT fk_dl_sa_branch FOREIGN KEY (assigned_branch_id) REFERENCES dl_branches(id) ON DELETE SET NULL,
    CONSTRAINT fk_dl_sa_pricegroup FOREIGN KEY (price_group_id) REFERENCES dl_price_groups(id) ON DELETE SET NULL,
    INDEX idx_dl_sa_branch (assigned_branch_id, is_active),
    INDEX idx_dl_sa_type (account_type, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
