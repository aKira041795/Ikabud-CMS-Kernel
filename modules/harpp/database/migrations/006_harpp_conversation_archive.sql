ALTER TABLE `harpp_conversations`
    ADD COLUMN `archived_at` DATETIME NULL AFTER `updated_at`,
    ADD KEY `idx_harpp_conversations_archived` (`archived_at`);
