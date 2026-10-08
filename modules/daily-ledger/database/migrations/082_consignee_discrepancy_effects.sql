-- 082: Distinguish the dispatch credit from one idempotent discrepancy adjustment.
--
-- MySQL 5.7 compatibility is INSPECTION-ONLY (no local 5.7 server). This uses
-- only guarded ALTER TABLE statements and ENUM/index syntax available in 5.7.

SET @sql := IF(
    EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'dl_consignee_ledger_effects' AND column_name = 'effect_kind'),
    'SELECT 1',
    'ALTER TABLE dl_consignee_ledger_effects ADD COLUMN effect_kind ENUM(''credit'',''adjustment'') NOT NULL DEFAULT ''credit'' AFTER quantity'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Keep an explicit FK-supporting index before replacing the old unique index.
SET @sql := IF(
    EXISTS(SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'dl_consignee_ledger_effects' AND index_name = 'idx_dl_consignee_effect_item'),
    'SELECT 1',
    'ALTER TABLE dl_consignee_ledger_effects ADD INDEX idx_dl_consignee_effect_item (delivery_item_id)'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    EXISTS(SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'dl_consignee_ledger_effects' AND index_name = 'uq_dl_consignee_effect_item'),
    'ALTER TABLE dl_consignee_ledger_effects DROP INDEX uq_dl_consignee_effect_item',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    EXISTS(SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'dl_consignee_ledger_effects' AND index_name = 'uq_dl_consignee_effect_item_kind'),
    'SELECT 1',
    'ALTER TABLE dl_consignee_ledger_effects ADD UNIQUE KEY uq_dl_consignee_effect_item_kind (delivery_item_id, effect_kind)'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
