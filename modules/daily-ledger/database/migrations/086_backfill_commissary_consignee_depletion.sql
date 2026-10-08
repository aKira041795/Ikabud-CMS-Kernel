-- 086: Backfill the commissary projection with consignee departures.
--
-- OWNER, 2026-10-08: "consignee movement owned by commissary but dispatch done by
-- cashier" / "commissary stocks are depleted when dispatched to consignee".
--
-- THE DEFECT THIS REPAIRS
-- `dl_applyPostedDeliveryCommissaryLedger()` used to accept only
-- origin_type='commissary' AND destination_type='branch'. A consignee dispatch is
-- performed by the cashier (origin_type='branch') and credited only to the
-- consignee, so the supplying commissary's projection
-- (`dl_commissary_product_ledger.dispatched_qty`) was NEVER debited. The goods
-- left the branch ledger and appeared on the consignee's while the commissary
-- still counted them on hand.
--
-- The runtime path is fixed in handlers.php; this migration corrects rows already
-- persisted for past dates so the projection stops disagreeing with the Daily
-- Sheet (which now reads the single derivation
-- dl_commissaryDepartedQtyByProduct()).
--
-- WHY A MARKER TABLE, NOT A PLAIN UPDATE
-- `dispatched_qty` is an incremental projection (delivery effects, plus direct
-- same-location releases that no delivery represents). It cannot be re-SET from
-- deliveries without clobbering those direct movements, and it cannot be
-- incremented unconditionally without double-counting on a rerun. The marker
-- buckets the correction and the multi-table UPDATE below applies the projection
-- increment and marks the bucket applied in ONE atomic statement. Re-running
-- queues nothing (INSERT IGNORE on the unique bucket) and updates nothing
-- (applied_at is no longer NULL), so it is idempotent and crash-safe.
--
-- WHAT IT CHANGES
-- For every POSTED consignee departure whose item has no applied
-- dl_delivery_ledger_effects row:
--   * creates the projection row if absent (beg/produced/dispatched/wastage = 0),
--   * adds the consignee quantity to dispatched_qty exactly once,
--   * records the durable per-item delivery effect so a later void reverses it.
-- Any day with a consignee dispatch therefore shows a LOWER commissary balance.
-- That is the correction, not a regression, and it is reported, never silent.
--
-- BEG / ADDTL / WASTAGE are untouched: only dispatched_qty moves. The identity
-- BEG + ADDTL - TOTAL - WASTAGE = ACTUAL BAL is preserved by the generated
-- columns, and calc_variance is corrected through the same input.
--
-- @mysql57-compat INSPECTION-ONLY (no local 5.7 server): CREATE TABLE IF NOT
-- EXISTS, INSERT IGNORE, COALESCE/NULLIF, one multi-table UPDATE with a unique
-- bucket (1:1 match), no CTEs, no window functions, no JSON_TABLE. InnoDB,
-- utf8mb4_unicode_ci.

CREATE TABLE IF NOT EXISTS dl_commissary_depletion_backfill (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    commissary_branch_id INT UNSIGNED NOT NULL,
    product_id INT UNSIGNED NOT NULL,
    ledger_date DATE NOT NULL,
    shift_key VARCHAR(2) NOT NULL DEFAULT '',
    quantity INT NOT NULL,
    queued_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    applied_at DATETIME NULL DEFAULT NULL,
    UNIQUE KEY uq_dl_cdb_bucket (commissary_branch_id, product_id, ledger_date, shift_key),
    KEY idx_dl_cdb_pending (applied_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Queue each (commissary, product, date, shift) consignee departure not yet
-- represented in the projection. `e.id IS NULL` keeps live-applied departures
-- out; `b.id IS NULL` keeps an already-queued bucket out (rerun-safe).
INSERT IGNORE INTO dl_commissary_depletion_backfill
    (commissary_branch_id, product_id, ledger_date, shift_key, quantity)
SELECT c.assigned_commissary_id, di.product_id, d.delivery_date,
       COALESCE(d.production_shift, ''), SUM(di.quantity)
  FROM dl_deliveries d
  INNER JOIN dl_delivery_items di ON di.delivery_id = d.id
  INNER JOIN dl_consignees c ON c.id = d.consignee_id
  LEFT JOIN dl_delivery_ledger_effects e ON e.delivery_item_id = di.id
  LEFT JOIN dl_commissary_depletion_backfill b
         ON b.commissary_branch_id = c.assigned_commissary_id
        AND b.product_id = di.product_id
        AND b.ledger_date = d.delivery_date
        AND b.shift_key = COALESCE(d.production_shift, '')
 WHERE d.destination_type = 'consignee'
   AND d.status = 'posted'
   AND e.id IS NULL
   AND b.id IS NULL
 GROUP BY c.assigned_commissary_id, di.product_id, d.delivery_date, COALESCE(d.production_shift, '');

-- Ensure the projection row exists so the atomic increment cannot skip a
-- departure on a date that had no production row.
INSERT IGNORE INTO dl_commissary_product_ledger
    (commissary_branch_id, product_id, ledger_date, shift, beg_qty, produced_qty, dispatched_qty, wastage_qty)
SELECT b.commissary_branch_id, b.product_id, b.ledger_date, NULLIF(b.shift_key, ''), 0, 0, 0, 0
  FROM dl_commissary_depletion_backfill b
 WHERE b.applied_at IS NULL;

-- Apply the bucket atomically: projection increment AND marker application in a
-- single statement. The bucket key is unique, so the join is 1:1.
UPDATE dl_commissary_product_ledger cpl
  INNER JOIN dl_commissary_depletion_backfill b
     ON b.commissary_branch_id = cpl.commissary_branch_id
    AND b.product_id = cpl.product_id
    AND b.ledger_date = cpl.ledger_date
    AND BINARY cpl.shift_key = BINARY b.shift_key
   SET cpl.dispatched_qty = cpl.dispatched_qty + b.quantity,
       b.applied_at = NOW()
 WHERE b.applied_at IS NULL;

-- Record the durable per-item effect so a later void reverses the backfilled
-- departure from the projection. INSERT IGNORE is idempotent on delivery_item_id.
INSERT IGNORE INTO dl_delivery_ledger_effects
    (delivery_id, delivery_item_id, commissary_branch_id, product_id, ledger_date,
     quantity, effect_status, applied_at, before_dispatched_qty, after_dispatched_qty,
     before_remaining_qty, after_remaining_qty)
SELECT d.id, di.id, c.assigned_commissary_id, di.product_id, d.delivery_date,
       di.quantity, 'applied', NOW(),
       cpl.dispatched_qty - di.quantity, cpl.dispatched_qty,
       cpl.remaining_qty + di.quantity, cpl.remaining_qty
  FROM dl_deliveries d
  INNER JOIN dl_delivery_items di ON di.delivery_id = d.id
  INNER JOIN dl_consignees c ON c.id = d.consignee_id
  INNER JOIN dl_commissary_product_ledger cpl
          ON cpl.commissary_branch_id = c.assigned_commissary_id
         AND cpl.product_id = di.product_id
         AND cpl.ledger_date = d.delivery_date
         AND BINARY cpl.shift_key = BINARY COALESCE(d.production_shift, '')
  LEFT JOIN dl_delivery_ledger_effects e ON e.delivery_item_id = di.id
 WHERE d.destination_type = 'consignee'
   AND d.status = 'posted'
   AND e.id IS NULL;
