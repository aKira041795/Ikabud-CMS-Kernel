-- Migration 023: Add 13th month pay toggle to employee profiles
ALTER TABLE `employee_profiles`
ADD COLUMN `thirteenth_month_enabled` TINYINT(1) DEFAULT 1 COMMENT 'Auto-compute 13th month pay accrual per period' AFTER `cash_advance_allowed`;
