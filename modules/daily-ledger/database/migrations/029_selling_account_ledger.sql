-- ============================================================
-- Daily Ledger Module — Selling-account ledger (Phase E)
--
-- Per-day sales encoding for a selling account.
-- Formula:
--   sold_qty     = beg_qty + delivered_qty - return_qty - end_qty
--   gross_amount = sold_qty * price_snapshot
--
-- This is intentionally a parallel table to dl_daily_ledger so
-- branch-scoped handlers and queries stay untouched.
-- ============================================================

CREATE TABLE IF NOT EXISTS dl_selling_account_ledger (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    selling_account_id INT UNSIGNED NOT NULL,
    product_id INT UNSIGNED NOT NULL,
    ledger_date DATE NOT NULL,
    price_snapshot DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    beg_qty INT NOT NULL DEFAULT 0,
    delivered_qty INT NOT NULL DEFAULT 0,
    return_qty INT NOT NULL DEFAULT 0,
    end_qty INT NOT NULL DEFAULT 0,
    sold_qty INT NOT NULL DEFAULT 0,
    gross_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    encoded_by INT UNSIGNED NULL DEFAULT NULL,
    updated_by INT UNSIGNED NULL DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_dl_sal (selling_account_id, product_id, ledger_date),
    CONSTRAINT fk_dl_sal_account FOREIGN KEY (selling_account_id) REFERENCES dl_selling_accounts(id) ON DELETE CASCADE,
    CONSTRAINT fk_dl_sal_product FOREIGN KEY (product_id) REFERENCES dl_products(id) ON DELETE CASCADE,
    INDEX idx_dl_sal_date (ledger_date),
    INDEX idx_dl_sal_account_date (selling_account_id, ledger_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dl_selling_account_day_status (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    selling_account_id INT UNSIGNED NOT NULL,
    ledger_date DATE NOT NULL,
    status ENUM('open','closed') NOT NULL DEFAULT 'open',
    closed_by INT UNSIGNED NULL DEFAULT NULL,
    closed_at DATETIME NULL DEFAULT NULL,
    reopened_by INT UNSIGNED NULL DEFAULT NULL,
    reopened_at DATETIME NULL DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_dl_sads (selling_account_id, ledger_date),
    CONSTRAINT fk_dl_sads_account FOREIGN KEY (selling_account_id) REFERENCES dl_selling_accounts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
