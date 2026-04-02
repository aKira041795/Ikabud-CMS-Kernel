-- ============================================================
-- Migration 003: Normalize kernel users table to Kernel OS roles
-- Safe for fresh installs: skips if users table does not exist.
-- ============================================================

SET @_m003_exists := (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'users');

SET @_m003_upd := IF(@_m003_exists > 0, 'UPDATE `users` SET `role` = ''viewer'' WHERE `role` IN (''supervisor'', ''cashier'')', 'SELECT 1');
PREPARE _m003_stmt FROM @_m003_upd;
EXECUTE _m003_stmt;
DEALLOCATE PREPARE _m003_stmt;

SET @_m003_alt := IF(@_m003_exists > 0, 'ALTER TABLE `users` MODIFY COLUMN `role` ENUM(''admin'',''superadmin'',''manager'',''viewer'') NOT NULL DEFAULT ''viewer''', 'SELECT 1');
PREPARE _m003_stmt FROM @_m003_alt;
EXECUTE _m003_stmt;
DEALLOCATE PREPARE _m003_stmt;