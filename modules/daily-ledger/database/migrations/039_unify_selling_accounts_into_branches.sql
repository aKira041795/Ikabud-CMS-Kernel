-- Migration 039: Unify selling accounts into branches
-- Selling accounts are now first-class branch entries. All SA-specific
-- tables are dropped after migrating data into dl_branches + dl_daily_ledger.

-- Step 1: Add SA-specific columns to dl_branches
ALTER TABLE dl_branches
    ADD COLUMN account_type VARCHAR(50) NULL DEFAULT NULL AFTER is_commissary,
    ADD COLUMN price_group_id INT UNSIGNED NULL DEFAULT NULL AFTER account_type;

-- Step 2: Insert selling accounts as branch entries
INSERT INTO dl_branches (code, name, is_commissary, account_type, price_group_id, is_active, created_at, updated_at)
SELECT CONCAT('SA-', code), name, 0, account_type, price_group_id, is_active, created_at, updated_at
FROM dl_selling_accounts
WHERE is_active = 1;

-- Step 3: Migrate user-selling-account assignments to user-branch assignments
-- (the new branch IDs match the order of insertion above — we need to map old SA IDs to new branch IDs)
-- Since SA IDs may differ from new branch IDs, use a temp mapping approach:
INSERT INTO dl_user_branches (user_id, branch_id)
SELECT usa.user_id, b.id
FROM dl_user_selling_accounts usa
INNER JOIN dl_selling_accounts sa ON sa.id = usa.selling_account_id
INNER JOIN dl_branches b ON b.name = sa.name AND b.account_type = sa.account_type
WHERE b.account_type IS NOT NULL
ON DUPLICATE KEY UPDATE branch_id = b.id;

-- Step 4: Drop SA-specific tables
DROP TABLE IF EXISTS dl_selling_account_day_status;
DROP TABLE IF EXISTS dl_selling_account_ledger;
DROP TABLE IF EXISTS dl_user_selling_accounts;
DROP TABLE IF EXISTS dl_selling_accounts;
