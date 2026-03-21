-- ============================================================
-- Drop legacy tables once canonical replacements exist
--
-- Purpose:
-- - Enforce canonical schema usage across modules.
-- - Remove old/unprefixed legacy tables no longer used at runtime.
-- - Avoid destructive drops unless replacement tables exist.
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;

-- Always remove explicit legacy marker tables.
DROP TABLE IF EXISTS dl_branches_legacy;
DROP TABLE IF EXISTS dl_user_branches_legacy;

-- Drop unprefixed daily-ledger legacy tables only if canonical tables exist.
SET @has_new := (
    SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'dl_daily_ledger'
);
SET @sql := IF(@has_new > 0, 'DROP TABLE IF EXISTS daily_ledger', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_new := (
    SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'dl_branch_products'
);
SET @sql := IF(@has_new > 0, 'DROP TABLE IF EXISTS branch_products', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_new := (
    SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'dl_product_price_history'
);
SET @sql := IF(@has_new > 0, 'DROP TABLE IF EXISTS product_price_history', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_new := (
    SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'dl_ledger_day_status'
);
SET @sql := IF(@has_new > 0, 'DROP TABLE IF EXISTS ledger_day_status', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_new := (
    SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'dl_variance_flags'
);
SET @sql := IF(@has_new > 0, 'DROP TABLE IF EXISTS variance_flags', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_new := (
    SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'dl_branches'
);
SET @sql := IF(@has_new > 0, 'DROP TABLE IF EXISTS branches', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- user_branches is obsolete after daily-ledger module isolation.
DROP TABLE IF EXISTS user_branches;

SET FOREIGN_KEY_CHECKS = 1;
