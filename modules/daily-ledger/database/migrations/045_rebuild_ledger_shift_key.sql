-- ============================================================
-- Migration 045: Rebuild the shift-period unique key (AM/PM rows)
--
-- Completes the shift-period ledger started in 044. Every product-day
-- now holds up to two independent ledger rows:
--   (branch_id, product_id, ledger_date, shift = 'AM')  -> AM cashier
--   (branch_id, product_id, ledger_date, shift = 'PM')  -> PM cashier
--
-- The old unique key (branch_id, product_id, ledger_date) must be
-- replaced with one that includes `shift`. Deployments created that
-- key under different names (legacy `uq_ledger_entry` vs canonical
-- `uq_dl_ledger_entry`), so each candidate is dropped only when it
-- actually exists, using the single-statement
--   SET @sql = IF(EXISTS(...), 'ALTER ...', 'SELECT 1');
--   PREPARE / EXECUTE / DEALLOCATE
-- pattern. The canonical key + helper index are added only when
-- absent, making this migration idempotent and safe to re-run.
--
-- NOTE: no stored procedures — the module migration runner splits SQL
-- on every ';', so compound BEGIN...END blocks are not supported here.
--
-- Bluehost MySQL 5.7 compatible.
-- ============================================================

-- Drop the legacy unique key name if present.
SET @d1 = IF(EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'dl_daily_ledger' AND INDEX_NAME = 'uq_ledger_entry'), 'ALTER TABLE dl_daily_ledger DROP INDEX uq_ledger_entry', 'SELECT 1');
PREPARE s1 FROM @d1;
EXECUTE s1;
DEALLOCATE PREPARE s1;

-- Drop the canonical unique key if present (handles a prior partial/older build).
SET @d2 = IF(EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'dl_daily_ledger' AND INDEX_NAME = 'uq_dl_ledger_entry'), 'ALTER TABLE dl_daily_ledger DROP INDEX uq_dl_ledger_entry', 'SELECT 1');
PREPARE s2 FROM @d2;
EXECUTE s2;
DEALLOCATE PREPARE s2;

-- Add the shift-aware unique key if absent.
SET @a1 = IF(EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'dl_daily_ledger' AND INDEX_NAME = 'uq_dl_ledger_entry'), 'SELECT 1', 'ALTER TABLE dl_daily_ledger ADD UNIQUE KEY uq_dl_ledger_entry (branch_id, product_id, ledger_date, shift)');
PREPARE p1 FROM @a1;
EXECUTE p1;
DEALLOCATE PREPARE p1;

-- Add the shift/date helper index if absent.
SET @a2 = IF(EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'dl_daily_ledger' AND INDEX_NAME = 'idx_dl_ledger_shift_date'), 'SELECT 1', 'ALTER TABLE dl_daily_ledger ADD INDEX idx_dl_ledger_shift_date (shift, ledger_date)');
PREPARE p2 FROM @a2;
EXECUTE p2;
DEALLOCATE PREPARE p2;
