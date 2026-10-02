-- 072: Persist both sides of receipt shift accountability.
--
-- Historical rows deliberately remain NULL. NULL means "recorded before receipt
-- shifts were captured" and must never be inferred or backfilled from ledger rows.
-- New receipts store the receiving shift while dl_deliveries.production_shift
-- stores the shift written on the paper DR.
-- MySQL 5.7 compatible. Both schema operations are guarded and rerun-safe.
-- The owned tables remain InnoDB.

SET @sql := IF(
    EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'dl_branch_receivings' AND column_name = 'received_shift'),
    'SELECT 1',
    'ALTER TABLE dl_branch_receivings ADD COLUMN received_shift ENUM(''AM'',''PM'') NULL DEFAULT NULL AFTER received_ledger_date'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'dl_cashier_withdrawals' AND column_name = 'received_shift'),
    'SELECT 1',
    'ALTER TABLE dl_cashier_withdrawals ADD COLUMN received_shift ENUM(''AM'',''PM'') NULL DEFAULT NULL AFTER received_ledger_date'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
