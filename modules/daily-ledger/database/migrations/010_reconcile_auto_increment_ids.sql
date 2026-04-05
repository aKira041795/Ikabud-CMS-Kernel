-- ============================================================
-- Daily Ledger Module — Reconcile AUTO_INCREMENT primary keys
--
-- Some tenant databases were created from legacy schemas where dl_* tables
-- kept PRIMARY KEY(id) but lost AUTO_INCREMENT. That breaks runtime inserts
-- and deterministic CLI capability fixtures.
--
-- This migration is idempotent and only alters tables that already exist.
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;

SET @table_exists := (
    SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'dl_admins'
);
SET @sql := IF(
    @table_exists > 0,
    'ALTER TABLE dl_admins MODIFY COLUMN id INT UNSIGNED NOT NULL AUTO_INCREMENT',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @table_exists := (
    SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'dl_branches'
);
SET @sql := IF(
    @table_exists > 0,
    'ALTER TABLE dl_branches MODIFY COLUMN id INT UNSIGNED NOT NULL AUTO_INCREMENT',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @table_exists := (
    SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'dl_cashiers'
);
SET @sql := IF(
    @table_exists > 0,
    'ALTER TABLE dl_cashiers MODIFY COLUMN id INT UNSIGNED NOT NULL AUTO_INCREMENT',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @table_exists := (
    SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'dl_supervisors'
);
SET @sql := IF(
    @table_exists > 0,
    'ALTER TABLE dl_supervisors MODIFY COLUMN id INT UNSIGNED NOT NULL AUTO_INCREMENT',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @table_exists := (
    SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'dl_supervisor_branches'
);
SET @sql := IF(
    @table_exists > 0,
    'ALTER TABLE dl_supervisor_branches MODIFY COLUMN id INT UNSIGNED NOT NULL AUTO_INCREMENT',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @table_exists := (
    SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'dl_products'
);
SET @sql := IF(
    @table_exists > 0,
    'ALTER TABLE dl_products MODIFY COLUMN id INT UNSIGNED NOT NULL AUTO_INCREMENT',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @table_exists := (
    SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'dl_product_price_history'
);
SET @sql := IF(
    @table_exists > 0,
    'ALTER TABLE dl_product_price_history MODIFY COLUMN id INT UNSIGNED NOT NULL AUTO_INCREMENT',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @table_exists := (
    SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'dl_branch_products'
);
SET @sql := IF(
    @table_exists > 0,
    'ALTER TABLE dl_branch_products MODIFY COLUMN id INT UNSIGNED NOT NULL AUTO_INCREMENT',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @table_exists := (
    SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'dl_daily_ledger'
);
SET @sql := IF(
    @table_exists > 0,
    'ALTER TABLE dl_daily_ledger MODIFY COLUMN id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @table_exists := (
    SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'dl_ledger_day_status'
);
SET @sql := IF(
    @table_exists > 0,
    'ALTER TABLE dl_ledger_day_status MODIFY COLUMN id INT UNSIGNED NOT NULL AUTO_INCREMENT',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @table_exists := (
    SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'dl_variance_flags'
);
SET @sql := IF(
    @table_exists > 0,
    'ALTER TABLE dl_variance_flags MODIFY COLUMN id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @table_exists := (
    SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'dl_production_movements'
);
SET @sql := IF(
    @table_exists > 0,
    'ALTER TABLE dl_production_movements MODIFY COLUMN id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @table_exists := (
    SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'dl_production_incharges'
);
SET @sql := IF(
    @table_exists > 0,
    'ALTER TABLE dl_production_incharges MODIFY COLUMN id INT UNSIGNED NOT NULL AUTO_INCREMENT',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @table_exists := (
    SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'dl_production_incharge_branches'
);
SET @sql := IF(
    @table_exists > 0,
    'ALTER TABLE dl_production_incharge_branches MODIFY COLUMN id INT UNSIGNED NOT NULL AUTO_INCREMENT',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET FOREIGN_KEY_CHECKS = 1;