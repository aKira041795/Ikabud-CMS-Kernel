-- ============================================================
-- Migration 076: a distinct finding_type for a business day that could NOT
-- close because its PM shift is still open.
--
-- Commit 531106f8 added the closed_without_pm_finalize admin notification but,
-- because its allowed file set excluded migrations, it had to reuse the
-- existing 'variance' finding_type. The event is therefore indistinguishable
-- from a real count variance and cannot be addressed by type on the admin
-- notification surface. This migration ADDS exactly one enum value at the END.
--
-- HARD CONSTRAINT: never drop, rename or reorder an existing ENUM value.
-- The five existing values keep their exact order, so every historical row
-- keeps its stored meaning. 'closed_without_pm_finalize' is appended, taking
-- ordinal 6 for new rows only. No historical row is relabelled or backfilled.
--
-- MySQL 5.7 compatible: one guarded MODIFY COLUMN using the
-- SET @sql = IF(...) + PREPARE pattern already used by migrations 069/074/075.
-- No window functions, no CTEs, no JSON_TABLE, no LIMIT inside IN (subquery).
-- The MODIFY COLUMN restates the column's existing NOT NULL and no-default
-- characteristics, so the ALTER is a pure enum extension and does not touch
-- row storage for the existing ordinals.
-- Rerun-safe: when the new value is already present the guard executes
-- 'SELECT 1' instead of rebuilding the table.
-- ============================================================

SET @dl_in_notif_finding_type_enum := (
  SELECT IF(
    LOCATE('closed_without_pm_finalize', COLUMN_TYPE) > 0,
    'SELECT 1',
    'ALTER TABLE dl_integrity_notifications MODIFY COLUMN finding_type ENUM(''variance'',''unresolved_origin'',''uncounted_receipt'',''historical_digest'',''receipt_mismatch'',''closed_without_pm_finalize'') NOT NULL'
  )
    FROM information_schema.columns
   WHERE table_schema = DATABASE()
     AND table_name = 'dl_integrity_notifications'
     AND column_name = 'finding_type'
   LIMIT 1
);
PREPARE dl_in_notif_finding_type_st FROM @dl_in_notif_finding_type_enum;
EXECUTE dl_in_notif_finding_type_st;
DEALLOCATE PREPARE dl_in_notif_finding_type_st;
