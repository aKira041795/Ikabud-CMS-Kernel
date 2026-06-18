-- Migration 020: Add onsite_attendance flag to employee_profiles
-- When enabled, employee can clock in without geo-fence verification (on-site project attendance)
ALTER TABLE `employee_profiles`
    ADD COLUMN `onsite_attendance` TINYINT(1) NOT NULL DEFAULT 0 AFTER `cash_advance_allowed`;
