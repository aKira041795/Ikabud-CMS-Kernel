-- ============================================================
-- Daily Ledger Module — Fix withdrawal ledger column
--
-- Prior to this fix, all production movements (both withdrawal
-- and output) incremented the `addtl` column. This migration
-- recalculates `addtl` and `withdraw` from the production
-- movements event log so that:
--   - output movements  → addtl  (stock delivered to branch)
--   - withdrawal movements → withdraw (stock pulled from branch)
--   - reverses undo the column of the referenced movement
-- ============================================================

-- Step 1: Compute net output delta per branch/product/date
-- (output movements minus reverses of output movements)
CREATE TEMPORARY TABLE tmp_output_totals AS
SELECT
    pm.destination_branch_id AS branch_id,
    pm.product_id,
    pm.ledger_date,
    SUM(CASE
        WHEN pm.movement_type = 'output' THEN pm.quantity
        WHEN pm.movement_type = 'reverse' THEN -(CAST(pm.quantity AS SIGNED))
        ELSE 0
    END) AS net_output
FROM dl_production_movements pm
WHERE pm.movement_type = 'output'
   OR (pm.movement_type = 'reverse' AND pm.reference_movement_id IN (
       SELECT id FROM dl_production_movements WHERE movement_type = 'output'
   ))
GROUP BY pm.destination_branch_id, pm.product_id, pm.ledger_date;

-- Step 2: Compute net withdrawal delta per branch/product/date
-- (withdrawal movements minus reverses of withdrawal movements)
CREATE TEMPORARY TABLE tmp_withdrawal_totals AS
SELECT
    pm.destination_branch_id AS branch_id,
    pm.product_id,
    pm.ledger_date,
    SUM(CASE
        WHEN pm.movement_type = 'withdrawal' THEN pm.quantity
        WHEN pm.movement_type = 'reverse' THEN -(CAST(pm.quantity AS SIGNED))
        ELSE 0
    END) AS net_withdrawal
FROM dl_production_movements pm
WHERE pm.movement_type = 'withdrawal'
   OR (pm.movement_type = 'reverse' AND pm.reference_movement_id IN (
       SELECT id FROM dl_production_movements WHERE movement_type = 'withdrawal'
   ))
GROUP BY pm.destination_branch_id, pm.product_id, pm.ledger_date;

-- Step 3: Update addtl to net output (was previously sum of both)
UPDATE dl_daily_ledger dl
INNER JOIN tmp_output_totals t
    ON t.branch_id = dl.branch_id
    AND t.product_id = dl.product_id
    AND t.ledger_date = dl.ledger_date
SET dl.addtl = GREATEST(0, t.net_output),
    dl.updated_at = CURRENT_TIMESTAMP;

-- Step 4: For rows with no output movements, reset addtl to 0
-- (these had withdrawal-only data incorrectly in addtl)
UPDATE dl_daily_ledger dl
LEFT JOIN tmp_output_totals t
    ON t.branch_id = dl.branch_id
    AND t.product_id = dl.product_id
    AND t.ledger_date = dl.ledger_date
INNER JOIN (
    SELECT DISTINCT destination_branch_id AS branch_id, product_id, ledger_date
    FROM dl_production_movements
) has_movements
    ON has_movements.branch_id = dl.branch_id
    AND has_movements.product_id = dl.product_id
    AND has_movements.ledger_date = dl.ledger_date
SET dl.addtl = 0,
    dl.updated_at = CURRENT_TIMESTAMP
WHERE t.branch_id IS NULL;

-- Step 5: Update withdraw to net withdrawal
UPDATE dl_daily_ledger dl
INNER JOIN tmp_withdrawal_totals t
    ON t.branch_id = dl.branch_id
    AND t.product_id = dl.product_id
    AND t.ledger_date = dl.ledger_date
SET dl.withdraw = GREATEST(0, t.net_withdrawal),
    dl.updated_at = CURRENT_TIMESTAMP;

-- Step 6: Recompute sales for all affected rows
UPDATE dl_daily_ledger dl
INNER JOIN (
    SELECT DISTINCT destination_branch_id AS branch_id, product_id, ledger_date
    FROM dl_production_movements
) affected
    ON affected.branch_id = dl.branch_id
    AND affected.product_id = dl.product_id
    AND affected.ledger_date = dl.ledger_date
SET dl.sales = GREATEST(0, dl.beg_bal + dl.addtl - dl.withdraw - dl.bal_end),
    dl.updated_at = CURRENT_TIMESTAMP;

DROP TEMPORARY TABLE IF EXISTS tmp_output_totals;
DROP TEMPORARY TABLE IF EXISTS tmp_withdrawal_totals;
