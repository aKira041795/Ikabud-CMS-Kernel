-- ============================================================
-- Daily Ledger Module — Branch supply mode
--
-- Adds:
--   dl_branches.default_supply_mode  ENUM('commissary_supplied','self_managed','hybrid')
--   dl_branches.assigned_commissary_id  INT UNSIGNED NULL (self-FK; commissary modeled as flagged branch)
--   dl_branches.is_commissary  TINYINT(1) NOT NULL DEFAULT 0
--
-- Idempotent via information_schema guards.
-- ============================================================

SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'dl_branches' AND column_name = 'default_supply_mode'
);
SET @sql := IF(@col_exists = 0,
    "ALTER TABLE dl_branches ADD COLUMN default_supply_mode ENUM('commissary_supplied','self_managed','hybrid') NOT NULL DEFAULT 'self_managed' AFTER area",
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'dl_branches' AND column_name = 'assigned_commissary_id'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE dl_branches ADD COLUMN assigned_commissary_id INT UNSIGNED NULL DEFAULT NULL AFTER default_supply_mode',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'dl_branches' AND column_name = 'is_commissary'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE dl_branches ADD COLUMN is_commissary TINYINT(1) NOT NULL DEFAULT 0 AFTER assigned_commissary_id',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists := (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'dl_branches' AND index_name = 'idx_dl_branches_supply_mode'
);
SET @sql := IF(@idx_exists = 0,
    'ALTER TABLE dl_branches ADD INDEX idx_dl_branches_supply_mode (default_supply_mode)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists := (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'dl_branches' AND index_name = 'idx_dl_branches_commissary'
);
SET @sql := IF(@idx_exists = 0,
    'ALTER TABLE dl_branches ADD INDEX idx_dl_branches_commissary (is_commissary, is_active)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
