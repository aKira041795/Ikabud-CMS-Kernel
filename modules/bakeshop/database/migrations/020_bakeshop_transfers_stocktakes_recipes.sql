-- Bakeshop — Transfers, Stocktakes, and Recipe Versioning
--
-- Additive, MySQL 5.7 compatible migration.
--
-- 1. bakeshop_transfers — balanced inter-branch ingredient transfers
-- 2. bakeshop_stocktake_sessions + bakeshop_stocktake_items — counted/reviewed/posted counts
-- 3. bakeshop_recipe_versions — immutable recipe snapshots bound to production

-- ═══════════════════════════════════════════════════════════════
-- 1. Balanced Transfers
-- ═══════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS bakeshop_transfers (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    branch_id INT UNSIGNED NOT NULL COMMENT 'Source branch',
    destination_branch_id INT UNSIGNED NOT NULL COMMENT 'Target branch',
    transfer_date DATE NOT NULL,
    document_no VARCHAR(50) DEFAULT NULL,
    status ENUM('draft','dispatched','received','cancelled') NOT NULL DEFAULT 'draft',
    version INT UNSIGNED NOT NULL DEFAULT 1,
    received_at DATETIME DEFAULT NULL,
    received_by INT UNSIGNED DEFAULT NULL,
    cancelled_at DATETIME DEFAULT NULL,
    cancelled_by INT UNSIGNED DEFAULT NULL,
    cancel_reason VARCHAR(255) DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    created_by INT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_tr_branch (branch_id),
    INDEX idx_tr_dest (destination_branch_id),
    INDEX idx_tr_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bakeshop_transfer_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    transfer_id INT UNSIGNED NOT NULL,
    ingredient_id INT UNSIGNED NOT NULL,
    qty DECIMAL(14,4) NOT NULL,
    unit_id INT UNSIGNED NOT NULL,
    unit_cost DECIMAL(14,4) DEFAULT NULL COMMENT 'Snapshotted at dispatch',
    line_amount DECIMAL(14,4) DEFAULT NULL,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    INDEX idx_ti_transfer (transfer_id),
    INDEX idx_ti_ingredient (ingredient_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ═══════════════════════════════════════════════════════════════
-- 2. Stocktake Sessions
-- ═══════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS bakeshop_stocktake_sessions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    branch_id INT UNSIGNED NOT NULL,
    stocktake_date DATE NOT NULL,
    document_no VARCHAR(50) DEFAULT NULL,
    status ENUM('draft','counted','reviewed','posted','cancelled') NOT NULL DEFAULT 'draft',
    version INT UNSIGNED NOT NULL DEFAULT 1,
    counted_by INT UNSIGNED DEFAULT NULL,
    counted_at DATETIME DEFAULT NULL,
    reviewed_by INT UNSIGNED DEFAULT NULL,
    reviewed_at DATETIME DEFAULT NULL,
    posted_by INT UNSIGNED DEFAULT NULL,
    posted_at DATETIME DEFAULT NULL,
    cancelled_at DATETIME DEFAULT NULL,
    cancel_reason VARCHAR(255) DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    created_by INT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_ss_branch (branch_id),
    INDEX idx_ss_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bakeshop_stocktake_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_id INT UNSIGNED NOT NULL,
    ingredient_id INT UNSIGNED NOT NULL,
    expected_qty DECIMAL(14,4) NOT NULL COMMENT 'Ledger balance at count time',
    counted_qty DECIMAL(14,4) DEFAULT NULL COMMENT 'Actual physical count',
    variance_qty DECIMAL(14,4) GENERATED ALWAYS AS (COALESCE(counted_qty, 0) - expected_qty) STORED,
    unit_id INT UNSIGNED NOT NULL,
    notes VARCHAR(255) DEFAULT NULL,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    INDEX idx_si_session (session_id),
    INDEX idx_si_ingredient (ingredient_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ═══════════════════════════════════════════════════════════════
-- 3. Recipe Versions (immutable snapshots)
-- ═══════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS bakeshop_recipe_headers (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id INT UNSIGNED NOT NULL,
    version_no INT UNSIGNED NOT NULL COMMENT 'Monotonically increasing per product',
    notes VARCHAR(255) DEFAULT NULL COMMENT 'Reason for version bump',
    created_by INT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_rh_product_version (product_id, version_no),
    INDEX idx_rh_product (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bakeshop_recipe_version_lines (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    recipe_header_id INT UNSIGNED NOT NULL,
    ingredient_id INT UNSIGNED NOT NULL,
    qty DECIMAL(14,4) NOT NULL,
    unit_id INT UNSIGNED NOT NULL,
    INDEX idx_rvl_header (recipe_header_id),
    INDEX idx_rvl_ingredient (ingredient_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
