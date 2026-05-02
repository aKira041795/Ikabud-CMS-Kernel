-- Migration 018: Ensure audit_logs has actor_module_user_id and actor_source columns.
-- Migration 017 used SET/@variable/PREPARE/EXECUTE which may silently fail on some
-- MySQL hosts (e.g. Bluehost shared hosting). This migration uses plain ALTER TABLE
-- statements instead. Duplicate-column errors (MySQL 1060) are handled as idempotent
-- by the migration runner, so this is safe to run even if the columns already exist.
ALTER TABLE `audit_logs` ADD COLUMN `actor_module_user_id` INT NULL DEFAULT NULL AFTER `actor_user_id`;
ALTER TABLE `audit_logs` ADD COLUMN `actor_source` VARCHAR(50) NULL DEFAULT NULL AFTER `actor_module_user_id`;
