-- ============================================================
-- Daily Ledger Module — Soft delete for user accounts
--
-- Adds a nullable deleted_at column to all four dl user tables
-- so admins can soft-delete accounts (preserves all referenced
-- data: ledger encoded_by/updated_by, audit logs, etc.) while
-- hiding them from the default admin user list.
-- ============================================================

SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'dl_cashiers' AND column_name = 'deleted_at'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE dl_cashiers ADD COLUMN deleted_at DATETIME NULL DEFAULT NULL, ADD INDEX idx_dl_cashiers_deleted (deleted_at)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'dl_supervisors' AND column_name = 'deleted_at'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE dl_supervisors ADD COLUMN deleted_at DATETIME NULL DEFAULT NULL, ADD INDEX idx_dl_supervisors_deleted (deleted_at)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'dl_admins' AND column_name = 'deleted_at'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE dl_admins ADD COLUMN deleted_at DATETIME NULL DEFAULT NULL, ADD INDEX idx_dl_admins_deleted (deleted_at)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'dl_production_incharges' AND column_name = 'deleted_at'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE dl_production_incharges ADD COLUMN deleted_at DATETIME NULL DEFAULT NULL, ADD INDEX idx_dl_prod_incharges_deleted (deleted_at)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
