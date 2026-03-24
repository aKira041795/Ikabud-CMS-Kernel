-- ============================================================
-- Migration 003: Normalize kernel users table to Kernel OS roles
-- ============================================================

UPDATE `users`
SET `role` = 'viewer'
WHERE `role` IN ('supervisor', 'cashier');

ALTER TABLE `users`
    MODIFY COLUMN `role` ENUM('admin','superadmin','manager','viewer') NOT NULL DEFAULT 'viewer';