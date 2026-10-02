-- 070: AM/PM dimension for the Production Daily Sheet
--
-- Historical production rows deliberately remain NULL. NULL means "recorded
-- before production shifts were introduced" and is never rewritten to AM or PM.
-- New Daily Sheet writes always carry AM or PM.
-- MySQL 5.7 compatible; every schema operation is guarded and rerun-safe.

SET @sql := IF(EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'dl_commissary_product_ledger' AND column_name = 'shift'), 'SELECT 1', 'ALTER TABLE dl_commissary_product_ledger ADD COLUMN shift ENUM(''AM'',''PM'') NULL DEFAULT NULL AFTER ledger_date');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- The old unique key may be the supporting index for the branch FK. Add an
-- explicit left-prefix index before dropping it (avoids MySQL error 1553).
SET @sql := IF(EXISTS(SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'dl_commissary_product_ledger' AND index_name = 'idx_dl_cpl_commissary'), 'SELECT 1', 'ALTER TABLE dl_commissary_product_ledger ADD INDEX idx_dl_cpl_commissary (commissary_branch_id)');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(EXISTS(SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'dl_commissary_product_ledger' AND index_name = 'dl_cpl_date_product_commissary'), 'ALTER TABLE dl_commissary_product_ledger DROP INDEX dl_cpl_date_product_commissary', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- MySQL unique indexes permit multiple NULLs. Index a generated sentinel so
-- legacy NULL-shift rows remain unique without changing their historical value.
SET @sql := IF(EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'dl_commissary_product_ledger' AND column_name = 'shift_key'), 'SELECT 1', 'ALTER TABLE dl_commissary_product_ledger ADD COLUMN shift_key VARCHAR(2) GENERATED ALWAYS AS (COALESCE(shift, '''')) STORED AFTER shift');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(EXISTS(SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'dl_commissary_product_ledger' AND index_name = 'uq_dl_cpl_shift'), 'SELECT 1', 'ALTER TABLE dl_commissary_product_ledger ADD UNIQUE KEY uq_dl_cpl_shift (commissary_branch_id, product_id, ledger_date, shift_key)');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'dl_production_movements' AND column_name = 'shift'), 'SELECT 1', 'ALTER TABLE dl_production_movements ADD COLUMN shift ENUM(''AM'',''PM'') NULL DEFAULT NULL AFTER ledger_date');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'dl_production_runs' AND column_name = 'shift'), 'SELECT 1', 'ALTER TABLE dl_production_runs ADD COLUMN shift ENUM(''AM'',''PM'') NULL DEFAULT NULL AFTER ledger_date');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'dl_deliveries' AND column_name = 'production_shift'), 'SELECT 1', 'ALTER TABLE dl_deliveries ADD COLUMN production_shift ENUM(''AM'',''PM'') NULL DEFAULT NULL AFTER delivery_date');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
