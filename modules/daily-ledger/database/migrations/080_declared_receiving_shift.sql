-- 080: Record the sender's expected receiving shift for branch deliveries.
--
-- This is a declaration only. Historical rows deliberately remain NULL, and the
-- receiver's actual ledger shift remains dl_branch_receivings.received_shift.
-- MySQL 5.7 compatible (INSPECTION-ONLY; no local 5.7 server). Rerun-safe.

SET @sql := IF(
    EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'dl_deliveries' AND column_name = 'declared_receiving_shift'),
    'SELECT 1',
    'ALTER TABLE dl_deliveries ADD COLUMN declared_receiving_shift ENUM(''AM'',''PM'') NULL DEFAULT NULL AFTER production_shift'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
