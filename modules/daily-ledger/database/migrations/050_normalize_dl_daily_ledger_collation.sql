-- ============================================================
-- Migration 050: Normalize dl_daily_ledger collation to utf8mb4_unicode_ci
--
-- Bluehost's legacy dl_daily_ledger was created with the server
-- default utf8mb4_general_ci. Migration 049 added
-- dl_ledger_shift_status (utf8mb4_unicode_ci), and the LEFT JOIN
-- ... ss.shift = dl.shift comparison then fails with MySQL error
-- 1267 "Illegal mix of collations (utf8mb4_unicode_ci,IMPLICIT)
-- and (utf8mb4_general_ci,IMPLICIT) for operation '='".
--
-- Convert the table (and therefore its `shift` ENUM column) to the
-- canonical utf8mb4_unicode_ci used by every other daily-ledger
-- table. Guarded: no-op when the table is already conforming, and
-- skipped entirely when the table does not exist.
--
-- Bluehost MySQL 5.7 compatible; single-statement ALTER via the
-- SET @sql=IF(...)+PREPARE pattern.
-- ============================================================

SET @dl_coll = IF(
  EXISTS(SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'dl_daily_ledger')
  AND COALESCE((SELECT TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'dl_daily_ledger'), '') <> 'utf8mb4_unicode_ci',
  'ALTER TABLE dl_daily_ledger CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
  'SELECT 1'
);
PREPARE dl_coll_st FROM @dl_coll;
EXECUTE dl_coll_st;
DEALLOCATE PREPARE dl_coll_st;
