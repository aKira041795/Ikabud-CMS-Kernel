-- 087: per-consignee display order for the Daily Production Sheet
--
-- Consignees are listed on the Daily Sheet and in the admin consignee list.
-- The owner's paper form has a fixed consignee order, so each consignee carries
-- an explicit sort_order that the consignee admin form sets.
--
-- The default of 0 is deliberate and load-bearing: an unconfigured tenant (or
-- any consignee left at 0) falls through the listing query's `ORDER BY
-- (sort_order = 0) ASC, sort_order ASC, name ASC` clause to the exact
-- alphabetical order it had before. 0 is the "unnumbered" marker, and the
-- leading boolean pushes every unnumbered consignee AFTER the numbered ones so
-- a newly added consignee can never jump to the front. No backfill is required
-- or performed.
--
-- Additive only: one column, no table rebuild, no data change on re-run.
--
-- @mysql57-compat: guarded dynamic ALTER via information_schema, InnoDB table
-- unchanged, utf8mb4_unicode_ci respected, INT NOT NULL DEFAULT 0.

SET @sort_order_exists := (
    SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name = 'dl_consignees'
       AND column_name = 'sort_order'
);
SET @sql := IF(@sort_order_exists = 0,
    'ALTER TABLE dl_consignees ADD COLUMN sort_order INT NOT NULL DEFAULT 0',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
