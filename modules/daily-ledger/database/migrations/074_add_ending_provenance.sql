-- 074: ending provenance for verified settlement (settle -> verify -> revert)
--
-- A settled ending is written into the counted column, but it is TAGGED so it can never
-- masquerade as a human count: end_source carries the ladder rung while unverified,
-- end_settled_at records when it was derived, and end_verified_by / end_verified_at record
-- the admin who certified it. Historical rows keep end_source NULL, which means
-- "this ending was counted by a person" - the same meaning it has today. NEVER backfilled.
--
-- R6b: a derived ending must NOT manufacture a variance of zero. calc_variance on the
-- commissary product ledger is a STORED generated column, so the skip is expressed in its
-- generation expression: a row whose end_source is a derived rung yields NULL, leaving the
-- "nobody counted this" signal intact. Counted rows are computed exactly as before.
--
-- MySQL 5.7 compatible; every schema operation is guarded and rerun-safe.

-- ── dl_daily_ledger (cashier sheet) ──────────────────────────────────────
SET @sql := IF(EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'dl_daily_ledger' AND column_name = 'end_source'), 'SELECT 1', 'ALTER TABLE dl_daily_ledger ADD COLUMN end_source VARCHAR(24) NULL DEFAULT NULL AFTER bal_end');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'dl_daily_ledger' AND column_name = 'end_settled_at'), 'SELECT 1', 'ALTER TABLE dl_daily_ledger ADD COLUMN end_settled_at DATETIME NULL DEFAULT NULL AFTER end_source');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'dl_daily_ledger' AND column_name = 'end_verified_by'), 'SELECT 1', 'ALTER TABLE dl_daily_ledger ADD COLUMN end_verified_by INT UNSIGNED NULL DEFAULT NULL AFTER end_settled_at');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'dl_daily_ledger' AND column_name = 'end_verified_at'), 'SELECT 1', 'ALTER TABLE dl_daily_ledger ADD COLUMN end_verified_at DATETIME NULL DEFAULT NULL AFTER end_verified_by');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ── dl_commissary_product_ledger (production sheet) ──────────────────────
SET @sql := IF(EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'dl_commissary_product_ledger' AND column_name = 'end_source'), 'SELECT 1', 'ALTER TABLE dl_commissary_product_ledger ADD COLUMN end_source VARCHAR(24) NULL DEFAULT NULL AFTER actual_end_qty');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'dl_commissary_product_ledger' AND column_name = 'end_settled_at'), 'SELECT 1', 'ALTER TABLE dl_commissary_product_ledger ADD COLUMN end_settled_at DATETIME NULL DEFAULT NULL AFTER end_source');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'dl_commissary_product_ledger' AND column_name = 'end_verified_by'), 'SELECT 1', 'ALTER TABLE dl_commissary_product_ledger ADD COLUMN end_verified_by INT UNSIGNED NULL DEFAULT NULL AFTER end_settled_at');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'dl_commissary_product_ledger' AND column_name = 'end_verified_at'), 'SELECT 1', 'ALTER TABLE dl_commissary_product_ledger ADD COLUMN end_verified_at DATETIME NULL DEFAULT NULL AFTER end_verified_by');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- R6b: regenerate calc_variance so a derived ending yields NULL instead of 0. Guarded on
-- the generation expression already naming end_source, so this is rerun-safe.
SET @sql := IF(EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'dl_commissary_product_ledger' AND column_name = 'calc_variance' AND GENERATION_EXPRESSION LIKE '%end_source%'), 'SELECT 1', 'ALTER TABLE dl_commissary_product_ledger MODIFY COLUMN calc_variance INT GENERATED ALWAYS AS (CASE WHEN end_source IN (''derived-from-movements'',''zero-forced'') THEN NULL ELSE actual_end_qty - (beg_qty + produced_qty - dispatched_qty - wastage_qty) END) STORED AFTER end_verified_at');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
