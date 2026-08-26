-- HARPP 2.0 Phase 0/1 foundation (MySQL 5.7 compatible, additive only).
-- Existing tenant data is placed in a tenant-local Legacy workspace. New users
-- are not implicitly added after this one-time migration.

CREATE TABLE IF NOT EXISTS `harpp_workspaces` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `workspace_key` VARCHAR(191) NOT NULL,
    `name` VARCHAR(255) NOT NULL,
    `status` ENUM('active','archived') NOT NULL DEFAULT 'active',
    `created_by` INT UNSIGNED NULL,
    `version` INT UNSIGNED NOT NULL DEFAULT 1,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_harpp_workspaces_key` (`workspace_key`),
    KEY `idx_harpp_workspaces_status` (`status`,`id`),
    CONSTRAINT `fk_harpp_workspaces_creator` FOREIGN KEY (`created_by`) REFERENCES `harpp_users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `harpp_workspace_memberships` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `workspace_id` INT UNSIGNED NOT NULL,
    `user_id` INT UNSIGNED NOT NULL,
    `roles` JSON NOT NULL,
    `status` ENUM('active','suspended','revoked') NOT NULL DEFAULT 'active',
    `created_by` INT UNSIGNED NULL,
    `version` INT UNSIGNED NOT NULL DEFAULT 1,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_harpp_workspace_member` (`workspace_id`,`user_id`),
    KEY `idx_harpp_workspace_membership_user` (`user_id`,`status`,`workspace_id`),
    CONSTRAINT `fk_harpp_workspace_membership_workspace` FOREIGN KEY (`workspace_id`) REFERENCES `harpp_workspaces` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_harpp_workspace_membership_user` FOREIGN KEY (`user_id`) REFERENCES `harpp_users` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_harpp_workspace_membership_creator` FOREIGN KEY (`created_by`) REFERENCES `harpp_users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `harpp_projects` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `workspace_id` INT UNSIGNED NOT NULL,
    `project_key` VARCHAR(191) NOT NULL,
    `name` VARCHAR(255) NOT NULL,
    `status` ENUM('active','archived') NOT NULL DEFAULT 'active',
    `created_by` INT UNSIGNED NULL,
    `version` INT UNSIGNED NOT NULL DEFAULT 1,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_harpp_project_key` (`workspace_id`,`project_key`),
    KEY `idx_harpp_projects_workspace_status` (`workspace_id`,`status`,`id`),
    CONSTRAINT `fk_harpp_projects_workspace` FOREIGN KEY (`workspace_id`) REFERENCES `harpp_workspaces` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_harpp_projects_creator` FOREIGN KEY (`created_by`) REFERENCES `harpp_users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `harpp_project_memberships` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `project_id` INT UNSIGNED NOT NULL,
    `user_id` INT UNSIGNED NOT NULL,
    `roles` JSON NOT NULL,
    `status` ENUM('active','suspended','revoked') NOT NULL DEFAULT 'active',
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_harpp_project_member` (`project_id`,`user_id`),
    KEY `idx_harpp_project_membership_user` (`user_id`,`status`,`project_id`),
    CONSTRAINT `fk_harpp_project_membership_project` FOREIGN KEY (`project_id`) REFERENCES `harpp_projects` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_harpp_project_membership_user` FOREIGN KEY (`user_id`) REFERENCES `harpp_users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Every alteration is independently guarded so interruption at any completed
-- statement boundary can be resumed. Scope columns are nullable until the
-- Legacy seed/backfill and validation below have completed.
SET @harpp_007_sql := IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='harpp_conversations' AND column_name='workspace_id')=0,'ALTER TABLE `harpp_conversations` ADD COLUMN `workspace_id` INT UNSIGNED NULL AFTER `id`','DO 1');
PREPARE harpp_007_stmt FROM @harpp_007_sql; EXECUTE harpp_007_stmt; DEALLOCATE PREPARE harpp_007_stmt;
SET @harpp_007_sql := IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='harpp_conversations' AND column_name='project_id')=0,'ALTER TABLE `harpp_conversations` ADD COLUMN `project_id` INT UNSIGNED NULL AFTER `workspace_id`','DO 1');
PREPARE harpp_007_stmt FROM @harpp_007_sql; EXECUTE harpp_007_stmt; DEALLOCATE PREPARE harpp_007_stmt;
SET @harpp_007_sql := IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='harpp_conversations' AND column_name='visibility')=0,'ALTER TABLE `harpp_conversations` ADD COLUMN `visibility` ENUM(''workspace'',''participants'',''private'') NULL AFTER `project_id`','DO 1');
PREPARE harpp_007_stmt FROM @harpp_007_sql; EXECUTE harpp_007_stmt; DEALLOCATE PREPARE harpp_007_stmt;
SET @harpp_007_sql := IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='harpp_conversations' AND column_name='version')=0,'ALTER TABLE `harpp_conversations` ADD COLUMN `version` INT UNSIGNED NULL AFTER `status`','DO 1');
PREPARE harpp_007_stmt FROM @harpp_007_sql; EXECUTE harpp_007_stmt; DEALLOCATE PREPARE harpp_007_stmt;

SET @harpp_007_sql := IF(
    (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='harpp_messages' AND column_name='aggregate_sequence')=0,
    'ALTER TABLE `harpp_messages` ADD COLUMN `aggregate_sequence` BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER `conversation_id`',
    'DO 1'
);
PREPARE harpp_007_stmt FROM @harpp_007_sql;
EXECUTE harpp_007_stmt;
DEALLOCATE PREPARE harpp_007_stmt;
SET @harpp_007_sql := IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='harpp_notifications' AND column_name='dedup_key')=0,'ALTER TABLE `harpp_notifications` ADD COLUMN `dedup_key` CHAR(64) NULL AFTER `payload`','DO 1');
PREPARE harpp_007_stmt FROM @harpp_007_sql; EXECUTE harpp_007_stmt; DEALLOCATE PREPARE harpp_007_stmt;

SET @harpp_007_sql := IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='harpp_decisions' AND column_name='workspace_id')=0,'ALTER TABLE `harpp_decisions` ADD COLUMN `workspace_id` INT UNSIGNED NULL AFTER `id`','DO 1');
PREPARE harpp_007_stmt FROM @harpp_007_sql; EXECUTE harpp_007_stmt; DEALLOCATE PREPARE harpp_007_stmt;
SET @harpp_007_sql := IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='harpp_decisions' AND column_name='project_id')=0,'ALTER TABLE `harpp_decisions` ADD COLUMN `project_id` INT UNSIGNED NULL AFTER `workspace_id`','DO 1');
PREPARE harpp_007_stmt FROM @harpp_007_sql; EXECUTE harpp_007_stmt; DEALLOCATE PREPARE harpp_007_stmt;
SET @harpp_007_sql := IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='harpp_decisions' AND column_name='visibility')=0,'ALTER TABLE `harpp_decisions` ADD COLUMN `visibility` ENUM(''workspace'',''participants'',''private'') NULL AFTER `project_id`','DO 1');
PREPARE harpp_007_stmt FROM @harpp_007_sql; EXECUTE harpp_007_stmt; DEALLOCATE PREPARE harpp_007_stmt;
SET @harpp_007_sql := IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='harpp_decisions' AND column_name='version')=0,'ALTER TABLE `harpp_decisions` ADD COLUMN `version` INT UNSIGNED NULL AFTER `lifecycle_state`','DO 1');
PREPARE harpp_007_stmt FROM @harpp_007_sql; EXECUTE harpp_007_stmt; DEALLOCATE PREPARE harpp_007_stmt;
SET @harpp_007_sql := IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='harpp_decisions' AND column_name='archived_at')=0,'ALTER TABLE `harpp_decisions` ADD COLUMN `archived_at` DATETIME(6) NULL AFTER `closed_at`','DO 1');
PREPARE harpp_007_stmt FROM @harpp_007_sql; EXECUTE harpp_007_stmt; DEALLOCATE PREPARE harpp_007_stmt;
SET @harpp_007_sql := IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='harpp_decisions' AND column_name='legal_hold_at')=0,'ALTER TABLE `harpp_decisions` ADD COLUMN `legal_hold_at` DATETIME(6) NULL AFTER `archived_at`','DO 1');
PREPARE harpp_007_stmt FROM @harpp_007_sql; EXECUTE harpp_007_stmt; DEALLOCATE PREPARE harpp_007_stmt;
SET @harpp_007_sql := IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='harpp_decisions' AND column_name='legal_hold_reason')=0,'ALTER TABLE `harpp_decisions` ADD COLUMN `legal_hold_reason` VARCHAR(1000) NULL AFTER `legal_hold_at`','DO 1');
PREPARE harpp_007_stmt FROM @harpp_007_sql; EXECUTE harpp_007_stmt; DEALLOCATE PREPARE harpp_007_stmt;

CREATE TABLE IF NOT EXISTS `harpp_conversation_participants` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `conversation_id` INT UNSIGNED NOT NULL,
    `user_id` INT UNSIGNED NOT NULL,
    `grant_kind` ENUM('participant','private_grant') NOT NULL DEFAULT 'participant',
    `created_by` INT UNSIGNED NULL,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `revoked_at` DATETIME(6) NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_harpp_conversation_participant` (`conversation_id`,`user_id`,`grant_kind`),
    KEY `idx_harpp_participant_user` (`user_id`,`revoked_at`,`conversation_id`),
    CONSTRAINT `fk_harpp_participant_conversation` FOREIGN KEY (`conversation_id`) REFERENCES `harpp_conversations` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_harpp_participant_user` FOREIGN KEY (`user_id`) REFERENCES `harpp_users` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_harpp_participant_creator` FOREIGN KEY (`created_by`) REFERENCES `harpp_users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `harpp_message_receipts` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `message_id` INT UNSIGNED NOT NULL,
    `user_id` INT UNSIGNED NOT NULL,
    `read_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_harpp_message_receipt` (`message_id`,`user_id`),
    KEY `idx_harpp_receipt_user_read` (`user_id`,`read_at`,`message_id`),
    CONSTRAINT `fk_harpp_receipt_message` FOREIGN KEY (`message_id`) REFERENCES `harpp_messages` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_harpp_receipt_user` FOREIGN KEY (`user_id`) REFERENCES `harpp_users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `harpp_approval_policies` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `workspace_id` INT UNSIGNED NOT NULL,
    `policy_key` VARCHAR(191) NOT NULL,
    `name` VARCHAR(255) NOT NULL,
    `eligible_roles` JSON NOT NULL,
    `eligible_user_ids` JSON NULL,
    `quorum` SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    `distinct_actors` TINYINT(1) NOT NULL DEFAULT 1,
    `exclude_creator` TINYINT(1) NOT NULL DEFAULT 0,
    `exclude_executor` TINYINT(1) NOT NULL DEFAULT 0,
    `allow_veto` TINYINT(1) NOT NULL DEFAULT 0,
    `allow_owner_override` TINYINT(1) NOT NULL DEFAULT 0,
    `allow_delegation` TINYINT(1) NOT NULL DEFAULT 0,
    `expires_after_seconds` INT UNSIGNED NULL,
    `status` ENUM('active','retired') NOT NULL DEFAULT 'active',
    `version` INT UNSIGNED NOT NULL DEFAULT 1,
    `created_by` INT UNSIGNED NULL,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_harpp_policy_key` (`workspace_id`,`policy_key`),
    CONSTRAINT `fk_harpp_policy_workspace` FOREIGN KEY (`workspace_id`) REFERENCES `harpp_workspaces` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_harpp_policy_creator` FOREIGN KEY (`created_by`) REFERENCES `harpp_users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `harpp_decision_policy_snapshots` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `decision_id` INT UNSIGNED NOT NULL,
    `source_policy_id` INT UNSIGNED NULL,
    `policy_version` INT UNSIGNED NOT NULL,
    `policy_json` JSON NOT NULL,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_harpp_decision_policy_snapshot` (`decision_id`),
    CONSTRAINT `fk_harpp_snapshot_decision` FOREIGN KEY (`decision_id`) REFERENCES `harpp_decisions` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_harpp_snapshot_policy` FOREIGN KEY (`source_policy_id`) REFERENCES `harpp_approval_policies` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `harpp_decision_approvals` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `decision_id` INT UNSIGNED NOT NULL,
    `user_id` INT UNSIGNED NOT NULL,
    `vote` ENUM('approve','veto') NOT NULL,
    `reason` TEXT NOT NULL,
    `delegated_by` INT UNSIGNED NULL,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_harpp_decision_approval_actor` (`decision_id`,`user_id`),
    KEY `idx_harpp_approval_decision_vote` (`decision_id`,`vote`,`id`),
    CONSTRAINT `fk_harpp_approval_decision` FOREIGN KEY (`decision_id`) REFERENCES `harpp_decisions` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_harpp_approval_user` FOREIGN KEY (`user_id`) REFERENCES `harpp_users` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_harpp_approval_delegate` FOREIGN KEY (`delegated_by`) REFERENCES `harpp_users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `harpp_decision_assignments` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `decision_id` INT UNSIGNED NOT NULL,
    `user_id` INT UNSIGNED NOT NULL,
    `assignment_type` ENUM('assignee','watcher') NOT NULL,
    `created_by` INT UNSIGNED NULL,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `revoked_at` DATETIME(6) NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_harpp_decision_assignment` (`decision_id`,`user_id`,`assignment_type`),
    KEY `idx_harpp_assignment_user` (`user_id`,`assignment_type`,`revoked_at`),
    CONSTRAINT `fk_harpp_assignment_decision` FOREIGN KEY (`decision_id`) REFERENCES `harpp_decisions` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_harpp_assignment_user` FOREIGN KEY (`user_id`) REFERENCES `harpp_users` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_harpp_assignment_creator` FOREIGN KEY (`created_by`) REFERENCES `harpp_users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `harpp_approval_delegations` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `workspace_id` INT UNSIGNED NOT NULL,
    `from_user_id` INT UNSIGNED NOT NULL,
    `to_user_id` INT UNSIGNED NOT NULL,
    `created_by` INT UNSIGNED NOT NULL,
    `reason` VARCHAR(1000) NOT NULL,
    `valid_from` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `expires_at` DATETIME(6) NOT NULL,
    `revoked_at` DATETIME(6) NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_harpp_active_delegation` (`workspace_id`,`from_user_id`,`to_user_id`,`expires_at`),
    KEY `idx_harpp_delegation_target` (`workspace_id`,`to_user_id`,`revoked_at`,`expires_at`),
    CONSTRAINT `fk_harpp_delegation_workspace` FOREIGN KEY (`workspace_id`) REFERENCES `harpp_workspaces` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_harpp_delegation_from` FOREIGN KEY (`from_user_id`) REFERENCES `harpp_users` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_harpp_delegation_to` FOREIGN KEY (`to_user_id`) REFERENCES `harpp_users` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_harpp_delegation_creator` FOREIGN KEY (`created_by`) REFERENCES `harpp_users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `harpp_notification_preferences` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL,
    `workspace_id` INT UNSIGNED NULL,
    `event_type` VARCHAR(100) NOT NULL,
    `channel` ENUM('push','in_app') NOT NULL DEFAULT 'in_app',
    `enabled` TINYINT(1) NOT NULL DEFAULT 1,
    `version` INT UNSIGNED NOT NULL DEFAULT 1,
    `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_harpp_notification_preference` (`user_id`,`workspace_id`,`event_type`,`channel`),
    CONSTRAINT `fk_harpp_preference_user` FOREIGN KEY (`user_id`) REFERENCES `harpp_users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_harpp_preference_workspace` FOREIGN KEY (`workspace_id`) REFERENCES `harpp_workspaces` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `harpp_audit_events` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `event_key` VARCHAR(191) NOT NULL,
    `aggregate_type` VARCHAR(100) NOT NULL,
    `aggregate_id` VARCHAR(191) NOT NULL,
    `aggregate_sequence` BIGINT UNSIGNED NOT NULL,
    `actor_user_id` INT UNSIGNED NULL,
    `actor_type` ENUM('user','harness','system') NOT NULL,
    `action` VARCHAR(191) NOT NULL,
    `before_json` JSON NULL,
    `after_json` JSON NULL,
    `reason` TEXT NULL,
    `occurred_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_harpp_audit_event_key` (`event_key`),
    UNIQUE KEY `uq_harpp_audit_aggregate_sequence` (`aggregate_type`,`aggregate_id`,`aggregate_sequence`),
    KEY `idx_harpp_audit_actor_time` (`actor_user_id`,`occurred_at`,`id`),
    CONSTRAINT `fk_harpp_audit_actor` FOREIGN KEY (`actor_user_id`) REFERENCES `harpp_users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `harpp_outbox` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `event_key` VARCHAR(191) NOT NULL,
    `event_name` VARCHAR(191) NOT NULL,
    `aggregate_type` VARCHAR(100) NOT NULL,
    `aggregate_id` VARCHAR(191) NOT NULL,
    `payload_json` JSON NOT NULL,
    `status` ENUM('pending','processing','delivered','dead') NOT NULL DEFAULT 'pending',
    `attempts` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `available_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `claimed_at` DATETIME(6) NULL,
    `claim_token` CHAR(36) NULL,
    `last_error` VARCHAR(2000) NULL,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `delivered_at` DATETIME(6) NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_harpp_outbox_event_key` (`event_key`),
    KEY `idx_harpp_outbox_delivery` (`status`,`available_at`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `harpp_idempotency_keys` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `scope_key` VARCHAR(191) NOT NULL,
    `actor_key` VARCHAR(191) NOT NULL,
    `operation_key` VARCHAR(100) NOT NULL,
    `idempotency_key_hash` CHAR(64) NOT NULL,
    `request_hash` CHAR(64) NOT NULL,
    `status` ENUM('processing','completed','failed') NOT NULL DEFAULT 'processing',
    `response_code` SMALLINT UNSIGNED NULL,
    `response_json` JSON NULL,
    `response_hash` CHAR(64) NULL,
    `aggregate_type` VARCHAR(100) NULL,
    `aggregate_id` VARCHAR(191) NULL,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `completed_at` DATETIME(6) NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_harpp_idempotency_scope` (`scope_key`,`actor_key`,`operation_key`,`idempotency_key_hash`),
    KEY `idx_harpp_idempotency_created` (`created_at`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `harpp_purge_requests` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `resource_type` VARCHAR(100) NOT NULL,
    `resource_id` VARCHAR(191) NOT NULL,
    `state` ENUM('requested','approved','rejected','executed','cancelled') NOT NULL DEFAULT 'requested',
    `requested_by` INT UNSIGNED NOT NULL,
    `approved_by` INT UNSIGNED NULL,
    `reason` TEXT NOT NULL,
    `not_before` DATETIME(6) NOT NULL,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `approved_at` DATETIME(6) NULL,
    `executed_at` DATETIME(6) NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_harpp_open_purge` (`resource_type`,`resource_id`,`state`),
    KEY `idx_harpp_purge_state_time` (`state`,`not_before`,`id`),
    CONSTRAINT `fk_harpp_purge_requester` FOREIGN KEY (`requested_by`) REFERENCES `harpp_users` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_harpp_purge_approver` FOREIGN KEY (`approved_by`) REFERENCES `harpp_users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `harpp_workspaces` (`workspace_key`,`name`,`status`,`created_by`,`version`,`created_at`,`updated_at`)
SELECT 'legacy','Legacy','active',MIN(`id`),1,CURRENT_TIMESTAMP(6),CURRENT_TIMESTAMP(6)
FROM `harpp_users`
HAVING COUNT(*) > 0
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`);

INSERT INTO `harpp_workspace_memberships` (`workspace_id`,`user_id`,`roles`,`status`,`created_by`,`version`,`created_at`,`updated_at`)
SELECT w.`id`,u.`id`,
       CASE WHEN u.`role` IN ('owner','admin')
            THEN JSON_ARRAY('manager','operator','reviewer','viewer')
            ELSE JSON_ARRAY('operator','reviewer','viewer') END,
       'active',w.`created_by`,1,CURRENT_TIMESTAMP(6),CURRENT_TIMESTAMP(6)
FROM `harpp_workspaces` w
JOIN `harpp_users` u ON u.`is_active`=1 AND u.`deleted_at` IS NULL
WHERE w.`workspace_key`='legacy'
  AND NOT EXISTS (
      SELECT 1 FROM `harpp_settings` s
      WHERE s.`setting_key`='harpp_migration_007_progress' AND s.`setting_value`='complete'
  )
ON DUPLICATE KEY UPDATE `roles`=VALUES(`roles`),`status`='active';

UPDATE `harpp_conversations` c
JOIN `harpp_workspaces` w ON w.`workspace_key`='legacy'
SET c.`workspace_id`=COALESCE(c.`workspace_id`,w.`id`),c.`visibility`=COALESCE(c.`visibility`,'workspace'),c.`version`=COALESCE(c.`version`,1)
WHERE c.`workspace_id` IS NULL OR c.`visibility` IS NULL OR c.`version` IS NULL;

UPDATE `harpp_decisions` d
JOIN `harpp_conversations` c ON c.`id`=d.`conversation_id`
SET d.`workspace_id`=COALESCE(d.`workspace_id`,c.`workspace_id`),d.`project_id`=COALESCE(d.`project_id`,c.`project_id`),d.`visibility`=COALESCE(d.`visibility`,c.`visibility`,'workspace'),d.`version`=COALESCE(d.`version`,1)
WHERE d.`workspace_id` IS NULL OR d.`visibility` IS NULL OR d.`version` IS NULL;

UPDATE `harpp_messages` m
JOIN (
    SELECT `id`, @harpp_seq := IF(@harpp_conv=`conversation_id`, @harpp_seq+1, 1) AS seq,
           @harpp_conv := `conversation_id` AS conv
    FROM `harpp_messages`, (SELECT @harpp_conv := 0, @harpp_seq := 0) vars
    ORDER BY `conversation_id`,`created_at`,`id`
) ordered ON ordered.`id`=m.`id`
SET m.`aggregate_sequence`=ordered.`seq`
WHERE m.`aggregate_sequence`=0;

-- Validate before adding lookup constraints. A failed validation leaves all
-- additive data in place and a rerun resumes from the missing step.
SET @harpp_007_sql := IF((SELECT COUNT(*) FROM `harpp_conversations` WHERE `workspace_id` IS NULL OR `visibility` IS NULL OR `version` IS NULL)=0,'DO 1','SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT=''HARPP 007 conversation backfill incomplete''');
PREPARE harpp_007_stmt FROM @harpp_007_sql; EXECUTE harpp_007_stmt; DEALLOCATE PREPARE harpp_007_stmt;
SET @harpp_007_sql := IF((SELECT COUNT(*) FROM `harpp_decisions` WHERE `workspace_id` IS NULL OR `visibility` IS NULL OR `version` IS NULL)=0,'DO 1','SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT=''HARPP 007 decision backfill incomplete''');
PREPARE harpp_007_stmt FROM @harpp_007_sql; EXECUTE harpp_007_stmt; DEALLOCATE PREPARE harpp_007_stmt;
SET @harpp_007_sql := IF((SELECT COUNT(*) FROM `harpp_messages` WHERE `aggregate_sequence`=0)=0,'DO 1','SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT=''HARPP 007 message backfill incomplete''');
PREPARE harpp_007_stmt FROM @harpp_007_sql; EXECUTE harpp_007_stmt; DEALLOCATE PREPARE harpp_007_stmt;

SET @harpp_007_sql := IF((SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='harpp_conversations' AND index_name='idx_harpp_conversation_scope')=0,'ALTER TABLE `harpp_conversations` ADD KEY `idx_harpp_conversation_scope` (`workspace_id`,`project_id`,`visibility`,`updated_at`,`id`)','DO 1');
PREPARE harpp_007_stmt FROM @harpp_007_sql; EXECUTE harpp_007_stmt; DEALLOCATE PREPARE harpp_007_stmt;
SET @harpp_007_sql := IF((SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='harpp_decisions' AND index_name='idx_harpp_decision_scope')=0,'ALTER TABLE `harpp_decisions` ADD KEY `idx_harpp_decision_scope` (`workspace_id`,`project_id`,`visibility`,`created_at`,`id`)','DO 1');
PREPARE harpp_007_stmt FROM @harpp_007_sql; EXECUTE harpp_007_stmt; DEALLOCATE PREPARE harpp_007_stmt;
SET @harpp_007_sql := IF((SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='harpp_decisions' AND index_name='idx_harpp_decision_archive')=0,'ALTER TABLE `harpp_decisions` ADD KEY `idx_harpp_decision_archive` (`archived_at`,`lifecycle_state`,`id`)','DO 1');
PREPARE harpp_007_stmt FROM @harpp_007_sql; EXECUTE harpp_007_stmt; DEALLOCATE PREPARE harpp_007_stmt;
SET @harpp_007_sql := IF((SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='harpp_messages' AND index_name='uq_harpp_message_sequence')=0,'ALTER TABLE `harpp_messages` ADD UNIQUE KEY `uq_harpp_message_sequence` (`conversation_id`,`aggregate_sequence`)','DO 1');
PREPARE harpp_007_stmt FROM @harpp_007_sql; EXECUTE harpp_007_stmt; DEALLOCATE PREPARE harpp_007_stmt;
SET @harpp_007_sql := IF((SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='harpp_notifications' AND index_name='uq_harpp_notification_dedup')=0,'ALTER TABLE `harpp_notifications` ADD UNIQUE KEY `uq_harpp_notification_dedup` (`dedup_key`)','DO 1');
PREPARE harpp_007_stmt FROM @harpp_007_sql; EXECUTE harpp_007_stmt; DEALLOCATE PREPARE harpp_007_stmt;

SET @harpp_007_sql := IF((SELECT COUNT(*) FROM information_schema.table_constraints WHERE constraint_schema=DATABASE() AND table_name='harpp_conversations' AND constraint_name='fk_harpp_conversation_workspace')=0,'ALTER TABLE `harpp_conversations` ADD CONSTRAINT `fk_harpp_conversation_workspace` FOREIGN KEY (`workspace_id`) REFERENCES `harpp_workspaces` (`id`) ON DELETE RESTRICT','DO 1');
PREPARE harpp_007_stmt FROM @harpp_007_sql; EXECUTE harpp_007_stmt; DEALLOCATE PREPARE harpp_007_stmt;
SET @harpp_007_sql := IF((SELECT COUNT(*) FROM information_schema.table_constraints WHERE constraint_schema=DATABASE() AND table_name='harpp_conversations' AND constraint_name='fk_harpp_conversation_project')=0,'ALTER TABLE `harpp_conversations` ADD CONSTRAINT `fk_harpp_conversation_project` FOREIGN KEY (`project_id`) REFERENCES `harpp_projects` (`id`) ON DELETE RESTRICT','DO 1');
PREPARE harpp_007_stmt FROM @harpp_007_sql; EXECUTE harpp_007_stmt; DEALLOCATE PREPARE harpp_007_stmt;
SET @harpp_007_sql := IF((SELECT COUNT(*) FROM information_schema.table_constraints WHERE constraint_schema=DATABASE() AND table_name='harpp_decisions' AND constraint_name='fk_harpp_decision_workspace')=0,'ALTER TABLE `harpp_decisions` ADD CONSTRAINT `fk_harpp_decision_workspace` FOREIGN KEY (`workspace_id`) REFERENCES `harpp_workspaces` (`id`) ON DELETE RESTRICT','DO 1');
PREPARE harpp_007_stmt FROM @harpp_007_sql; EXECUTE harpp_007_stmt; DEALLOCATE PREPARE harpp_007_stmt;
SET @harpp_007_sql := IF((SELECT COUNT(*) FROM information_schema.table_constraints WHERE constraint_schema=DATABASE() AND table_name='harpp_decisions' AND constraint_name='fk_harpp_decision_project')=0,'ALTER TABLE `harpp_decisions` ADD CONSTRAINT `fk_harpp_decision_project` FOREIGN KEY (`project_id`) REFERENCES `harpp_projects` (`id`) ON DELETE RESTRICT','DO 1');
PREPARE harpp_007_stmt FROM @harpp_007_sql; EXECUTE harpp_007_stmt; DEALLOCATE PREPARE harpp_007_stmt;

INSERT INTO `harpp_settings` (`setting_key`,`setting_value`,`updated_at`) VALUES
('harpp_lifecycle_v2','1',NOW()),
('harpp_immutable_retention','1',NOW()),
('harpp_outbox','1',NOW()),
('harpp_strict_validation','1',NOW()),
('harpp_workspace_enforcement','0',NOW()),
('harpp_participant_visibility','0',NOW()),
('harpp_per_user_receipts','0',NOW()),
('harpp_approval_policies','0',NOW()),
('harpp_notification_fanout','0',NOW()),
('harpp_migration_007_progress','complete',NOW())
ON DUPLICATE KEY UPDATE `setting_value`=`setting_value`;
