-- Migration 039: Remove selling_account from destination_type / origin_type ENUMs
-- Selling accounts feature removed in commit cc5f07e.
-- Safe: first migrates any legacy rows to 'branch', then alters the ENUMs.

-- 1. dl_deliveries: migrate any remaining selling_account destination rows to branch
UPDATE dl_deliveries
   SET destination_type = 'branch'
 WHERE destination_type = 'selling_account';

-- 2. dl_deliveries: drop 'selling_account' from destination_type ENUM
ALTER TABLE dl_deliveries
MODIFY COLUMN destination_type ENUM('branch','own_account','reseller','customer','event','wastage','internal_use','adjustment') NOT NULL;

-- 3. dl_branch_receivings: migrate any remaining selling_account_return origin rows
UPDATE dl_branch_receivings
   SET origin_type = 'branch'
 WHERE origin_type = 'selling_account_return';

-- 4. dl_branch_receivings: drop 'selling_account_return' from origin_type ENUM
ALTER TABLE dl_branch_receivings
MODIFY COLUMN origin_type ENUM('commissary','branch','supplier','local_production','manual_adjustment') NOT NULL;
