ALTER TABLE `harpp_decisions`
    ADD COLUMN `context` TEXT NULL AFTER `body`,
    ADD COLUMN `requested_decision` TEXT NULL AFTER `context`,
    ADD COLUMN `priority` ENUM('low','normal','high','critical') NOT NULL DEFAULT 'normal' AFTER `requested_decision`,
    ADD COLUMN `source` VARCHAR(100) NOT NULL DEFAULT 'harness' AFTER `priority`,
    ADD COLUMN `workbench_state` VARCHAR(100) NULL AFTER `source`,
    ADD COLUMN `created_by` INT UNSIGNED NULL AFTER `workbench_state`,
    ADD KEY `idx_harpp_decisions_priority` (`priority`),
    ADD KEY `idx_harpp_decisions_created_by` (`created_by`),
    ADD CONSTRAINT `fk_harpp_decisions_created_by` FOREIGN KEY (`created_by`) REFERENCES `harpp_users` (`id`) ON DELETE SET NULL;

CREATE TABLE IF NOT EXISTS `harpp_decision_transitions` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `decision_id` INT UNSIGNED NOT NULL,
    `from_state` VARCHAR(32) NULL,
    `to_state` VARCHAR(32) NOT NULL,
    `actor_user_id` INT UNSIGNED NULL,
    `actor_type` ENUM('user','harness','system') NOT NULL DEFAULT 'user',
    `rationale` TEXT NULL,
    `workbench_state` VARCHAR(100) NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_harpp_transition_decision_created` (`decision_id`, `created_at`, `id`),
    CONSTRAINT `fk_harpp_transition_decision` FOREIGN KEY (`decision_id`) REFERENCES `harpp_decisions` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_harpp_transition_actor` FOREIGN KEY (`actor_user_id`) REFERENCES `harpp_users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `harpp_notifications`
    ADD COLUMN `conversation_id` INT UNSIGNED NULL AFTER `decision_id`,
    ADD COLUMN `message_id` INT UNSIGNED NULL AFTER `conversation_id`,
    ADD COLUMN `notification_type` ENUM('decision','message','system') NOT NULL DEFAULT 'system' AFTER `message_id`,
    ADD COLUMN `read_at` DATETIME NULL AFTER `sent_at`,
    ADD KEY `idx_harpp_notifications_user_read` (`user_id`, `read_at`, `created_at`),
    ADD CONSTRAINT `fk_harpp_notifications_conversation` FOREIGN KEY (`conversation_id`) REFERENCES `harpp_conversations` (`id`) ON DELETE CASCADE,
    ADD CONSTRAINT `fk_harpp_notifications_message` FOREIGN KEY (`message_id`) REFERENCES `harpp_messages` (`id`) ON DELETE CASCADE;

ALTER TABLE `harpp_push_subscriptions`
    ADD COLUMN `endpoint_hash` CHAR(64) NULL AFTER `endpoint`,
    ADD COLUMN `expires_at` DATETIME NULL AFTER `keys`,
    ADD COLUMN `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`,
    ADD UNIQUE KEY `uq_harpp_push_endpoint_hash` (`endpoint_hash`);

UPDATE `harpp_push_subscriptions` SET `endpoint_hash` = SHA2(`endpoint`, 256) WHERE `endpoint_hash` IS NULL;

ALTER TABLE `harpp_adrs`
    ADD COLUMN `context` TEXT NULL AFTER `title`,
    ADD COLUMN `decision` TEXT NULL AFTER `body`,
    ADD COLUMN `rationale` TEXT NULL AFTER `decision`,
    ADD COLUMN `decided_by` INT UNSIGNED NULL AFTER `decision_ref`,
    ADD FULLTEXT KEY `ft_harpp_adrs_memory` (`title`, `context`, `body`, `decision`, `rationale`),
    ADD KEY `idx_harpp_adrs_decided_by` (`decided_by`),
    ADD CONSTRAINT `fk_harpp_adrs_decided_by` FOREIGN KEY (`decided_by`) REFERENCES `harpp_users` (`id`) ON DELETE SET NULL;
