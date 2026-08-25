ALTER TABLE `harpp_users`
    ADD COLUMN `deleted_at` DATETIME NULL AFTER `is_active`,
    ADD KEY `idx_harpp_users_deleted` (`deleted_at`);
