-- 106: a commissary pull-out must name WHERE it was set from.
--
-- Owner 2026-11-18: "still need the branch or consignee the pullout is set from".
-- `target_branch_id` (migration 020) already records a branch counterparty - the cashier
-- modal uses it to point a branch pull-out at its commissary. A consignee is NOT a branch:
-- dl_consignees is its own table with its own id space, assigned to a commissary, so a
-- consignee pull-out had nowhere to record its source and the value would have been dropped
-- silently.
--
-- Exactly one counterparty is expected per entry (branch OR consignee). MySQL 5.7 cannot
-- enforce that with a CHECK constraint, so the pair is validated in apiSaveCashierWithdrawals
-- instead of being assumed here.
--
-- Idempotent: guarded by INFORMATION_SCHEMA, the same pattern as 101 and 104. Safe to re-run.
-- MySQL 5.7 compatible: no IF NOT EXISTS DDL, no CTEs, no window functions.

SET @dl_cw_target_consignee_exists := (
    SELECT COUNT(*)
      FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name = 'dl_cashier_withdrawals'
       AND column_name = 'target_consignee_id'
);
SET @dl_cw_target_consignee_ddl := IF(
    @dl_cw_target_consignee_exists = 0,
    'ALTER TABLE dl_cashier_withdrawals ADD COLUMN target_consignee_id INT UNSIGNED NULL DEFAULT NULL AFTER target_branch_id',
    'SELECT 1'
);
PREPARE dl_cw_target_consignee_st FROM @dl_cw_target_consignee_ddl;
EXECUTE dl_cw_target_consignee_st;
DEALLOCATE PREPARE dl_cw_target_consignee_st;
