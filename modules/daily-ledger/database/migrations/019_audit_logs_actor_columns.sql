-- Daily Ledger migration 019
-- Ensure audit_logs has actor_module_user_id and actor_source columns.
-- These columns are required by the activity log query in handleAdminActivity.
-- Uses plain ALTER TABLE (idempotent via MySQL error 1060 handling in runner).
ALTER TABLE `audit_logs` ADD COLUMN `actor_module_user_id` INT NULL DEFAULT NULL AFTER `actor_user_id`;
ALTER TABLE `audit_logs` ADD COLUMN `actor_source` VARCHAR(50) NULL DEFAULT NULL AFTER `actor_module_user_id`;
