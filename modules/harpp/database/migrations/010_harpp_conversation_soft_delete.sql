-- Manual delete for archived conversations (soft delete: records retained, hidden from lists).
-- Consistent with HARPP's immutable-retention philosophy and the existing soft-delete
-- pattern used for users (004_harpp_user_soft_delete.sql). Deleted conversations keep
-- their messages, decisions, ADRs, transitions, and audit history intact.
ALTER TABLE `harpp_conversations`
    ADD COLUMN `deleted_at` DATETIME NULL AFTER `archived_at`,
    ADD KEY `idx_harpp_conversations_deleted` (`deleted_at`);
