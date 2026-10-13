-- 092: production movements carry a SIGNED quantity
--
-- The Daily Production Sheet lets the production account take back an ADDTL
-- amount that was recorded too high, the same way the branch cells on that
-- sheet already do: the correction is APPENDED as its own movement (the trail
-- reads original entry → correction) rather than overwriting the original.
-- A reduction is carried as a negative quantity — the reduction both the
-- operator and the log read — so the column must be SIGNED.
--
-- This mirrors 056 for dl_cashier_withdrawals.quantity, which was signed for
-- exactly this reason (an operator prefixes the amount with a minus), and it
-- keeps the reductions netting naturally: any SUM over output movements
-- (reconcile, sheet activity) reads 10 + (-4) = 6 with no special casing.
-- dl_delivery_items.quantity, the carrier for the sheet's branch-cell
-- corrections, is already a signed INT.
--
-- Existing rows are all non-negative (every writer validates quantity > 0),
-- so unsigned -> signed converts in place. No index or foreign key covers this
-- column, so the type change has no cross-table constraint to respect.
--
-- Additive in effect: type widens to cover negatives, no data change on re-run.
--
-- @mysql57-compat: plain ALTER TABLE MODIFY, InnoDB table unchanged,
-- utf8mb4_unicode_ci respected, values bounded upstream to ±999,999,999.

ALTER TABLE dl_production_movements
    MODIFY quantity INT NOT NULL;
