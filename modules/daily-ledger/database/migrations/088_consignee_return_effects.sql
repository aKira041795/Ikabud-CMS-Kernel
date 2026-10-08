-- 088: standalone, append-only consignee return / pullout effects.
--
-- Returns are custody movements rather than delivery corrections. They therefore
-- have no delivery or delivery-item identity; the existing unique index permits
-- repeated NULL delivery_item_id values.
--
-- @mysql57-compat: every ALTER is guarded through information_schema.COLUMNS;
-- ENUM and nullable foreign-key columns use syntax supported by MySQL 5.7.

SET @sql := IF(
    (SELECT column_type FROM information_schema.columns
      WHERE table_schema = DATABASE()
        AND table_name = 'dl_consignee_ledger_effects'
        AND column_name = 'effect_kind') LIKE '%''return''%',
    'SELECT 1',
    'ALTER TABLE dl_consignee_ledger_effects MODIFY COLUMN effect_kind ENUM(''credit'',''adjustment'',''return'') NOT NULL DEFAULT ''credit'''
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT is_nullable FROM information_schema.columns
      WHERE table_schema = DATABASE()
        AND table_name = 'dl_consignee_ledger_effects'
        AND column_name = 'delivery_id') = 'YES',
    'SELECT 1',
    'ALTER TABLE dl_consignee_ledger_effects MODIFY COLUMN delivery_id BIGINT UNSIGNED NULL'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT is_nullable FROM information_schema.columns
      WHERE table_schema = DATABASE()
        AND table_name = 'dl_consignee_ledger_effects'
        AND column_name = 'delivery_item_id') = 'YES',
    'SELECT 1',
    'ALTER TABLE dl_consignee_ledger_effects MODIFY COLUMN delivery_item_id BIGINT UNSIGNED NULL'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
