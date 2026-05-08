-- Adds DR number tracing to production movements and commissary runs.

SET @has_prod_move_dr := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'dl_production_movements'
      AND COLUMN_NAME = 'dr_number'
);

SET @ddl := IF(
    @has_prod_move_dr = 0,
    'ALTER TABLE dl_production_movements ADD COLUMN dr_number VARCHAR(120) NULL DEFAULT NULL AFTER quantity',
    'SELECT 1'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_prod_move_dr_idx := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'dl_production_movements'
      AND INDEX_NAME = 'idx_dl_prod_move_dr_number'
);

SET @ddl := IF(
    @has_prod_move_dr_idx = 0,
    'ALTER TABLE dl_production_movements ADD INDEX idx_dl_prod_move_dr_number (dr_number)',
    'SELECT 1'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_run_dr := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'dl_production_runs'
      AND COLUMN_NAME = 'dr_number'
);

SET @ddl := IF(
    @has_run_dr = 0,
    'ALTER TABLE dl_production_runs ADD COLUMN dr_number VARCHAR(120) NULL DEFAULT NULL AFTER yield_qty',
    'SELECT 1'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;