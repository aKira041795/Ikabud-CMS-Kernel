-- ============================================================
-- Daily Ledger Module — Canonical dl_* Schema Alignment
--
-- Purpose:
-- 1) Ensure dl_* tables used by runtime handlers exist.
-- 2) Backfill data from legacy unprefixed tables when present.
-- 3) Remove legacy daily-ledger tables to prevent schema drift.
--
-- Notes:
-- - Keep global `products` table intact for other modules.
-- - This migration is idempotent and safe to re-run.
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS dl_products (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sku VARCHAR(30) NOT NULL UNIQUE,
    name VARCHAR(150) NOT NULL,
    current_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    sort_order INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_dl_products_active (is_active),
    INDEX idx_dl_products_sort (sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dl_product_price_history (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id INT UNSIGNED NOT NULL,
    price DECIMAL(10,2) NOT NULL,
    changed_by INT UNSIGNED DEFAULT NULL,
    effective_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_dl_pph_product FOREIGN KEY (product_id) REFERENCES dl_products(id) ON DELETE CASCADE,
    INDEX idx_dl_pph_product_date (product_id, effective_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dl_branch_products (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    branch_id INT UNSIGNED NOT NULL,
    product_id INT UNSIGNED NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_dl_branch_product (branch_id, product_id),
    CONSTRAINT fk_dl_bp_branch FOREIGN KEY (branch_id) REFERENCES dl_branches(id) ON DELETE CASCADE,
    CONSTRAINT fk_dl_bp_product FOREIGN KEY (product_id) REFERENCES dl_products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dl_daily_ledger (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    branch_id INT UNSIGNED NOT NULL,
    product_id INT UNSIGNED NOT NULL,
    ledger_date DATE NOT NULL,
    price_snapshot DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    beg_bal INT NOT NULL DEFAULT 0,
    addtl INT NOT NULL DEFAULT 0,
    withdraw INT NOT NULL DEFAULT 0,
    bal_end INT NOT NULL DEFAULT 0,
    sales INT NOT NULL DEFAULT 0,
    encoded_by INT UNSIGNED DEFAULT NULL,
    updated_by INT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_dl_ledger_entry (branch_id, product_id, ledger_date),
    CONSTRAINT fk_dl_ledger_branch FOREIGN KEY (branch_id) REFERENCES dl_branches(id) ON DELETE CASCADE,
    CONSTRAINT fk_dl_ledger_product FOREIGN KEY (product_id) REFERENCES dl_products(id) ON DELETE CASCADE,
    INDEX idx_dl_ledger_date (ledger_date),
    INDEX idx_dl_ledger_branch_date (branch_id, ledger_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dl_ledger_day_status (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    branch_id INT UNSIGNED NOT NULL,
    ledger_date DATE NOT NULL,
    status ENUM('open','closed') NOT NULL DEFAULT 'open',
    closed_by INT UNSIGNED DEFAULT NULL,
    closed_at DATETIME DEFAULT NULL,
    reopened_by INT UNSIGNED DEFAULT NULL,
    reopened_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_dl_day_status (branch_id, ledger_date),
    CONSTRAINT fk_dl_lds_branch FOREIGN KEY (branch_id) REFERENCES dl_branches(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dl_variance_flags (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    branch_id INT UNSIGNED NOT NULL,
    product_id INT UNSIGNED NOT NULL,
    ledger_date DATE NOT NULL,
    prev_bal_end INT DEFAULT NULL,
    current_beg_bal INT DEFAULT NULL,
    variance INT NOT NULL DEFAULT 0,
    is_reviewed TINYINT(1) NOT NULL DEFAULT 0,
    reviewed_by INT UNSIGNED DEFAULT NULL,
    reviewed_at DATETIME DEFAULT NULL,
    review_note TEXT DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_dl_variance (branch_id, product_id, ledger_date),
    CONSTRAINT fk_dl_vf_branch FOREIGN KEY (branch_id) REFERENCES dl_branches(id) ON DELETE CASCADE,
    CONSTRAINT fk_dl_vf_product FOREIGN KEY (product_id) REFERENCES dl_products(id) ON DELETE CASCADE,
    INDEX idx_dl_vf_unreviewed (is_reviewed, branch_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Backfill from global products so daily-ledger can be fully isolated.
SET @src_exists := (
    SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'products'
);
SET @sql := IF(
    @src_exists > 0,
    'INSERT IGNORE INTO dl_products (id, sku, name, current_price, sort_order, is_active, created_at, updated_at) SELECT p.id, p.sku, p.name, p.current_price, p.sort_order, p.is_active, p.created_at, p.updated_at FROM products p',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Backfill legacy unprefixed daily-ledger tables when they exist.
SET @src_exists := (
    SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'branch_products'
);
SET @sql := IF(
    @src_exists > 0,
    'INSERT IGNORE INTO dl_branch_products (id, branch_id, product_id, is_active, created_at) SELECT bp.id, bp.branch_id, bp.product_id, bp.is_active, bp.created_at FROM branch_products bp INNER JOIN dl_branches b ON b.id = bp.branch_id INNER JOIN dl_products p ON p.id = bp.product_id',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @src_exists := (
    SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'product_price_history'
);
SET @sql := IF(
    @src_exists > 0,
    'INSERT IGNORE INTO dl_product_price_history (id, product_id, price, changed_by, effective_at, created_at) SELECT pph.id, pph.product_id, pph.price, pph.changed_by, pph.effective_at, pph.created_at FROM product_price_history pph INNER JOIN dl_products p ON p.id = pph.product_id',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @src_exists := (
    SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'daily_ledger'
);
SET @sql := IF(
    @src_exists > 0,
    'INSERT IGNORE INTO dl_daily_ledger (id, branch_id, product_id, ledger_date, price_snapshot, beg_bal, addtl, withdraw, bal_end, sales, encoded_by, updated_by, created_at, updated_at) SELECT dl.id, dl.branch_id, dl.product_id, dl.ledger_date, dl.price_snapshot, dl.beg_bal, dl.addtl, dl.withdraw, dl.bal_end, dl.sales, dl.encoded_by, dl.updated_by, dl.created_at, dl.updated_at FROM daily_ledger dl INNER JOIN dl_branches b ON b.id = dl.branch_id INNER JOIN dl_products p ON p.id = dl.product_id',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @src_exists := (
    SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'ledger_day_status'
);
SET @sql := IF(
    @src_exists > 0,
    'INSERT IGNORE INTO dl_ledger_day_status (id, branch_id, ledger_date, status, closed_by, closed_at, reopened_by, reopened_at, created_at, updated_at) SELECT lds.id, lds.branch_id, lds.ledger_date, lds.status, lds.closed_by, lds.closed_at, lds.reopened_by, lds.reopened_at, lds.created_at, lds.updated_at FROM ledger_day_status lds INNER JOIN dl_branches b ON b.id = lds.branch_id',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @src_exists := (
    SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'variance_flags'
);
SET @sql := IF(
    @src_exists > 0,
    'INSERT IGNORE INTO dl_variance_flags (id, branch_id, product_id, ledger_date, prev_bal_end, current_beg_bal, variance, is_reviewed, reviewed_by, reviewed_at, review_note, created_at) SELECT vf.id, vf.branch_id, vf.product_id, vf.ledger_date, vf.prev_bal_end, vf.current_beg_bal, vf.variance, vf.is_reviewed, vf.reviewed_by, vf.reviewed_at, vf.review_note, vf.created_at FROM variance_flags vf INNER JOIN dl_branches b ON b.id = vf.branch_id INNER JOIN dl_products p ON p.id = vf.product_id',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Legacy cleanup: daily-ledger should no longer use unprefixed tables.
DROP TABLE IF EXISTS variance_flags;
DROP TABLE IF EXISTS ledger_day_status;
DROP TABLE IF EXISTS daily_ledger;
DROP TABLE IF EXISTS branch_products;
DROP TABLE IF EXISTS product_price_history;

SET FOREIGN_KEY_CHECKS = 1;