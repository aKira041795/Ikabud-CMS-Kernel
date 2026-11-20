-- 112 — REVERSE 111. A returned item is NOT stock.
--
-- Owner direction 2026-11-21, correcting my model:
--   "a returned product (pull-out from branches to commis) does not add to the qty of stocks.
--    you cannot redeliver a returned product for reworking. therefore this is an inventory of
--    returned items for reworking which will be identified by PIC as new stocks qty available
--    once done"
--
-- Migration 111 added received_qty to the balance (beg + produced + received − …). That was
-- wrong in a way that matters: anything inside the balance is DISPATCHABLE, so a branch return
-- would have been re-deliverable — precisely what the owner rules out. A returned item is held
-- stock awaiting rework, tracked in its own inventory, and only the REWORK OUTPUT becomes new
-- stock qty (as produced_qty of the new product).
--
-- Both columns are empty in every environment that ran 111 (verified: SUM=0 on tenant 207), so
-- dropping them restates nothing. The guard below refuses to drop if any value was ever written,
-- so a deployment that somehow used 111 fails loudly instead of silently losing figures.
--
-- The holding inventory itself lives in migration 113 (dl_commissary_returned_items).
--
-- @mysql57-compat: MODIFY ... GENERATED ... STORED recomputes the column; plain ALTER; no JSON,
-- no CTE, no window functions.

SET @recv_written := (
    SELECT COALESCE(SUM(ABS(received_qty)) + SUM(ABS(rework_consumed_qty)), 0)
      FROM dl_commissary_product_ledger
);
SET @sql := IF(@recv_written > 0,
    'SELECT ''111 columns carry data - refusing to drop'' AS blocked',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Restore the original flow figure. It has never included the carried beginning, and it must not
-- include returns either: the dispatchable balance is beg + produced − dispatched − wastage.
SET @sql := IF(@recv_written > 0, 'SELECT 1',
    'ALTER TABLE dl_commissary_product_ledger
        MODIFY COLUMN remaining_qty INT
        GENERATED ALWAYS AS (produced_qty - dispatched_qty - wastage_qty) STORED');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(@recv_written > 0, 'SELECT 1',
    'ALTER TABLE dl_commissary_product_ledger
        MODIFY COLUMN calc_variance INT
        GENERATED ALWAYS AS (
            CASE WHEN end_source IN (''derived-from-movements'',''zero-forced'') THEN NULL
                 ELSE actual_end_qty - (beg_qty + produced_qty - dispatched_qty - wastage_qty)
            END) STORED');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(@recv_written > 0, 'SELECT 1',
    'ALTER TABLE dl_commissary_product_ledger
        DROP COLUMN rework_consumed_qty,
        DROP COLUMN received_qty');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
