-- ============================================================
-- Daily Ledger Module — Product Output Profile (Option A)
--
-- Adds per-product batch yield metadata used by Production Output
-- batch-count encoding.
-- ============================================================

SET @has_output_pieces := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'dl_products'
      AND COLUMN_NAME = 'output_pieces_per_batch'
);
SET @sql := IF(
    @has_output_pieces = 0,
    'ALTER TABLE dl_products ADD COLUMN output_pieces_per_batch INT UNSIGNED DEFAULT NULL AFTER sort_order',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_output_unit := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'dl_products'
      AND COLUMN_NAME = 'output_unit_label'
);
SET @sql := IF(
    @has_output_unit = 0,
    'ALTER TABLE dl_products ADD COLUMN output_unit_label VARCHAR(20) NOT NULL DEFAULT ''pcs'' AFTER output_pieces_per_batch',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE dl_products
SET output_unit_label = 'pcs'
WHERE output_unit_label IS NULL OR output_unit_label = '';
