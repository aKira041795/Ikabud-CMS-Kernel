-- 108: packaging_applied — how many boxes the cake sales have already deducted for this row.
--
-- WHY A STORED COLUMN, AND WHY IT IS THE WHOLE SAFETY OF THE FEATURE:
--
-- The cashier sheet is saved per cell, on blur, and again on every correction. The obvious
-- implementation - "withdraw the boxes for the sales I see now" - therefore multiplies boxes with
-- every save cycle: 12 cakes sold becomes 12, then 24, then 36 boxes gone for one day's baking.
--
-- So the deduction is DERIVED and SET, never incremented. For each box row we store the amount the
-- cake sales currently justify (`packaging_applied`), compute what they justify now (`usage`), and
-- write only the difference (`delta = usage - packaging_applied`). A re-save produces delta 0 and
-- changes nothing; a corrected sales figure corrects the box by exactly the correction; and a
-- deletion of sales gives the boxes back.
--
-- Same shape as `dl_commissary_product_ledger`'s produced/dispatched columns: the row records what
-- the derivation concluded, so the derivation can be re-run at any time and be idempotent.
--
-- DEFAULT 0, NOT NULL: a row that existed before this migration has deducted nothing, and 0 is the
-- truth for it rather than "unknown". Signed negative values are never stored here.
--
-- Idempotent: guarded by INFORMATION_SCHEMA, the same pattern as 101, 104, 106 and 107.
-- MySQL 5.7 compatible: no IF NOT EXISTS DDL, no CHECK constraints, no CTEs, no window functions.

SET @dl_daily_ledger_packaging_applied_exists := (
    SELECT COUNT(*)
      FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name = 'dl_daily_ledger'
       AND column_name = 'packaging_applied'
);
SET @dl_daily_ledger_packaging_applied_ddl := IF(
    @dl_daily_ledger_packaging_applied_exists = 0,
    'ALTER TABLE dl_daily_ledger ADD COLUMN packaging_applied INT NOT NULL DEFAULT 0 AFTER sales',
    'SELECT 1'
);
PREPARE dl_daily_ledger_packaging_applied_st FROM @dl_daily_ledger_packaging_applied_ddl;
EXECUTE dl_daily_ledger_packaging_applied_st;
DEALLOCATE PREPARE dl_daily_ledger_packaging_applied_st;
