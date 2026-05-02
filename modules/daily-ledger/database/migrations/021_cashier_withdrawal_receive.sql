-- Daily Ledger migration 021
-- Add receive tracking to dl_cashier_withdrawals.
-- Plain ALTER TABLE (idempotent via MySQL error 1060/1061 handling in runner).

ALTER TABLE `dl_cashier_withdrawals` ADD COLUMN `received_at` DATETIME NULL DEFAULT NULL;
ALTER TABLE `dl_cashier_withdrawals` ADD COLUMN `received_by` INT UNSIGNED NULL DEFAULT NULL;
ALTER TABLE `dl_cashier_withdrawals` ADD COLUMN `received_ledger_date` DATE NULL DEFAULT NULL;
ALTER TABLE `dl_cashier_withdrawals` ADD INDEX `idx_dl_cw_target_received` (`target_branch_id`, `received_at`);
