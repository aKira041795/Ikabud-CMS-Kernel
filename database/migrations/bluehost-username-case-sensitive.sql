-- ============================================================
-- Bluehost: Username Case-Sensitivity Hardening
-- Applies utf8mb4_bin to EVERY auth username column that EXISTS in
-- this install. Each ALTER is guarded by an information_schema
-- existence check, so the file is safe on a barebones kernel (only
-- `users` present) and on a full install (all module tables present).
-- Safe to run repeatedly (ALTER TABLE ... MODIFY is idempotent).
-- MySQL 5.7 / MariaDB 10.x compatible.
-- ============================================================

-- Kernel (always present)
SET @ik_stm := (SELECT IF(COUNT(*) > 0, 'ALTER TABLE users MODIFY username VARCHAR(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL', 'SELECT 1') FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'users');
PREPARE ik_s FROM @ik_stm; EXECUTE ik_s; DEALLOCATE PREPARE ik_s;

-- CMS
SET @ik_stm := (SELECT IF(COUNT(*) > 0, 'ALTER TABLE cms_users MODIFY username VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL', 'SELECT 1') FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'cms_users');
PREPARE ik_s FROM @ik_stm; EXECUTE ik_s; DEALLOCATE PREPARE ik_s;

-- Project Audit Ledger
SET @ik_stm := (SELECT IF(COUNT(*) > 0, 'ALTER TABLE pal_users MODIFY username VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL', 'SELECT 1') FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'pal_users');
PREPARE ik_s FROM @ik_stm; EXECUTE ik_s; DEALLOCATE PREPARE ik_s;

-- Bakeshop
SET @ik_stm := (SELECT IF(COUNT(*) > 0, 'ALTER TABLE bakeshop_users MODIFY username VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL', 'SELECT 1') FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'bakeshop_users');
PREPARE ik_s FROM @ik_stm; EXECUTE ik_s; DEALLOCATE PREPARE ik_s;

-- Attendance-Wage
SET @ik_stm := (SELECT IF(COUNT(*) > 0, 'ALTER TABLE attendance_wage_users MODIFY username VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL', 'SELECT 1') FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'attendance_wage_users');
PREPARE ik_s FROM @ik_stm; EXECUTE ik_s; DEALLOCATE PREPARE ik_s;

-- EHR
SET @ik_stm := (SELECT IF(COUNT(*) > 0, 'ALTER TABLE ehr_users MODIFY username VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL', 'SELECT 1') FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'ehr_users');
PREPARE ik_s FROM @ik_stm; EXECUTE ik_s; DEALLOCATE PREPARE ik_s;

-- WMS
SET @ik_stm := (SELECT IF(COUNT(*) > 0, 'ALTER TABLE wms_users MODIFY username VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL', 'SELECT 1') FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'wms_users');
PREPARE ik_s FROM @ik_stm; EXECUTE ik_s; DEALLOCATE PREPARE ik_s;

-- Inventory Scanner
SET @ik_stm := (SELECT IF(COUNT(*) > 0, 'ALTER TABLE is_users MODIFY username VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL', 'SELECT 1') FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'is_users');
PREPARE ik_s FROM @ik_stm; EXECUTE ik_s; DEALLOCATE PREPARE ik_s;

-- Daily Ledger
SET @ik_stm := (SELECT IF(COUNT(*) > 0, 'ALTER TABLE dl_users MODIFY username VARCHAR(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL', 'SELECT 1') FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'dl_users');
PREPARE ik_s FROM @ik_stm; EXECUTE ik_s; DEALLOCATE PREPARE ik_s;

SET @ik_stm := (SELECT IF(COUNT(*) > 0, 'ALTER TABLE dl_admins MODIFY username VARCHAR(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL', 'SELECT 1') FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'dl_admins');
PREPARE ik_s FROM @ik_stm; EXECUTE ik_s; DEALLOCATE PREPARE ik_s;

SET @ik_stm := (SELECT IF(COUNT(*) > 0, 'ALTER TABLE dl_cashiers MODIFY username VARCHAR(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL', 'SELECT 1') FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'dl_cashiers');
PREPARE ik_s FROM @ik_stm; EXECUTE ik_s; DEALLOCATE PREPARE ik_s;

SET @ik_stm := (SELECT IF(COUNT(*) > 0, 'ALTER TABLE dl_supervisors MODIFY username VARCHAR(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL', 'SELECT 1') FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'dl_supervisors');
PREPARE ik_s FROM @ik_stm; EXECUTE ik_s; DEALLOCATE PREPARE ik_s;

SET @ik_stm := (SELECT IF(COUNT(*) > 0, 'ALTER TABLE dl_production_incharges MODIFY username VARCHAR(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL', 'SELECT 1') FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'dl_production_incharges');
PREPARE ik_s FROM @ik_stm; EXECUTE ik_s; DEALLOCATE PREPARE ik_s;
