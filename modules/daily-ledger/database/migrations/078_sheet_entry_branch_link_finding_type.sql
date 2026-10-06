-- ============================================================
-- Migration 078: a distinct finding_type for a branch link that the Production
-- Daily Sheet switched back on automatically because someone recorded a
-- delivery to a branch that did not list the product.
--
-- Owner decision (2026-10-06): the encoder's data is authoritative and the
-- branch product list is bookkeeping that may simply be behind, so a
-- destination a branch does not currently list is no longer a wall. The entry
-- is recorded, the branch link is revived, and the admin is told. Reusing
-- 'variance' would make this indistinguishable from a real count variance on the
-- admin notification surface, exactly as migration 076 fixed for
-- closed_without_pm_finalize.
--
-- DEPLOYMENT: without this migration the revival still happens, but the finding
-- is stored with an EMPTY finding_type (dl_raiseIntegrityNotification uses
-- INSERT IGNORE, which downgrades an unknown ENUM value to a warning rather than
-- an error). The write path detects that and logs it at error level, and the G2
-- oracle fails. Run: php ikabud tenant:migrate <tenant> daily-ledger
--
-- HARD CONSTRAINT: never drop, rename or reorder an existing ENUM value. The
-- six existing values keep their exact order, so every historical row keeps its
-- stored meaning. 'product_branch_link_revived' is appended, taking ordinal 7
-- for new rows only. No historical row is relabelled or backfilled.
--
-- MySQL 5.7 compatible: one guarded MODIFY COLUMN using the SET @sql = IF(...)
-- + PREPARE pattern already used by migrations 069/074/075/076. No window
-- functions, no CTEs, no JSON_TABLE, no LIMIT inside IN (subquery).
-- The MODIFY COLUMN restates the column's existing NOT NULL and no-default
-- characteristics, so the ALTER is a pure enum extension and does not touch row
-- storage for the existing ordinals.
--
-- Rerun-safe: when the new value is already present the guard executes
-- 'SELECT 1' instead of rebuilding the table. If an earlier enum extension was
-- skipped on this database the restated list also supplies the missing earlier
-- values, so the guard self-heals instead of failing the migration.
-- ============================================================

SET @dl_in_notif_finding_type_enum := (
  SELECT IF(
    LOCATE('product_branch_link_revived', COLUMN_TYPE) > 0,
    'SELECT 1',
    'ALTER TABLE dl_integrity_notifications MODIFY COLUMN finding_type ENUM(''variance'',''unresolved_origin'',''uncounted_receipt'',''historical_digest'',''receipt_mismatch'',''closed_without_pm_finalize'',''product_branch_link_revived'') NOT NULL'
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
