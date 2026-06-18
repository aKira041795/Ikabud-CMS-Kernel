ALTER TABLE `cash_advances`
    ADD COLUMN `employee_profile_id` INT UNSIGNED NULL AFTER `user_id`,
    ADD COLUMN `approved_at` DATETIME NULL AFTER `approved_by`,
    ADD INDEX `idx_tenant_profile` (`tenant_id`, `employee_profile_id`);
