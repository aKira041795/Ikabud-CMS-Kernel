-- Allow negative user_id for unlinked employees and fix unique index
ALTER TABLE `salary_computations`
    MODIFY COLUMN `user_id` INT NOT NULL DEFAULT 0,
    DROP INDEX `idx_tenant_user_period`,
    ADD UNIQUE INDEX `idx_tenant_profile_period` (`tenant_id`, `employee_profile_id`, `payroll_period_id`);
