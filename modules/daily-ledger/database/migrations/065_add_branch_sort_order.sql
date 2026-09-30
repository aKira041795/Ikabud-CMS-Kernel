-- 065: per-branch display order for the Daily Production Sheet
--
-- The Daily Sheet orders its branch columns alphabetically today. The owner's
-- paper form has a fixed branch order, so each branch carries an explicit
-- sort_order that the branches admin edit view sets.
--
-- The default of 0 is deliberate and load-bearing: an unconfigured tenant (or
-- any branch left at 0) falls through the sheet query's `ORDER BY sort_order
-- ASC, name ASC` tie-breaker to the exact alphabetical order it had before.
-- No backfill is required or performed.
--
-- Additive only: one column, no table rebuild, no data change on re-run.
--
-- @mysql57-compat: guarded dynamic ALTER via information_schema, InnoDB table
-- unchanged, utf8mb4_unicode_ci respected, INT NOT NULL DEFAULT 0.

SET @sort_order_exists := (
    SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name = 'dl_branches'
       AND column_name = 'sort_order'
);
SET @sql := IF(@sort_order_exists = 0,
    'ALTER TABLE dl_branches ADD COLUMN sort_order INT NOT NULL DEFAULT 0',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
