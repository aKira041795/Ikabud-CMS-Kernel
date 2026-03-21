-- ============================================================
-- Migration 009: Add 'superadmin' role to kernel users table
-- ============================================================
-- The superadmin role is a tenant-scoped settings administrator.
-- They can only access module feature configuration — not daily
-- operations, platform infrastructure, or user management.
-- ============================================================

ALTER TABLE `users`
    MODIFY COLUMN `role` ENUM('admin','superadmin','supervisor','cashier','manager','viewer') NOT NULL DEFAULT 'cashier';
