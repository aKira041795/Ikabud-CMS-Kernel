-- ============================================================
-- Migration 004: Remove legacy Daily Ledger roles from kernel users
-- ============================================================

UPDATE `users`
SET `role` = 'viewer'
WHERE `role` IN ('supervisor', 'cashier');

ALTER TABLE `users`
    MODIFY COLUMN `role` ENUM('admin','superadmin','manager','viewer') NOT NULL DEFAULT 'viewer';