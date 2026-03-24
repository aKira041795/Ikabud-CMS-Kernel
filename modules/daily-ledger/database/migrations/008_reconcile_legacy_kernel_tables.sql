-- ============================================================
-- Daily Ledger Module — Reconcile legacy kernel-era tables
--
-- Purpose:
-- 1) Ensure dl_admins exists for module-owned admin logins.
-- 2) Backfill canonical dl_* tables from legacy unprefixed tables when needed.
-- 3) Remove remaining legacy daily-ledger tables from the shared app DB.
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS dl_admins (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_dl_admins_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @src_exists := (
    SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'users'
);
SET @sql := IF(
    @src_exists > 0,
    'INSERT IGNORE INTO dl_admins (username, password_hash, full_name, is_active, created_at, updated_at) SELECT username, password_hash, full_name, is_active, created_at, updated_at FROM users WHERE role IN (''admin'',''superadmin'')',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @src_exists := (
    SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'branches'
);
SET @sql := IF(
    @src_exists > 0,
    'INSERT IGNORE INTO dl_branches (id, code, name, address, is_active, created_at, updated_at) SELECT id, code, name, address, is_active, created_at, updated_at FROM branches',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @src_exists := (
    SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'products'
);
SET @sql := IF(
    @src_exists > 0,
    'INSERT IGNORE INTO dl_products (id, sku, name, current_price, sort_order, is_active, created_at, updated_at) SELECT id, sku, name, current_price, sort_order, is_active, created_at, updated_at FROM products',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

DROP TABLE IF EXISTS user_branches;
DROP TABLE IF EXISTS branches;
DROP TABLE IF EXISTS products;

SET FOREIGN_KEY_CHECKS = 1;