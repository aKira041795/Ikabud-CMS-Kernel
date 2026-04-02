-- ============================================================
-- Migration 004: Remove legacy Daily Ledger roles from kernel users
-- Safe for fresh installs: skips if users table does not exist.
-- ============================================================

SET @_m004_exists := (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'users');

SET @_m004_upd := IF(@_m004_exists > 0, 'UPDATE `users` SET `role` = ''viewer'' WHERE `role` IN (''supervisor'', ''cashier'')', 'SELECT 1');
PREPARE _m004_stmt FROM @_m004_upd;
EXECUTE _m004_stmt;
DEALLOCATE PREPARE _m004_stmt;

SET @_m004_alt := IF(@_m004_exists > 0, 'ALTER TABLE `users` MODIFY COLUMN `role` ENUM(''admin'',''superadmin'',''manager'',''viewer'') NOT NULL DEFAULT ''viewer''', 'SELECT 1');
PREPARE _m004_stmt FROM @_m004_alt;
EXECUTE _m004_stmt;
DEALLOCATE PREPARE _m004_stmt;