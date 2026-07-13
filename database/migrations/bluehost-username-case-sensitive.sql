-- ============================================================
-- Bluehost: Username Case-Sensitivity Hardening
-- Applies utf8mb4_bin to ALL auth username columns across ALL tables.
-- Safe to run repeatedly (ALTER TABLE … MODIFY is idempotent).
-- MySQL 5.7 / MariaDB 10.x compatible.
-- ============================================================

-- Kernel
ALTER TABLE users                    MODIFY username VARCHAR(50)  CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL;
ALTER TABLE cms_users                MODIFY username VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL;

-- Project Audit Ledger
ALTER TABLE pal_users                MODIFY username VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL;

-- Bakeshop
ALTER TABLE bakeshop_users           MODIFY username VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL;

-- Attendance-Wage
ALTER TABLE attendance_wage_users    MODIFY username VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL;

-- EHR
ALTER TABLE ehr_users                MODIFY username VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL;

-- WMS
ALTER TABLE wms_users                MODIFY username VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL;

-- Inventory Scanner
ALTER TABLE is_users                 MODIFY username VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL;

-- Daily Ledger
ALTER TABLE dl_users                 MODIFY username VARCHAR(50)  CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL;
ALTER TABLE dl_admins                MODIFY username VARCHAR(50)  CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL;
ALTER TABLE dl_cashiers              MODIFY username VARCHAR(50)  CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL;
ALTER TABLE dl_supervisors           MODIFY username VARCHAR(50)  CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL;
ALTER TABLE dl_production_incharges  MODIFY username VARCHAR(50)  CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL;
