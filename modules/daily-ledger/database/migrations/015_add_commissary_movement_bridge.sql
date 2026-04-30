-- 015_add_commissary_movement_bridge.sql
-- Adds a nullable FK column to dl_production_runs that stores the id of the
-- dl_production_movements row auto-created when a commissary run is saved with
-- a destination branch and a yield > 0.  This lets the bridge handler do
-- idempotent updates: on re-save it knows which movement to reverse/adjust
-- instead of creating duplicate entries.

SET @has_col := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'dl_production_runs'
      AND COLUMN_NAME  = 'commissary_movement_id'
);

SET @sql := IF(
    @has_col = 0,
    'ALTER TABLE dl_production_runs ADD COLUMN commissary_movement_id BIGINT UNSIGNED NULL DEFAULT NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
