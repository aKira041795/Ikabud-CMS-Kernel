-- HARPP performance read-model (2026-08-27, module 2.2.1):
--   * ADR-4: harpp_notifications.in_app_visible becomes a real write-time column so
--     the in-app list/unread scans drop the per-row correlated EXISTS + JSON_EXTRACT
--     (backfill encodes the exact prior visibility rule before reads switch over).
--   * ADR-6: (sender_type,id) index for the harness message poll
--     (WHERE sender_type='user' AND id > :cursor ORDER BY id), plus a
--     harpp_outbox.dispatch_priority column so delivery ordering no longer needs
--     the payload_json LIKE probe.
-- MySQL 5.7 compatible, additive only.

ALTER TABLE `harpp_notifications`
    ADD COLUMN `in_app_visible` TINYINT(1) NOT NULL DEFAULT 1 AFTER `status`,
    ADD KEY `idx_harpp_notifications_user_visibility` (`user_id`,`in_app_visible`,`read_at`,`created_at`);

-- Backfill: hide harness/system message notifications and payload-stamped
-- in_app_visible=false rows so reads can rely on the column alone.
UPDATE `harpp_notifications` n
LEFT JOIN `harpp_messages` m ON m.id = n.message_id
SET n.in_app_visible = 0
WHERE (n.notification_type = 'message' AND n.message_id IS NOT NULL AND m.sender_type IN ('harness','system'))
   OR (JSON_UNQUOTE(JSON_EXTRACT(n.payload, '$.in_app_visible')) = 'false');

ALTER TABLE `harpp_messages`
    ADD KEY `idx_harpp_messages_sender_id` (`sender_type`,`id`);

ALTER TABLE `harpp_outbox`
    ADD COLUMN `dispatch_priority` TINYINT(1) NOT NULL DEFAULT 0 AFTER `payload_json`,
    ADD KEY `idx_harpp_outbox_dispatch` (`status`,`dispatch_priority`,`available_at`,`id`);
