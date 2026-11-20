-- 111 — Stock that arrives at a hub, and stock consumed by rework.
--
-- WHY THIS EXISTS. The commissary product ledger could only ever express two inflows and two
-- outflows: beg_qty + produced_qty in, dispatched_qty + wastage_qty out. Two real movements had
-- nowhere to land:
--
--   1. PULLOUT RETURNS. Branches pull items out and send them back to the hub — measured on
--      tenant 207 on 2026-11-21: 1,479 deliveries addressed to a commissary, 119 products,
--      55,785 units, of which only 13 were ever received anywhere. The hub's ledger had no
--      received column, so the returned stock either vanished from the books or was forced into
--      produced_qty (which reports a returned cake as newly produced).
--
--   2. REWORK CONSUMPTION. Owner direction 2026-11-21: the hub "receives as pullout items and
--      reworks them for another type of product". The output side is already honest — the new
--      product really does count as production of that product — but the INPUT side had no
--      column, so the original product's stock never went down. A rework would have created stock
--      out of nothing.
--
-- Both columns default to 0, so every historical row keeps exactly the numbers it had. The two
-- generated columns are recreated with the new terms added; because the new terms are 0 on
-- history, historical values are unchanged — this migration restates nothing.
--
-- @mysql57-compat: MySQL 5.7 allows multiple ADD COLUMN in one ALTER and requires a STORED
-- generated column to be recomputed on MODIFY, which is what happens below. No JSON, no CTE,
-- no window functions. Guarded so a re-run is a no-op.

SET @has_received := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE table_schema = DATABASE()
       AND table_name = 'dl_commissary_product_ledger'
       AND column_name = 'received_qty'
);
SET @sql := IF(@has_received = 0,
    'ALTER TABLE dl_commissary_product_ledger
        ADD COLUMN received_qty INT NOT NULL DEFAULT 0 AFTER wastage_qty',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_rework := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE table_schema = DATABASE()
       AND table_name = 'dl_commissary_product_ledger'
       AND column_name = 'rework_consumed_qty'
);
SET @sql := IF(@has_rework = 0,
    'ALTER TABLE dl_commissary_product_ledger
        ADD COLUMN rework_consumed_qty INT NOT NULL DEFAULT 0 AFTER received_qty',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- remaining_qty is a FLOW figure: it has never included the carried beginning, and this migration
-- does not change that. It now counts the two new flows.
SET @sql := IF(EXISTS(
        SELECT 1 FROM information_schema.COLUMNS
         WHERE table_schema = DATABASE()
           AND table_name = 'dl_commissary_product_ledger'
           AND column_name = 'remaining_qty'
           AND GENERATION_EXPRESSION LIKE '%received_qty%'),
    'SELECT 1',
    'ALTER TABLE dl_commissary_product_ledger
        MODIFY COLUMN remaining_qty INT
        GENERATED ALWAYS AS (produced_qty + received_qty - dispatched_qty - wastage_qty - rework_consumed_qty) STORED');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- calc_variance DOES include the carried beginning, because a variance is a comparison against the
-- whole book balance. The rework and receipt terms join the same arithmetic.
SET @sql := IF(EXISTS(
        SELECT 1 FROM information_schema.COLUMNS
         WHERE table_schema = DATABASE()
           AND table_name = 'dl_commissary_product_ledger'
           AND column_name = 'calc_variance'
           AND GENERATION_EXPRESSION LIKE '%received_qty%'),
    'SELECT 1',
    'ALTER TABLE dl_commissary_product_ledger
        MODIFY COLUMN calc_variance INT
        GENERATED ALWAYS AS (
            CASE WHEN end_source IN (''derived-from-movements'',''zero-forced'') THEN NULL
                 ELSE actual_end_qty - (beg_qty + produced_qty + received_qty
                                        - dispatched_qty - wastage_qty - rework_consumed_qty)
            END) STORED');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
