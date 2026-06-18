-- Add name columns to employee_profiles and make user_id nullable
ALTER TABLE `employee_profiles`
    ADD COLUMN `first_name`  VARCHAR(100) DEFAULT NULL AFTER `tenant_id`,
    ADD COLUMN `last_name`   VARCHAR(100) DEFAULT NULL AFTER `first_name`,
    ADD COLUMN `middle_name` VARCHAR(100) DEFAULT NULL AFTER `last_name`,
    ADD COLUMN `suffix`      VARCHAR(10)  DEFAULT NULL AFTER `middle_name`,
    MODIFY COLUMN `user_id`  INT UNSIGNED NULL,
    DROP INDEX `idx_tenant_user`,
    ADD UNIQUE INDEX `idx_tenant_user` (`tenant_id`, `user_id`);
